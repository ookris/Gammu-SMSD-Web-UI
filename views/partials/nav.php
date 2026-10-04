<?php
/** @var string $nav @var array $status */
$unread = Status::unreadMessages();
$calls = Status::unreadCalls();
$user = Auth::user();
$link = static function (string $page, string $iconName, string $label, int $count = 0) use ($nav): string {
    $current = $nav === $page ? ' aria-current="page"' : '';
    $badge = $count > 0 ? '<span class="nav-count" aria-label="' . e(t('layout.nav_new', ['n' => $count])) . '">' . $count . '</span>' : '';
    return '<a class="nav-link" href="' . e(url($page)) . '"' . $current . '>' . icon($iconName) . '<span>' . e($label) . '</span>' . $badge . '</a>';
};
?>
<div class="brand"><span class="brand-mark"><?= icon('chat-round-line') ?></span><div><div class="brand-name"><?= e(t('app.name')) ?></div><div class="brand-sub">Gammu SMSD<?= $status['modem'] !== '' ? ' · ' . e($status['modem']) : '' ?></div></div></div>
<a class="compose" href="<?= e(url('compose')) ?>"<?= $nav === 'compose' ? ' aria-current="page"' : '' ?>><?= icon('add-circle') ?><?= e(t('nav.compose')) ?></a>
<?= $link('dashboard', 'home-angle', t('nav.dashboard')) ?>
<?= $link('threads', 'chat-round-line', t('nav.threads'), $unread) ?>
<?= $link('inbox', 'inbox-in', t('nav.inbox')) ?>
<?= $link('sent', 'inbox-out', t('nav.sent')) ?>
<?= $link('contacts', 'users-group-two-rounded', t('nav.contacts')) ?>
<?= $link('groups', 'folder-with-files', t('nav.groups')) ?>
<?= $link('templates', 'notes', t('nav.templates')) ?>
<div class="nav-group"><?= e(t('nav.gateway')) ?></div>
<?= $link('modem', 'sim-card', t('nav.modem')) ?>
<?= $link('calls', 'end-call', t('nav.calls'), $calls) ?>
<?= $link('blocklist', 'forbidden-circle', t('nav.blocklist')) ?>
<div class="nav-group"><?= e(t('nav.system')) ?></div>
<?= $link('config', 'tuning-2', t('nav.config')) ?>
<?= $link('log', 'code-square', t('nav.log')) ?>
<?= $link('settings', 'settings', t('nav.settings')) ?>
<div class="side-status" role="status"><strong><span class="dot dot-<?= e($status['level']) ?>"></span><?= e($status['title']) ?></strong><span class="line2"><?= e($status['line2']) ?></span><span class="line3"><?= e($status['line3']) ?></span></div>
<div class="side-user"><a href="<?= e(url('password')) ?>"<?= $nav === 'password' ? ' aria-current="page"' : '' ?>><?= icon('user') ?><span><?= e($user['username'] ?? '') ?></span></a><form class="logout-form" method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button type="submit" class="logout" aria-label="<?= e(t('nav.logout')) ?>" title="<?= e(t('nav.logout')) ?>"><?= icon('logout-2') ?></button></form></div>
<p class="side-credit"><?= t('layout.credit', ['link' => '<a href="https://icon-sets.iconify.design/solar/">Solar Icon Set</a>']) ?></p>
