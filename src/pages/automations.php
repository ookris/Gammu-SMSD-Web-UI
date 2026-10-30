<?php
// Automatyzacje: kanały powiadomień (e-mail, webhook) i ustawienia poczty
$tab = in_array(input('tab'), ['channels', 'alerts', 'rules', 'replies', 'schedules', 'api'], true) ? input('tab') : 'channels';
$id = (int) input('id');
$error = null;
$mailError = null;
$ruleError = null;
$schedError = null;

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
    } elseif ($tab === 'api') {
        $tid = (int) input('token');
        if (isset($_POST['token_revoke']) && $tid > 0) {
            ApiTokens::revoke($tid);
            flash('ok', t('api.revoked'));
        } elseif (isset($_POST['token_delete']) && $tid > 0) {
            ApiTokens::delete($tid);
            flash('ok', t('api.deleted'));
        } else {
            [, $plain, $apiError] = ApiTokens::create(input('name'), input('ips'));
            if ($plain !== null) {
                $_SESSION['api_new_token'] = $plain; // pokazany raz, zaraz po przekierowaniu
            } else {
                flash('err', $apiError);
            }
        }
        redirect(url('automations', ['tab' => 'api']));
    } elseif ($tab === 'schedules') {
        $sid = (int) input('sched');
        if (isset($_POST['sched_toggle']) && $sid > 0) {
            Schedules::setEnabled($sid, input('sched_toggle') === '1');
            redirect(url('automations', ['tab' => 'schedules']));
        } elseif (isset($_POST['sched_delete']) && $sid > 0) {
            Schedules::delete($sid);
            flash('ok', t('schedules.deleted'));
            redirect(url('automations', ['tab' => 'schedules']));
        }
        [$saved, $schedError] = Schedules::save($sid > 0 ? $sid : null, [
            'name' => input('name'), 'groups' => input_ids('groups'), 'numbers' => input('numbers'), 'text' => (string) ($_POST['text'] ?? ''),
            'translit' => isset($_POST['translit']), 'report' => isset($_POST['report']), 'flash' => isset($_POST['flash']), 'frequency' => input('frequency'),
            'weekdays' => array_map('intval', input_list('weekdays')), 'month_day' => (int) input('month_day'), 'time' => input('time'),
        ]);
        if ($saved !== null) {
            flash('ok', t('schedules.saved'));
            redirect(url('automations', ['tab' => 'schedules']));
        }
    } elseif (in_array($tab, ['rules', 'replies'], true) && is_post()) {
        $kind = $tab === 'replies' ? 'autoreply' : 'forward';
        $rid = (int) input('rule');
        if (isset($_POST['rule_test']) && $rid > 0) {
            $err = Rules::test($rid);
            $err === null ? flash('ok', t('channels.test_ok')) : flash('err', t('channels.test_failed'), $err);
            redirect(url('automations', ['tab' => $tab]));
        } elseif (isset($_POST['rule_toggle']) && $rid > 0) {
            Rules::setEnabled($rid, input('rule_toggle') === '1');
            redirect(url('automations', ['tab' => $tab]));
        } elseif (isset($_POST['rule_delete']) && $rid > 0) {
            Rules::delete($rid);
            flash('ok', t('rules.deleted'));
            redirect(url('automations', ['tab' => $tab]));
        }
        [$saved, $ruleError] = Rules::save($rid > 0 ? $rid : null, [
            'name' => input('name'), 'event' => input('event'), 'cond_type' => input('cond_type'), 'number' => input('number'),
            'group' => (int) input('group'), 'text' => input('cond_text'), 'channel_id' => (int) input('channel_id'),
            'reply_text' => (string) ($_POST['reply_text'] ?? ''), 'hours_mode' => input('hours_mode'), 'hours_from' => input('hours_from'),
            'hours_to' => input('hours_to'), 'workdays' => isset($_POST['workdays']),
        ], $kind);
        if ($saved !== null) {
            flash('ok', t('rules.saved'));
            redirect(url('automations', ['tab' => $tab]));
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
$ruleTab = in_array($tab, ['rules', 'replies'], true);
$ruleId = $ruleTab ? (int) input('rule') : 0;
$rule = $ruleId > 0 ? Rules::find($ruleId) : null;
if ($rule !== null && $rule['kind'] !== ($tab === 'replies' ? 'autoreply' : 'forward')) {
    $rule = null;
}
$ruleForm = is_post() && $ruleError !== null
    ? ['name' => input('name'), 'event' => input('event'), 'cond_type' => input('cond_type'), 'number' => input('number'), 'group' => (int) input('group'),
        'text' => input('cond_text'), 'channel_id' => (int) input('channel_id'), 'reply_text' => (string) ($_POST['reply_text'] ?? ''),
        'hours_mode' => input('hours_mode'), 'hours_from' => input('hours_from'), 'hours_to' => input('hours_to'), 'workdays' => isset($_POST['workdays'])]
    : ['name' => $rule['name'] ?? '', 'event' => $rule['event'] ?? 'sms', 'cond_type' => $rule['cond_type'] ?? 'all',
        'number' => ($rule['cond_type'] ?? '') === 'number' ? Phone::format($rule['cond_value']) : '',
        'group' => ($rule['cond_type'] ?? '') === 'group' ? (int) $rule['cond_value'] : 0,
        'text' => in_array($rule['cond_type'] ?? '', ['text', 'equals', 'starts'], true) ? $rule['cond_value'] : '', 'channel_id' => (int) ($rule['channel_id'] ?? 0),
        'reply_text' => $rule['reply_text'] ?? '', 'hours_mode' => $rule['hours_mode'] ?? 'any',
        'hours_from' => substr((string) ($rule['hours_from'] ?? '08:00'), 0, 5), 'hours_to' => substr((string) ($rule['hours_to'] ?? '16:00'), 0, 5),
        'workdays' => (bool) ($rule['workdays_only'] ?? 0)];
$newToken = null;
if ($tab === 'api' && isset($_SESSION['api_new_token'])) {
    $newToken = $_SESSION['api_new_token'];
    unset($_SESSION['api_new_token']);
}
$sched = $tab === 'schedules' && (int) input('sched') > 0 ? Schedules::find((int) input('sched')) : null;
$schedForm = is_post() && $schedError !== null
    ? ['name' => input('name'), 'groups' => input_ids('groups'), 'numbers' => input('numbers'), 'text' => (string) ($_POST['text'] ?? ''), 'translit' => isset($_POST['translit']),
        'report' => isset($_POST['report']), 'flash' => isset($_POST['flash']), 'frequency' => input('frequency'), 'weekdays' => array_map('intval', input_list('weekdays')),
        'month_day' => (int) input('month_day') ?: 1, 'time' => input('time')]
    : ['name' => $sched['name'] ?? '', 'groups' => json_decode((string) ($sched['groups_json'] ?? '[]'), true) ?: [], 'numbers' => $sched['numbers'] ?? '',
        'text' => $sched['body'] ?? '', 'translit' => (bool) ($sched['translit'] ?? Settings::bool('translit_default')), 'report' => (bool) ($sched['report'] ?? false),
        'flash' => (bool) ($sched['flash'] ?? false), 'frequency' => $sched['frequency'] ?? 'daily',
        'weekdays' => array_map('intval', array_filter(explode(',', (string) ($sched['weekdays'] ?? '1,2,3,4,5')))), 'month_day' => (int) ($sched['month_day'] ?? 1),
        'time' => substr((string) ($sched['at_time'] ?? '08:00'), 0, 5)];
render('automations', [
    'schedules' => $tab === 'schedules' ? Schedules::all() : [], 'schedId' => $sched ? (int) $sched['id'] : 0, 'schedForm' => $schedForm, 'schedError' => $schedError,
    'tokens' => $tab === 'api' ? ApiTokens::all() : [], 'newToken' => $newToken,
    'rules' => $ruleTab ? Rules::all($tab === 'replies' ? 'autoreply' : 'forward') : [], 'ruleId' => $rule ? $ruleId : 0, 'ruleForm' => $ruleForm, 'ruleError' => $ruleError,
    'groups' => $ruleTab || $tab === 'schedules' ? Contacts::groups() : [],
    'tab' => $tab,
    'title' => t('nav.automations'), 'nav' => 'automations', 'channels' => Channels::all(), 'id' => $current ? $id : 0, 'form' => $form,
    'hasSecret' => ($cfg['secret'] ?? '') !== '', 'error' => $error, 'mailError' => $mailError, 'deliveries' => Deliveries::counts(),
    'routes' => Alerts::routes(), 'alertState' => Alerts::state(),
    'mail' => is_post() && $mailError !== null ? $_POST + Channels::mailSettings() : Channels::mailSettings(),
]);
