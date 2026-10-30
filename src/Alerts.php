<?php
declare(strict_types=1);

/**
 * Powiadomienia administracyjne: stan bramki → kanały wybrane dla danego rodzaju alarmu.
 * Alarm zgłaszany po upływie czasu karencji (żeby chwilowe zakłócenia nie budziły), najwyżej raz na godzinę,
 * przypomnienie co 6 h, a po ustąpieniu problemu – jedna wiadomość „ustąpiło”.
 */
final class Alerts
{
    public const TYPES = ['service', 'modem', 'signal', 'queue', 'failures', 'worker', 'balance', 'limit'];
    /** Karencja w sekundach: jak długo stan musi trwać, zanim zostanie zgłoszony. */
    private const GRACE = ['service' => 60, 'modem' => 180, 'signal' => 900, 'queue' => 0, 'failures' => 0, 'worker' => 120, 'balance' => 0, 'limit' => 0];
    private const COOLDOWN = 3600;
    private const REMIND = 21600;
    private const LOW_SIGNAL = 15;
    private const CHECK_EVERY = 20;
    private static int $checkedAt = 0;

    /**
     * Aktywne problemy: rodzaj => parametry opisu albo null, gdy wszystko w porządku.
     * @param list<string> $only ograniczenie do wybranych rodzajów
     * @return array<string, ?array<string, string|int>>
     */
    public static function conditions(array $only = self::TYPES): array
    {
        $c = [];
        $service = in_array('service', $only, true) || in_array('modem', $only, true) || in_array('signal', $only, true) ? Status::service() : 'active';
        $c['service'] = $service !== 'active' ? ['state' => $service] : null;
        $m = Status::modem();
        $available = Status::modemAvailable($m);
        $c['modem'] = $service === 'active' && !$available
            ? ['modem' => $m['modem'] ?? '-', 'ago' => $m && $m['gammu_updated_at'] ? fmt_ago($m['gammu_updated_at']) : t('alerts.never')] : null;
        $c['signal'] = $available && (int) $m['signal_pct'] >= 0 && (int) $m['signal_pct'] < self::LOW_SIGNAL ? ['modem' => $m['modem'], 'pct' => (int) $m['signal_pct']] : null;
        $stuck = (int) Db::val("SELECT COUNT(*) FROM messages WHERE direction = 'out' AND status = 'queued' AND retries = 0
            AND COALESCE(scheduled_at, created_at) < ?", [now_db(-900)]);
        $c['queue'] = $stuck > 0 ? ['n' => $stuck] : null;
        $last = Db::col("SELECT status FROM messages WHERE direction = 'out' AND source <> 'notify'
            AND status IN ('sent','delivered','failed','undelivered') AND updated_at > ? ORDER BY updated_at DESC, id DESC LIMIT 3", [now_db(-6 * 3600)]);
        $c['failures'] = count($last) === 3 && array_unique($last) === ['failed'] ? ['n' => 3] : null;
        $seen = ts(Settings::get('worker_seen_at'));
        $c['worker'] = $seen === null || time() - $seen > 120 ? ['ago' => $seen === null ? t('alerts.never') : fmt_ago(Settings::get('worker_seen_at'))] : null;
        $threshold = (float) Settings::get('balance_threshold');
        $bal = in_array('balance', $only, true) && $threshold > 0 ? Balance::latest() : null;
        $c['balance'] = $bal !== null && $bal['amount'] !== null && $bal['amount'] < $threshold
            ? ['amount' => number_format($bal['amount'], 2, lang() === 'pl' ? ',' : '.', ''), 'threshold' => number_format($threshold, 2, lang() === 'pl' ? ',' : '.', '')] : null;
        $lim = in_array('limit', $only, true) ? Stats::limit() : null;
        $c['limit'] = $lim !== null && $lim['pct'] >= 80 ? ['pct' => $lim['pct'], 'used' => $lim['used'], 'limit' => $lim['limit']] : null;
        return array_intersect_key($c, array_flip($only));
    }

    /** @return array<string, list<int>> rodzaj alarmu => identyfikatory kanałów */
    public static function routes(): array
    {
        $r = [];
        foreach (Settings::json('alerts_routes') as $type => $ids) {
            if (in_array($type, self::TYPES, true) && is_array($ids)) {
                $r[$type] = array_values(array_map('intval', $ids));
            }
        }
        return $r;
    }

    /** @param array<string, mixed> $in rodzaj => lista identyfikatorów kanałów (z formularza) */
    public static function saveRoutes(array $in): void
    {
        $valid = array_map('intval', array_column(Channels::all(), 'id'));
        $out = [];
        foreach (self::TYPES as $type) {
            $ids = array_values(array_intersect(array_map('intval', is_array($in[$type] ?? null) ? $in[$type] : []), $valid));
            if ($ids !== []) {
                $out[$type] = $ids;
            }
        }
        Settings::set('alerts_routes', $out);
    }

    /** Stan alarmów: rodzaj => {since: od kiedy trwa problem, notified: czy zgłoszono, last_sent: ostatnie zgłoszenie problemu}. */
    public static function state(): array
    {
        return Settings::json('alerts_state');
    }

    /**
     * Jeden przebieg: ocena stanu, zakolejkowanie powiadomień. Zwraca liczbę zakolejkowanych dostarczeń.
     * @param list<string> $only rodzaje do oceny (watchdog sprawdza tylko „worker”)
     */
    public static function process(array $only = self::TYPES, ?int $now = null, bool $force = false): int
    {
        $now ??= time();
        if (!$force && $now - self::$checkedAt < self::CHECK_EVERY) {
            return 0;
        }
        self::$checkedAt = $now;
        $routes = self::routes();
        $state = self::state();
        $queued = 0;
        foreach (self::conditions($only) as $type => $params) {
            $s = ($state[$type] ?? []) + ['since' => null, 'notified' => false, 'last_sent' => null];
            if ($params !== null) {
                $s['since'] ??= $now;
                if ($now - $s['since'] >= self::GRACE[$type]) {
                    $wait = $s['notified'] ? self::REMIND : self::COOLDOWN; // pierwsze zgłoszenie po nawrocie – po przerwie godzinnej
                    if ($s['last_sent'] === null || $now - $s['last_sent'] >= $wait) {
                        $queued += self::notify($type, 'problem', $params, $routes[$type] ?? []);
                        $s['notified'] = true;
                        $s['last_sent'] = $now;
                    }
                }
            } else {
                if ($s['notified']) {
                    $queued += self::notify($type, 'resolved', [], $routes[$type] ?? []);
                }
                $s['since'] = null;
                $s['notified'] = false;
            }
            // Wpis zostaje, dopóki trwa problem albo przerwa po ostatnim zgłoszeniu
            if ($s['since'] !== null || ($s['last_sent'] !== null && $now - $s['last_sent'] < self::COOLDOWN)) {
                $state[$type] = $s;
            } else {
                unset($state[$type]);
            }
        }
        Settings::set('alerts_state', $state);
        return $queued;
    }

    /** @param list<int> $channelIds */
    private static function notify(string $type, string $kind, array $params, array $channelIds): int
    {
        if ($channelIds === []) {
            return 0;
        }
        $host = gethostname() ?: 'smsgui';
        $title = t("alerts.$type.title");
        $text = $kind === 'problem' ? t("alerts.$type.text", $params) : t("alerts.$type.resolved");
        $subject = t($kind === 'problem' ? 'alerts.subject_problem' : 'alerts.subject_resolved', ['title' => $title, 'host' => $host]);
        $n = 0;
        foreach (Db::all('SELECT id FROM channels WHERE enabled = 1 AND id IN (' . Db::in($channelIds) . ')', $channelIds) as $ch) {
            Deliveries::enqueue((int) $ch['id'], 'alert', [
                'subject' => $subject, 'text' => $text,
                'data' => ['type' => 'alert', 'alert' => $type, 'state' => $kind, 'title' => $title, 'host' => $host, 'time' => now_db()],
            ]);
            $n++;
        }
        app_log('info', "alarm $type ($kind): zakolejkowano $n powiadomień");
        return $n;
    }

    /** Strażnik uruchamiany z timera systemd: gdy proces w tle nie działa, sam ocenia alarm „worker” i wysyła zaległe powiadomienia. */
    public static function watchdog(): int
    {
        $queued = self::process(['worker'], null, true);
        Deliveries::process();
        return $queued;
    }
}
