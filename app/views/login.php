<?php $flashes = consume_flashes(); ?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Přihlášení · WiFi Manager</title>
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
</head>
<body class="login-page">
<main class="login-card">
    <section class="login-brand">
        <div class="login-orbit"><span><?= icon('wifi') ?></span><i></i><i></i><i></i></div>
        <div><span class="eyebrow light">SPRÁVA CAPSMAN WI‑FI</span><h1>WiFi<br><strong>Manager</strong></h1><p>Jedno místo pro přístupové body, klienty, registrace a bezpečný provoz sítě.</p></div>
        <small>Zabezpečená síťová administrace</small>
    </section>
    <section class="login-form-wrap">
        <div class="login-form-head"><span class="login-icon"><?= icon('lock') ?></span><h2>Vítejte zpět</h2><p>Přihlaste se do administrace WiFi Manageru.</p></div>
        <?php if (!empty($fatalError)): ?><div class="form-alert error"><?= e($fatalError) ?></div><?php endif; ?>
        <?php foreach ($flashes as $flash): ?><div class="form-alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
        <form method="post" action="<?= e(url('/login')) ?>" class="stacked-form">
            <?= csrf_field() ?>
            <label><span>Uživatelské jméno</span><div class="input-icon"><?= icon('users') ?><input name="username" autocomplete="username" autofocus required></div></label>
            <label><span>Heslo</span><div class="input-icon"><?= icon('lock') ?><input type="password" name="password" autocomplete="current-password" required></div></label>
            <button class="button primary wide" type="submit">Přihlásit se <?= icon('logout') ?></button>
        </form>
        <p class="login-help">Přístup je evidován v historii administrace.</p>
    </section>
</main>
</body>
</html>
