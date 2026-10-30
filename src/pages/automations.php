<?php
// Automatyzacje: kanały powiadomień (e-mail, webhook) i ustawienia poczty
$tab = in_array(input('tab'), ['channels', 'alerts', 'rules'], true) ? input('tab') : 'channels';
$id = (int) input('id');
$error = null;
$mailError = null;
$ruleError = null;

if (is_post()) {
    if (isset($_POST['mail_save'])) {
        $mailError = Channels::saveMail($_POST + ['pass_clear' => isset($_POST['pass_clear'])]);
        if ($mailError === null) {
            flash('ok', t('channels.mail_saved'));
            redirect(url('automations'));
        }
    } elseif (isset($_POST['alerts_save'])) {
        Alerts::saveRoutes(is_array($_POST['route'] ?? null) ? $_POST['route'] : []);
        flash('ok', t('alerts.saved'));
        redirect(url('automations', ['tab' => 'alerts']));
    } elseif ($tab === 'rules' && is_post()) {
        $rid = (int) input('rule');
        if (isset($_POST['rule_test']) && $rid > 0) {
            $err = Rules::test($rid);
            $err === null ? flash('ok', t('channels.test_ok')) : flash('err', t('channels.test_failed'), $err);
            redirect(url('automations', ['tab' => 'rules']));
        } elseif (isset($_POST['rule_toggle']) && $rid > 0) {
            Rules::setEnabled($rid, input('rule_toggle') === '1');
            redirect(url('automations', ['tab' => 'rules']));
        } elseif (isset($_POST['rule_delete']) && $rid > 0) {
            Rules::delete($rid);
            flash('ok', t('rules.deleted'));
            redirect(url('automations', ['tab' => 'rules']));
        }
        [$saved, $ruleError] = Rules::save($rid > 0 ? $rid : null, [
            'name' => input('name'), 'event' => input('event'), 'cond_type' => input('cond_type'), 'number' => input('number'),
            'group' => (int) input('group'), 'text' => input('cond_text'), 'channel_id' => (int) input('channel_id'),
        ]);
        if ($saved !== null) {
            flash('ok', t('rules.saved'));
            redirect(url('automations', ['tab' => 'rules']));
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
        [$saved, $error] = in_array($type, ['email', 'webhook', 'sms'], true)
            ? Channels::save($existing ? $id : null, $type, [
                'name' => input('name'), 'to' => input('to'), 'url' => input('url'), 'number' => input('number'), 'secret' => (string) ($_POST['secret'] ?? ''),
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
    ? ['type' => input('type'), 'name' => input('name'), 'to' => input('to'), 'url' => input('url'), 'number' => input('number')]
    : ['type' => $current['type'] ?? 'email', 'name' => $current['name'] ?? '', 'to' => $cfg['to'] ?? '', 'url' => $cfg['url'] ?? '',
        'number' => isset($cfg['number']) ? Phone::format($cfg['number']) : ''];
$ruleId = $tab === 'rules' ? (int) input('rule') : 0;
$rule = $ruleId > 0 ? Rules::find($ruleId) : null;
$ruleForm = is_post() && $ruleError !== null
    ? ['name' => input('name'), 'event' => input('event'), 'cond_type' => input('cond_type'), 'number' => input('number'), 'group' => (int) input('group'),
        'text' => input('cond_text'), 'channel_id' => (int) input('channel_id')]
    : ['name' => $rule['name'] ?? '', 'event' => $rule['event'] ?? 'sms', 'cond_type' => $rule['cond_type'] ?? 'all',
        'number' => ($rule['cond_type'] ?? '') === 'number' ? Phone::format($rule['cond_value']) : '',
        'group' => ($rule['cond_type'] ?? '') === 'group' ? (int) $rule['cond_value'] : 0,
        'text' => ($rule['cond_type'] ?? '') === 'text' ? $rule['cond_value'] : '', 'channel_id' => (int) ($rule['channel_id'] ?? 0)];
render('automations', [
    'rules' => $tab === 'rules' ? Rules::all() : [], 'ruleId' => $rule ? $ruleId : 0, 'ruleForm' => $ruleForm, 'ruleError' => $ruleError,
    'groups' => $tab === 'rules' ? Contacts::groups() : [],
    'tab' => $tab,
    'title' => t('nav.automations'), 'nav' => 'automations', 'channels' => Channels::all(), 'id' => $current ? $id : 0, 'form' => $form,
    'hasSecret' => ($cfg['secret'] ?? '') !== '', 'error' => $error, 'mailError' => $mailError, 'deliveries' => Deliveries::counts(),
    'routes' => Alerts::routes(), 'alertState' => Alerts::state(),
    'mail' => is_post() && $mailError !== null ? $_POST + Channels::mailSettings() : Channels::mailSettings(),
]);
