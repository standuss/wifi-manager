<?php
$routerStatus = $router['status'] ?? 'unconfigured';
$statusLabel = ['online' => 'MikroTik online', 'offline' => 'MikroTik nedostupný', 'error' => 'Chyba spojení', 'unconfigured' => 'Čeká na nastavení'][$routerStatus] ?? 'Neznámý stav';
?>
<section class="status-ribbon <?= e($routerStatus) ?>">
    <div class="status-ribbon-icon"><?= icon($routerStatus === 'online' ? 'check' : 'server') ?></div>
    <div><strong><?= e($statusLabel) ?></strong><span><?= $router ? e(($router['identity'] ?: $router['name']) . ' · ' . $router['host'] . ':' . $router['port']) : 'Nastavte připojení k RouterOS API' ?></span></div>
    <div class="status-ribbon-meta"><span>Poslední synchronizace</span><strong data-last-sync><?= e(format_datetime($router['last_sync_at'] ?? null)) ?></strong></div>
    <?php if ($auth->isAdmin()): ?><a class="button subtle" href="<?= e(url('/settings')) ?>"><?= icon('settings') ?> Nastavení</a><?php endif; ?>
</section>

<section class="metric-grid">
    <article class="metric-card blue"><span class="metric-icon"><?= icon('devices') ?></span><div><small>PŘIPOJENO</small><strong data-count-clients><?= (int) $stats['clients'] ?></strong><span>aktivních zařízení</span></div><i class="metric-pulse"></i></article>
    <article class="metric-card orange"><span class="metric-icon"><?= icon('register') ?></span><div><small>ČEKÁ NA REGISTRACI</small><strong data-count-pending><?= (int) $stats['pending'] ?></strong><span>vyžaduje pozornost</span></div></article>
    <article class="metric-card green"><span class="metric-icon"><?= icon('radio') ?></span><div><small>PŘÍSTUPOVÉ BODY</small><strong><?= (int) $stats['aps_online'] ?></strong><span>CAP online</span></div></article>
    <article class="metric-card violet"><span class="metric-icon"><?= icon('wifi') ?></span><div><small>AKTIVNÍ SÍTĚ</small><strong><?= (int) $stats['networks_enabled'] ?></strong><span>SSID vysílá</span></div></article>
</section>

<section class="panel live-panel">
    <header class="panel-header">
        <div><span class="panel-kicker"><i class="live-dot"></i> AKTUÁLNÍ STAV</span><h2>Připojená zařízení</h2><p>Klienti se aktualizují automaticky bez obnovení stránky.</p></div>
        <div class="panel-actions"><div class="search-box"><?= icon('search') ?><input type="search" placeholder="Hledat jméno, IP nebo MAC" data-table-search="live-clients"></div><a class="button subtle" href="<?= e(url('/clients')) ?>">Zobrazit vše</a></div>
    </header>
    <div class="table-wrap">
        <table class="data-table" id="live-clients" data-live-clients>
            <thead><tr><th>Zařízení</th><th>IP adresa</th><th>Síť</th><th>CAP / pásmo</th><th>Signál</th><th>Stav</th></tr></thead>
            <tbody>
            <?php if ($clients === []): ?>
                <tr class="empty-row"><td colspan="6"><span><?= icon('wifi') ?></span><strong>Zatím nejsou načtená žádná zařízení</strong><small>Po nastavení MikroTiku spustí synchronizační služba živý přehled.</small></td></tr>
            <?php endif; ?>
            <?php foreach ($clients as $client): ?>
                <?php require __DIR__ . '/partials/client-row.php'; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($jobs !== []): ?>
<section class="panel compact-panel">
    <header class="panel-header"><div><span class="panel-kicker">SYNCHRONIZACE</span><h2>Probíhající změny</h2></div></header>
    <div class="job-list">
        <?php foreach ($jobs as $job): ?><div class="job-row"><span class="job-state <?= e($job['status']) ?>"><?= icon($job['status'] === 'failed' ? 'alert' : 'refresh') ?></span><div><strong><?= e($job['progress'] ?: $job['type']) ?></strong><small><?= e($job['last_error'] ?: format_datetime($job['created_at'])) ?></small></div><span class="badge <?= e($job['status']) ?>"><?= e(['pending'=>'Čeká','running'=>'Probíhá','failed'=>'Chyba'][$job['status']] ?? $job['status']) ?></span></div><?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
