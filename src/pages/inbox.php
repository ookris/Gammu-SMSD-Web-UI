<?php
// Odebrane: lista rozmów + wybrana rozmowa (wiadomości, połączenia, odpowiedź)
$raw = input('phone');
// Nazwa nadawcy (zawiera litery) bez zmian, numer – tylko po poprawnej normalizacji
$phone = $raw === '' ? '' : (preg_match('/\p{L}/u', $raw) && mb_strlen($raw) <= 32 ? $raw : Phone::normalize($raw));
if ($phone === null) {
    flash('err', t('threads.invalid_number', ['number' => $raw]), (string) Phone::error($raw));
    redirect(url('inbox'));
}

// Akcje zbiorcze na liście rozmów
if (is_post() && input('bulk') !== '') {
    $phones = array_values(array_filter(input_list('phones'), static fn ($p) => $p !== ''));
    $n = count($phones);
    $bulk = input('bulk');
    match ($bulk) {
        'read' => array_map([Threads::class, 'markRead'], $phones),
        'unread' => Threads::markUnread($phones),
        'delete' => array_map([Threads::class, 'delete'], $phones),
        default => null,
    };
    if ($bulk === 'delete' && $n > 0) {
        // Rozmowy z wiadomościami w kolejce zostają – liczymy tylko faktycznie usunięte
        $left = (int) Db::val('SELECT COUNT(DISTINCT phone) FROM messages WHERE phone IN (' . Db::in($phones) . ')', $phones);
        $left < $n ? flash('ok', tn('inbox.threads_deleted', $n - $left), $left > 0 ? tn('inbox.threads_kept', $left) : '') : flash('err', t('inbox.delete_pending'));
    } elseif ($n > 0) {
        flash('ok', t('common.saved'));
    }
    // „Nieprzeczytane” nie może wrócić na otwartą rozmowę – jej otwarcie od razu oznaczyłoby ją jako przeczytaną
    $bulk === 'unread' ? redirect(url('inbox')) : back(url('inbox'));
}

if (is_post() && $phone !== '') {
    if (isset($_POST['delete_msg'])) {
        $n = Messages::delete([(int) $_POST['delete_msg']], null, $phone);
        $n > 0 ? flash('ok', tn('inbox.deleted', $n)) : flash('err', t('inbox.delete_pending'));
        redirect(url('inbox', ['phone' => $phone]));
    }
    if (isset($_POST['mark_unread'])) {
        Threads::markUnread([$phone]);
        flash('ok', t('common.saved'));
        redirect(url('inbox'));
    }
    if (isset($_POST['block'])) {
        Blocklist::blockFromPanel($phone, t('threads.block_note'));
        redirect(url('inbox', ['phone' => $phone]));
    }
    if (isset($_POST['delete_thread'])) {
        $n = Threads::delete($phone);
        flash('ok', t('threads.deleted', ['who' => Contacts::display($phone)]), tn('common.messages', $n) . '.');
        redirect(url('inbox'));
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
                'translit' => isset($_POST['translit']), 'priority' => Outbox::PRIORITY_SINGLE, 'modem' => Threads::replyModem($phone)]);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
    if (!is_htmx()) {
        $error ? flash('err', $error) : flash('ok', t('threads.queued'));
        redirect(url('inbox', ['phone' => $phone]));
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
$f = clean_dates(['q' => input('q'), 'from' => input('from'), 'to' => input('to'), 'unread' => input('unread'),
    'dir' => in_array(input('dir'), ['in', 'out'], true) ? input('dir') : ''], ['from', 'to']);
$list = Threads::list($f);
if ($phone !== '' && !in_array($phone, array_column($list, 'phone'), true) && !array_filter($f)) {
    array_unshift($list, ['phone' => $phone, 'body' => '', 'direction' => 'out', 'created_at' => null, 'received_at' => null, 'unread' => 0]);
}
$contact = $phone !== '' ? Contacts::byPhone($phone) : null;

render('inbox', [
    'title' => $phone !== '' ? Contacts::display($phone) : t('nav.inbox'), 'nav' => 'inbox', 'phone' => $phone, 'list' => $list, 'f' => $f,
    'blockedList' => Blocklist::blockedAmong(array_column($list, 'phone')),
    'contact' => $contact, 'groups' => $contact ? (Contacts::groupsOf([(int) $contact['id']])[(int) $contact['id']] ?? []) : [],
    'items' => $phone !== '' ? Threads::timeline($phone) : [], 'version' => $phone !== '' ? Threads::version($phone) : '',
    'blocked' => $phone !== '' && Blocklist::isBlocked($phone),
    'stats' => Db::row("SELECT COUNT(DISTINCT phone) AS threads, SUM(direction = 'in' AND is_read = 0) AS unread FROM messages"),
]);
