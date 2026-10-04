<?php
// Nowa wiadomość – do jednego lub wielu odbiorców (rozdz. 2.3)

// Wyszukiwarka kontaktów (htmx): zaznaczone zostają na liście
if (isset($_GET['contacts_q'])) {
    echo view('compose-contacts', ['contacts' => compose_contacts(input('contacts_q'), input_ids('contacts')), 'selected' => input_ids('contacts')]);
    exit;
}

$in = Compose::defaults();
$attempted = false;
$confirm = false;

if (is_post()) {
    $in = Compose::input();
    $plan = Compose::plan($in);
    if (input('preview') !== '') {
        echo view('compose-side', ['in' => $in, 'plan' => $plan, 'oob' => true]);
        exit;
    }
    if (isset($_POST['save_template'])) {
        if (trim($in['text']) !== '') {
            Templates::save(null, Ui::snippet($in['text'], 40), $in['text']);
            flash('ok', 'Zapisano szablon.', 'Zmienisz jego nazwę na ekranie „Szablony”.');
        }
    } elseif ($plan['errors'] === [] && (!$plan['multi'] || input('confirm') === '1')) {
        // Jednorazowy token formularza: ponowne wysłanie tego samego formularza (odświeżenie, podwójne kliknięcie) nie dubluje SMS
        $token = input('send_token');
        if ($token !== '' && isset($_SESSION['compose_sent'][$token])) {
            flash('info', 'Ta wiadomość została już wysłana.', 'Nic nie dodano ponownie do kolejki.');
            redirect($_SESSION['compose_sent'][$token]);
        }
        [$batch, $first] = Compose::send($in, $plan);
        if ($batch !== null) {
            flash('ok', 'Wysyłka zapisana w kolejce.', $plan['count'] . ' odbiorców × ' . $plan['parts'] . ' SMS.');
            $target = url('batch', ['id' => $batch]);
        } else {
            flash('ok', 'Wiadomość dodana do kolejki.', $plan['delayed'] ? 'Wysyłka: ' . Compose::startLabel($plan) . '.' : 'Gammu wyśle ją w ciągu kilku sekund.');
            $target = url('threads', ['phone' => $plan['recipients']['list'][0]['phone']]);
        }
        if ($token !== '') {
            $_SESSION['compose_sent'] = array_slice(($_SESSION['compose_sent'] ?? []) + [$token => $target], -20, null, true);
        }
        redirect($target);
    } else {
        $attempted = $plan['errors'] !== [];
        $confirm = $plan['errors'] === [] && $plan['multi'];
    }
} else {
    // Otwarcie z gotowym wyborem: z kontaktu, grupy, zaznaczonych kontaktów, rozmowy, połączenia, przekazania, kopii
    $in['numbers'] = implode("\n", array_map(static fn ($p) => Phone::format(Phone::normalize($p) ?? $p), input_list('to') ?: array_filter([input('to')])));
    $in['contacts'] = array_values(array_unique([...input_ids('contacts'), ...array_filter([(int) input('contact')])]));
    $in['groups'] = array_filter([(int) input('group')]);
    $src = (int) (input('copy') ?: input('forward'));
    if ($src > 0) {
        $in['text'] = (string) Db::val('SELECT body FROM messages WHERE id = ?', [$src]);
    }
    if (($tpl = (int) input('template')) > 0) {
        $in['text'] = (string) Db::val('SELECT body FROM templates WHERE id = ?', [$tpl]);
    }
    if (input('text') !== '') {
        $in['text'] = input('text');
    }
}

$plan ??= Compose::plan($in);

/** Kontakty do listy wyboru: zaznaczone + pasujące do wyszukiwania (najwyżej 40). */
function compose_contacts(string $q, array $selected): array
{
    $rows = $selected === [] ? [] : Db::all('SELECT id, name, phone FROM contacts WHERE id IN (' . Db::in($selected) . ') ORDER BY name', $selected);
    [$found] = Contacts::search($q, '', 1, 40);
    foreach ($found as $c) {
        if (!in_array((int) $c['id'], $selected, true)) {
            $rows[] = $c;
        }
    }
    return $rows;
}

render('compose', [
    'sendToken' => preg_match('/^[0-9a-f]{16}$/', input('send_token')) ? input('send_token') : bin2hex(random_bytes(8)),
    'title' => 'Nowa wiadomość', 'nav' => 'compose', 'in' => $in, 'plan' => $plan, 'attempted' => $attempted, 'confirm' => $confirm,
    'groups' => Contacts::groups(), 'contacts' => compose_contacts('', $in['contacts']), 'templates' => Templates::all(),
]);
