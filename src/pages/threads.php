<?php
// Rozmowy
$raw = input('phone');
// Nazwa nadawcy (zawiera litery) bez zmian, numer – tylko po poprawnej normalizacji
$phone = $raw === '' ? '' : (preg_match('/\p{L}/u', $raw) && mb_strlen($raw) <= 32 ? $raw : Phone::normalize($raw));
if ($phone === null) {
    flash('err', t('threads.invalid_number', ['number' => $raw]), (string) Phone::error($raw));
    redirect(url('threads'));
}

if (is_post() && $phone !== '') {
    if (isset($_POST['block'])) {
        Blocklist::blockFromPanel($phone, t('threads.block_note'));
        redirect(url('threads', ['phone' => $phone]));
    }
    if (isset($_POST['delete_thread'])) {
        $n = Threads::delete($phone);
        flash('ok', t('threads.deleted', ['who' => Contacts::display($phone)]), tn('common.messages', $n) . '.');
        redirect(url('threads'));
    }
    // Odpowiedź (htmx – bez przeładowania strony)
    $text = SmsText::normalize((string) ($_POST['text'] ?? ''));
    $error = null;
    if (trim($text) === '') {
        $error = t('threads.empty_reply');
    } elseif (Phone::isAlpha($phone)) {
        $error = t('threads.alpha_readonly');
    } else {
        try {
            Outbox::create(['phone' => $phone, 'body' => $text, 'report' => Settings::bool('report_default'),
                'translit' => isset($_POST['translit']), 'priority' => Outbox::PRIORITY_SINGLE]);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
    if (!is_htmx()) {
        $error ? flash('err', $error) : flash('ok', t('threads.queued'));
        redirect(url('threads', ['phone' => $phone]));
    }
    echo view('thread-body', ['phone' => $phone, 'items' => Threads::timeline($phone), 'version' => Threads::version($phone)]);
    echo view('thread-reply', ['phone' => $phone, 'oob' => true, 'error' => $error, 'text' => $error ? $text : '']);
    exit;
}

// Odświeżanie fragmentu co 10 s – tylko gdy coś się zmieniło
if ($phone !== '' && input('fragment') === 'body') {
    $version = Threads::version($phone);
    if ($version === input('v')) {
        http_response_code(204);
        exit;
    }
    Threads::markRead($phone);
    echo view('thread-body', ['phone' => $phone, 'items' => Threads::timeline($phone), 'version' => Threads::version($phone)]);
    exit;
}

if ($phone !== '') {
    Threads::markRead($phone);
    Status::forget();
}
$q = input('q');
$list = Threads::list($q);
if ($phone !== '' && !in_array($phone, array_column($list, 'phone'), true) && $q === '') {
    array_unshift($list, ['phone' => $phone, 'body' => '', 'direction' => 'out', 'created_at' => null, 'received_at' => null, 'unread' => 0]);
}
$contact = $phone !== '' ? Contacts::byPhone($phone) : null;

render('threads', [
    'title' => $phone !== '' ? Contacts::display($phone) : t('nav.threads'), 'nav' => 'threads', 'phone' => $phone, 'list' => $list, 'q' => $q,
    'contact' => $contact, 'groups' => $contact ? (Contacts::groupsOf([(int) $contact['id']])[(int) $contact['id']] ?? []) : [],
    'items' => $phone !== '' ? Threads::timeline($phone) : [], 'version' => $phone !== '' ? Threads::version($phone) : '',
    'blocked' => $phone !== '' && Blocklist::isBlocked($phone),
    'stats' => Db::row("SELECT COUNT(DISTINCT phone) AS threads, SUM(direction = 'in' AND is_read = 0) AS unread FROM messages"),
]);
