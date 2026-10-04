<?php
// Kontakt – dodawanie i edycja (rozdz. 2.7)
$id = (int) input('id');
$contact = $id > 0 ? Contacts::find($id) : null;
if ($id > 0 && $contact === null) {
    http_response_code(404);
    echo view('error', ['code' => 404]);
    exit;
}
$errors = [];
$form = $contact ?? ['name' => '', 'phone' => '', 'note' => ''];
$form['phone_input'] = $contact ? Phone::format($contact['phone']) : (input('phone') !== '' ? Phone::format(Phone::normalize(input('phone')) ?? input('phone')) : '');
$selected = $contact ? Contacts::groupIdsOf($id) : [];

if (is_post()) {
    if (isset($_POST['delete']) && $contact) {
        Contacts::delete([$id]);
        flash('ok', 'Usunięto kontakt ' . $contact['name'] . '.', 'Wiadomości i rozmowa zostały – widać w nich numer ' . Phone::format($contact['phone']) . '.');
        redirect(url('contacts'));
    }
    if (isset($_POST['block']) && $contact) {
        Blocklist::blockFromPanel($contact['phone'], $contact['name']);
        redirect(url('contact', ['id' => $id]));
    }
    $form = ['name' => input('name'), 'phone_input' => input('phone'), 'note' => input('note')] + $form;
    $selected = input_ids('groups');
    [$saved, $errors] = Contacts::save($contact ? $id : null, $form['name'], $form['phone_input'], $form['note'], $selected);
    if ($saved !== null) {
        flash('ok', 'Zapisano kontakt.');
        redirect(url('contact', ['id' => $saved]));
    }
}

$feed = [];
if ($contact) {
    $msgs = Db::all("SELECT id, direction, body, status, retries, error, scheduled_at, created_at, delivered_at, batch_id, send_window,
        COALESCE(received_at, created_at) AS at, 'msg' AS kind FROM messages WHERE phone = ? ORDER BY id DESC LIMIT 10", [$contact['phone']]);
    $calls = Db::all("SELECT received_at AS at, 'call' AS kind FROM calls WHERE phone = ? ORDER BY id DESC LIMIT 10", [$contact['phone']]);
    $feed = array_merge($msgs, $calls);
    usort($feed, static fn ($a, $b) => strcmp($b['at'], $a['at']));
    $feed = array_slice($feed, 0, 10);
}

render('contact', ['title' => $contact['name'] ?? 'Nowy kontakt', 'nav' => 'contacts', 'contact' => $contact, 'form' => $form, 'errors' => $errors,
    'groups' => Contacts::groups(), 'selected' => $selected, 'feed' => $feed, 'blocked' => $contact && Blocklist::isBlocked($contact['phone'])]);
