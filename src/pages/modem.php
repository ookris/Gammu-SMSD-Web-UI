<?php
// Modem i USSD
if (is_post()) {
    if (($forget = input('forget_modem')) !== '') {
        // Tylko niedostępny modem (np. po zmianie PhoneID albo odłączeniu) – działający i tak wróciłby przy następnej synchronizacji
        foreach (Status::modems() as $m) {
            if ($m['modem'] === $forget && !Status::modemAvailable($m)) {
                Db::exec('DELETE FROM modem_status WHERE modem = ?', [$forget]);
                flash('ok', t('modem.forgotten', ['modem' => $forget]));
            }
        }
        redirect(url('modem'));
    }
    if (($close = (int) input('close')) > 0) {
        Ussd::close($close);
        redirect(url('modem'));
    }
    $parent = (int) input('parent');
    $code = input($parent > 0 ? 'reply' : 'code');
    $modem = $parent > 0 ? (Ussd::get($parent)['modem'] ?? null) : Status::validModem(input('modem'));
    [$id, $err] = Ussd::send($code, $modem ?? Status::modem()['modem'] ?? null, $parent > 0 ? $parent : null);
    if ($err !== null) {
        flash('err', $err);
        redirect(url('modem'));
    }
    redirect(url('modem', ['ussd' => $id]));
}
$current = (int) input('ussd') > 0 ? Ussd::get((int) input('ussd')) : null;
if ($current === null) {
    $last = Ussd::history(1)[0] ?? null;
    $current = $last !== null && in_array($last['status'], ['queued', 'sent'], true) || ($last !== null && (int) $last['session_status'] === 3) ? $last : null;
}
if (input('fragment') === 'ussd') {
    Sync::run();
    echo view('modem-ussd', ['current' => $current ? Ussd::get((int) $current['id']) : null]);
    exit;
}
if (input('fragment') === 'state') {
    echo view('modem-state', ['modems' => Status::modems()]);
    exit;
}
$balance = ['enabled' => Balance::enabled(), 'by' => []];
foreach (Balance::modemsWithData() as $name) {
    $balance['by'][$name] = ['latest' => Balance::latest($name), 'series' => Balance::series(30, $name)];
}
render('modem', ['balance' => $balance,'title' => t('nav.modem'), 'nav' => 'modem', 'modems' => Status::modems(), 'current' => $current,
    'codes' => Settings::json('ussd_codes'), 'history' => Ussd::history(20),
    'device' => GammuConf::load()?->get('gammu', 'device')]);
