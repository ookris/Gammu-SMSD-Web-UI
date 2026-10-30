<?php
// Automatyzacje: kanały powiadomień (e-mail, webhook) i ustawienia poczty
$id = (int) input('id');
$error = null;
$mailError = null;

if (is_post()) {
    if (isset($_POST['mail_save'])) {
        $mailError = Channels::saveMail($_POST + ['pass_clear' => isset($_POST['pass_clear'])]);
        if ($mailError === null) {
            flash('ok', t('channels.mail_saved'));
            redirect(url('automations'));
        }
    } elseif (isset($_POST['test']) && $id > 0) {
        $err = Channels::test($id);
        $err === null ? flash('ok', t('channels.test_ok')) : flash('err', t('channels.test_failed'), $err);
        redirect(url('automations'));
    } elseif (isset($_POST['toggle']) && $id > 0) {
        Channels::setEnabled($id, input('toggle') === '1');
        redirect(url('automations'));
    } elseif (isset($_POST['delete']) && $id > 0) {
        Channels::delete($id);
        flash('ok', t('channels.deleted'));
        redirect(url('automations'));
    } else {
        $type = input('type');
        $existing = $id > 0 ? Channels::find($id) : null;
        if ($existing !== null) {
            $type = $existing['type'];
        }
        [$saved, $error] = in_array($type, ['email', 'webhook'], true)
            ? Channels::save($existing ? $id : null, $type, [
                'name' => input('name'), 'to' => input('to'), 'url' => input('url'), 'secret' => (string) ($_POST['secret'] ?? ''),
                'secret_clear' => isset($_POST['secret_clear']),
            ])
            : [null, t('channels.err_missing')];
        if ($saved !== null) {
            flash('ok', t('channels.saved'));
            redirect(url('automations'));
        }
    }
}

$current = $id > 0 ? Channels::find($id) : null;
$cfg = $current ? Channels::config($current) : [];
$form = is_post() && $error !== null
    ? ['type' => input('type'), 'name' => input('name'), 'to' => input('to'), 'url' => input('url')]
    : ['type' => $current['type'] ?? 'email', 'name' => $current['name'] ?? '', 'to' => $cfg['to'] ?? '', 'url' => $cfg['url'] ?? ''];
render('automations', [
    'title' => t('nav.automations'), 'nav' => 'automations', 'channels' => Channels::all(), 'id' => $current ? $id : 0, 'form' => $form,
    'hasSecret' => ($cfg['secret'] ?? '') !== '', 'error' => $error, 'mailError' => $mailError, 'deliveries' => Deliveries::counts(),
    'mail' => is_post() && $mailError !== null ? $_POST + Channels::mailSettings() : Channels::mailSettings(),
]);
