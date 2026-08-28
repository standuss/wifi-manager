<section class="system-overview">
    <article class="system-version-card"><span><?= icon('server') ?></span><div><small>NAINSTALOVANÁ VERZE</small><strong><?= e($currentVersion) ?></strong><p>WiFi Manager</p></div></article>
    <article class="system-version-card <?= $status['syslog']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('terminal') ?></span><div><small>RSYSLOG</small><strong><?= e($status['syslog']['service']) ?></strong><p>TCP <?= (int)$settings['monitor_syslog_tcp_port'] ?> · UDP <?= (int)$settings['monitor_syslog_udp_port'] ?></p></div></article>
    <article class="system-version-card <?= $status['netflow']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('flow') ?></span><div><small>NFCAPD</small><strong><?= e($status['netflow']['service']) ?></strong><p>IPFIX UDP <?= (int)$settings['monitor_netflow_port'] ?></p></div></article>
    <article class="system-version-card <?= $status['retention']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('archive') ?></span><div><small>RETENCE</small><strong><?= e($status['retention']['service']) ?></strong><p><?= (int)$settings['monitor_retention_days'] ?> dní</p></div></article>
</section>

<?php if ($applyStatus): ?><div class="update-status <?= e($applyStatus['state'] ?? '') ?> system-apply-status"><strong><?= e($applyStatus['message'] ?? 'Stav použití systémového nastavení') ?></strong><span><?= e(format_datetime($applyStatus['updated_at'] ?? null)) ?></span></div><?php endif; ?>

<form method="post" action="<?= e(url('/system/monitoring')) ?>" class="panel settings-section system-form">
<?= csrf_field() ?>
<header class="panel-header"><div><span class="panel-kicker">MONITOROVACÍ SLUŽBY</span><h2>Sběr a dlouhodobé uložení dat</h2><p>Změny po uložení bezpečně aplikuje samostatná root služba. Webový proces systémové soubory neupravuje.</p></div><button class="button primary" type="submit"><?= icon('settings') ?> Uložit a aplikovat</button></header>
<div class="form-grid settings-grid">
    <label class="span-2"><span>Naslouchací IP adresa serveru</span><input name="monitor_listen_address" value="<?= e($settings['monitor_listen_address']) ?>" class="mono" required></label>
    <label><span>Syslog/CEF TCP port</span><input type="number" name="monitor_syslog_tcp_port" value="<?= (int)$settings['monitor_syslog_tcp_port'] ?>" min="1" max="65535" required></label>
    <label><span>Legacy syslog UDP port</span><input type="number" name="monitor_syslog_udp_port" value="<?= (int)$settings['monitor_syslog_udp_port'] ?>" min="1" max="65535" required></label>
    <label><span>IPFIX UDP port</span><input type="number" name="monitor_netflow_port" value="<?= (int)$settings['monitor_netflow_port'] ?>" min="1" max="65535" required></label>
    <label><span>Retence ve dnech</span><input type="number" name="monitor_retention_days" value="<?= (int)$settings['monitor_retention_days'] ?>" min="30" max="3650" required></label>
    <label><span>Limit syslogu v GiB</span><input type="number" name="monitor_syslog_max_gib" value="<?= (int)$settings['monitor_syslog_max_gib'] ?>" min="1" max="4096" required></label>
    <label><span>Limit IPFIX v GiB</span><input type="number" name="monitor_netflow_max_gib" value="<?= (int)$settings['monitor_netflow_max_gib'] ?>" min="1" max="16384" required></label>
</div>
<div class="storage-budget"><div><span>Syslog</span><strong><?= (int)$settings['monitor_syslog_max_gib'] ?> GiB</strong></div><div><span>IPFIX</span><strong><?= (int)$settings['monitor_netflow_max_gib'] ?> GiB</strong></div><div><span>Volné místo</span><strong><?= e(format_bytes($status['disk']['free'])) ?></strong></div></div>
</form>

<section class="panel settings-section system-form">
<header class="panel-header"><div><span class="panel-kicker">GITHUB RELEASES</span><h2>Aktualizace aplikace</h2><p>Zdroj aktualizací je bezpečně zabudovaný v aplikaci. Není potřeba GitHub účet, token ani ruční nastavení repozitáře.</p></div><a class="button subtle" href="https://github.com/standuss/wifi-manager" target="_blank" rel="noopener"><?= icon('github') ?> Otevřít GitHub</a></header>
<div class="release-details"><div><span>Repozitář</span><strong class="mono"><?= e($settings['update_github_repository']) ?></strong></div><div><span>Kanál</span><strong>Stabilní verze</strong></div><div><span>Instalace</span><strong>Vždy ručně potvrzená</strong></div></div>
</section>

<section class="panel update-panel"><header class="panel-header"><div><span class="panel-kicker">DOSTUPNÁ VERZE</span><h2><?= $latest ? e($latest['name']) : 'Kontrola aktualizací' ?></h2><p><?php if ($releaseError): ?><?= e($releaseError) ?><?php elseif (!$latest): ?>Kliknutím načtěte poslední stabilní release.<?php elseif (version_compare($latest['version'],$currentVersion,'>')): ?>Novější verze <?= e($latest['version']) ?> je připravená.<?php else: ?>Používáte aktuální verzi.<?php endif; ?></p></div><div class="panel-actions"><a class="button subtle" href="<?= e(url('/system?check=1')) ?>"><?= icon('refresh') ?> Zkontrolovat</a><?php if ($latest && $latest['installable'] && version_compare($latest['version'],$currentVersion,'>')): ?><form method="post" action="<?= e(url('/system/update-install')) ?>"><?= csrf_field() ?><button class="button primary" type="submit"><?= icon('download') ?> Aktualizovat na <?= e($latest['version']) ?></button></form><?php endif; ?></div></header>
<?php if ($latest): ?><div class="release-details"><div><span>Verze</span><strong><?= e($latest['version']) ?></strong></div><div><span>Vydáno</span><strong><?= e(format_datetime($latest['published_at'])) ?></strong></div><div><span>Balíček</span><strong><?= $latest['installable'] ? e(format_bytes($latest['asset_size'])) : 'chybí' ?></strong></div><a href="<?= e($latest['html_url']) ?>" target="_blank" rel="noopener">Poznámky na GitHubu <?= icon('arrow-right') ?></a></div><?php endif; ?>
<?php if ($updateStatus): ?><div class="update-status <?= e($updateStatus['state'] ?? '') ?>"><strong><?= e($updateStatus['message'] ?? 'Stav aktualizace') ?></strong><span><?= e(format_datetime($updateStatus['updated_at'] ?? null)) ?></span></div><?php endif; ?>
</section>

<div class="info-banner"><?= icon('lock') ?><div><strong>Bezpečný model oprávnění</strong><p>Apache a PHP běží bez práv roota. Instalaci provádí omezená systemd služba až po ověření původu release balíčku a shody s pevně povoleným repozitářem.</p></div></div>
