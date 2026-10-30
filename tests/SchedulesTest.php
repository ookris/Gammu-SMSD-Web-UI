<?php
function sched_setup(): int
{
    reset_db();
    return Db::insert('groups', ['name' => 'Serwis', 'created_at' => now_db()]);
}

function sched_in(int $g, array $over = []): array
{
    return $over + ['name' => 'Przypomnienie', 'groups' => [$g], 'numbers' => '', 'text' => 'Cześć {imie}', 'frequency' => 'daily', 'time' => '08:00'];
}

test('Cykliczne: następne uruchomienie – codziennie, dni tygodnia, dzień miesiąca', function () {
    $run = static fn (array $s, string $after) => date('Y-m-d H:i', Schedules::nextRun($s + ['weekdays' => '', 'month_day' => 1, 'at_time' => '08:00:00'], strtotime($after)));
    assert_same('2026-11-02 08:00', $run(['frequency' => 'daily'], '2026-11-01 12:00:00'), 'jutro, bo dziś 8:00 już było');
    assert_same('2026-11-01 08:00', $run(['frequency' => 'daily'], '2026-11-01 07:59:59'), 'dziś');
    assert_same('2026-11-02 08:00', $run(['frequency' => 'daily'], '2026-11-01 08:00:00'), 'ściśle po chwili');
    // poniedziałek i czwartek; 2026-11-01 to niedziela
    assert_same('2026-11-02 08:00', $run(['frequency' => 'weekly', 'weekdays' => '1,4'], '2026-11-01 12:00:00'));
    assert_same('2026-11-05 08:00', $run(['frequency' => 'weekly', 'weekdays' => '1,4'], '2026-11-02 08:00:00'));
    assert_same('2026-11-09 08:00', $run(['frequency' => 'weekly', 'weekdays' => '1,4'], '2026-11-05 08:00:00'));
    assert_same('2026-12-15 08:00', $run(['frequency' => 'monthly', 'month_day' => 15], '2026-11-15 08:00:00'));
    assert_same('2027-02-28 08:00', $run(['frequency' => 'monthly', 'month_day' => 31], '2027-02-01 00:00:00'), 'luty: ostatni dzień');
    assert_same('2026-11-30 08:00', $run(['frequency' => 'monthly', 'month_day' => 31], '2026-11-01 00:00:00'), 'listopad: 30');
});

test('Cykliczne: zmiana czasu – godzina nieistniejąca wiosną i powtarzająca się jesienią', function () {
    $s = ['frequency' => 'daily', 'weekdays' => '', 'month_day' => 1, 'at_time' => '02:30:00'];
    $spring = Schedules::nextRun($s, strtotime('2026-03-28 12:00:00'));
    assert_same('2026-03-29', date('Y-m-d', $spring), 'dzień zmiany czasu nadal ma uruchomienie');
    assert_same('2026-03-30 02:30', date('Y-m-d H:i', Schedules::nextRun($s, $spring)), 'następnego dnia o zwykłej porze');
    $autumn = Schedules::nextRun($s, strtotime('2026-10-24 12:00:00'));
    assert_same('2026-10-25', date('Y-m-d', $autumn), 'dzień zmiany czasu jesienią');
    assert_same('2026-10-26 02:30', date('Y-m-d H:i', Schedules::nextRun($s, $autumn)), 'jesienią jedno uruchomienie na dobę, nie dwa');
});

test('Cykliczne: zapis i walidacja', function () {
    $g = sched_setup();
    [$id, $err] = Schedules::save(null, sched_in($g, ['frequency' => 'weekly', 'weekdays' => [3, 1, 3], 'time' => '09:15']));
    assert_same(null, $err);
    $s = Schedules::find($id);
    assert_same(['weekly', '1,3', '09:15:00', 1], [$s['frequency'], $s['weekdays'], $s['at_time'], (int) $s['enabled']]);
    assert_true($s['next_run_at'] > now_db(), 'następne uruchomienie w przyszłości');
    assert_true(str_contains(Schedules::describe($s), '09:15'), 'opis');
    foreach ([
        ['groups' => [], 'numbers' => ''], ['numbers' => 'abc', 'groups' => []], ['text' => ' '], ['time' => '25:00'],
        ['frequency' => 'weekly', 'weekdays' => []], ['frequency' => 'monthly', 'month_day' => 32], ['text' => str_repeat('x', 1700)],
    ] as $bad) {
        assert_true(Schedules::save(null, sched_in($g, $bad))[1] !== null, 'odrzucone: ' . json_encode($bad));
    }
    assert_same([], json_decode(Schedules::find(Schedules::save(null, sched_in($g, ['groups' => [999], 'numbers' => '601234567']))[0])['groups_json'], true), 'nieistniejąca grupa pominięta');
});

test('Cykliczne: uruchomienie wysyła do grupy i przesuwa termin; wyłączone i przyszłe nie ruszają', function () {
    $g = sched_setup();
    $c1 = Db::insert('contacts', ['name' => 'Jan Kowalski', 'phone' => '48601111111', 'created_at' => now_db(), 'updated_at' => now_db()]);
    $c2 = Db::insert('contacts', ['name' => 'Ewa Nowak', 'phone' => '48602222222', 'created_at' => now_db(), 'updated_at' => now_db()]);
    foreach ([$c1, $c2] as $c) {
        Db::insert('contact_group_members', ['contact_id' => $c, 'group_id' => $g]);
    }
    [$id] = Schedules::save(null, sched_in($g, ['numbers' => '603333333']));
    assert_same(0, Schedules::process(), 'jeszcze nie pora');
    Db::update('schedules', ['next_run_at' => now_db(-60)], 'id = ?', [$id]);
    Schedules::setEnabled($id, false);
    Db::update('schedules', ['next_run_at' => now_db(-60)], 'id = ?', [$id]);
    assert_same(0, Schedules::process(), 'wyłączony');
    Db::update('schedules', ['enabled' => 1, 'next_run_at' => now_db(-60)], 'id = ?', [$id]);
    assert_same(1, Schedules::process());
    $out = Db::all("SELECT phone, body FROM messages WHERE direction = 'out' ORDER BY phone");
    assert_same([['48601111111', 'Cześć Jan'], ['48602222222', 'Cześć Ewa'], ['48603333333', 'Cześć ']], array_map('array_values', $out));
    $s = Schedules::find($id);
    assert_same([1, null], [(int) $s['runs'], $s['last_error']]);
    assert_true($s['next_run_at'] > now_db(), 'termin przesunięty');
    assert_same(0, Schedules::process(), 'nie wysyła drugi raz');
});

test('Cykliczne: zaległość powyżej 12 h jest pomijana, krótsza nadrabiana raz', function () {
    $g = sched_setup();
    Db::insert('contacts', ['name' => 'Jan', 'phone' => '48601111111', 'created_at' => now_db(), 'updated_at' => now_db()]);
    [$id] = Schedules::save(null, sched_in($g, ['numbers' => '601111111', 'groups' => []]));
    Db::update('schedules', ['next_run_at' => now_db(-Schedules::MAX_LATE - 60)], 'id = ?', [$id]);
    assert_same(0, Schedules::process());
    assert_same(0, (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out'"));
    $s = Schedules::find($id);
    assert_true($s['last_error'] !== null && $s['next_run_at'] > now_db(), 'pominięte z komunikatem i przesunięte');
    Db::update('schedules', ['next_run_at' => now_db(-3600)], 'id = ?', [$id]);
    assert_same(1, Schedules::process());
    assert_same(1, (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out'"), 'jedna wiadomość mimo wielu minionych terminów');
});
