<?php
$currentUser = $auth->user();
$activeNav = $activeNav ?? '';
$flashes = consume_flashes();
$nav = [
    ['dashboard', '/', 'dashboard', 'Přehled'],
    ['clients', '/clients', 'devices', 'Připojená zařízení'],
    ['registrations', '/registrations', 'register', 'Registrace'],
    ['networks', '/networks', 'wifi', 'Wi‑Fi sítě'],
    ['access-points', '/access-points', 'radio', 'Přístupové body'],
];
$monitoringNav = [
    ['syslog', '/syslog', 'terminal', 'Syslog události'],
    ['flows', '/flows', 'flow', 'Síťové toky'],
];
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($title ?? 'WiFi Manager') ?> · <?= e($config->get('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="app-shell">
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <span class="brand-mark"><?= icon('wifi') ?></span>
        <span><strong>WiFi</strong> Manager<small>Správa sítí</small></span>
    </div>
    <nav class="main-nav" aria-label="Hlavní navigace">
        <span class="nav-caption">SPRÁVA SÍTĚ</span>
        <?php foreach ($nav as [$key, $href, $navIcon, $label]): ?>
            <a href="<?= e(url($href)) ?>" class="nav-item <?= $activeNav === $key ? 'active' : '' ?>">
                <?= icon($navIcon) ?><span><?= e($label) ?></span>
                <?php if ($key === 'registrations'): ?><span class="nav-count" data-pending-count hidden>0</span><?php endif; ?>
            </a>
        <?php endforeach; ?>
        <span class="nav-caption section-gap">PROVOZNÍ DATA</span>
        <?php foreach ($monitoringNav as [$key, $href, $navIcon, $label]): ?>
            <a href="<?= e(url($href)) ?>" class="nav-item <?= $activeNav === $key ? 'active' : '' ?>"><?= icon($navIcon) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
        <?php if ($auth->isAdmin()): ?>
            <span class="nav-caption section-gap">ADMINISTRACE</span>
            <a href="<?= e(url('/audit')) ?>" class="nav-item <?= $activeNav === 'audit' ? 'active' : '' ?>"><?= icon('history') ?><span>Historie změn</span></a>
            <a href="<?= e(url('/users')) ?>" class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>"><?= icon('users') ?><span>Uživatelé</span></a>
            <a href="<?= e(url('/settings')) ?>" class="nav-item <?= $activeNav === 'settings' ? 'active' : '' ?>"><?= icon('settings') ?><span>Nastavení</span></a>
            <a href="<?= e(url('/system')) ?>" class="nav-item <?= $activeNav === 'system' ? 'active' : '' ?>"><?= icon('server') ?><span>Služby a aktualizace</span></a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($currentUser['display_name'] ?? 'U'), 0, 1))) ?></div>
        <div class="user-meta"><strong><?= e($currentUser['display_name'] ?? '') ?></strong><span><?= ($currentUser['role'] ?? '') === 'admin' ? 'Administrátor' : 'Pouze prohlížení' ?></span></div>
        <form method="post" action="<?= e(url('/logout')) ?>">
            <?= csrf_field() ?>
            <button class="icon-button dark" title="Odhlásit" aria-label="Odhlásit"><?= icon('logout') ?></button>
        </form>
    </div>
</aside>

<div class="page-wrap">
    <header class="topbar">
        <button class="icon-button mobile-menu" type="button" data-sidebar-toggle aria-label="Otevřít menu"><?= icon('menu') ?></button>
        <div><span class="eyebrow">WI‑FI INFRASTRUKTURA</span><h1><?= e($title ?? '') ?></h1></div>
        <div class="topbar-actions">
            <span class="live-chip"><i></i> živá data</span>
            <span class="clock" data-clock></span>
        </div>
    </header>

    <main class="content">
        <?php foreach ($flashes as $flash): ?>
            <div class="toast <?= e($flash['type']) ?>" role="status">
                <?= icon($flash['type'] === 'success' ? 'check' : 'alert') ?>
                <span><?= e($flash['message']) ?></span>
                <button type="button" data-dismiss aria-label="Zavřít"><?= icon('close') ?></button>
            </div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>
<div class="sidebar-backdrop" data-sidebar-toggle></div>
<script>window.WFM = <?= json_encode(['basePath' => $config->get('app.base_path', ''), 'csrf' => \WifiManager\Csrf::token()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(asset('app.js')) ?>" defer></script>
</body>
</html>
