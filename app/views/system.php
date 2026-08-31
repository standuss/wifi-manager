<section class="system-overview">
    <article class="system-version-card"><span><?= icon('server') ?></span><div><small>NAINSTALOVANÁ VERZE</small><strong><?= e($currentVersion) ?></strong><p>WiFi Manager</p></div></article>
    <article class="system-version-card <?= $status['syslog']['service'] === 'active' && !$status['syslog']['conflict'] ? 'ok' : 'warn' ?>"><span><?= icon('terminal') ?></span><div><small>RSYSLOG</small><strong><?= $status['syslog']['conflict'] ? 'Konflikt portu' : e($status['syslog']['service']) ?></strong><p>TCP <?= (int) $settings['monitor_syslog_tcp_port'] ?> <?= $status['syslog']['listeners']['tcp']['listening'] ? '✓' : '×' ?> · UDP <?= (int) $settings['monitor_syslog_udp_port'] ?> <?= $status['syslog']['listeners']['udp']['listening'] ? '✓' : '×' ?></p></div></article>
    <article class="system-version-card <?= $status['netflow']['service'] === 'active' && !$status['netflow']['conflict'] ? 'ok' : 'warn' ?>"><span><?= icon('flow') ?></span><div><small>NFCAPD</small><strong><?= $status['netflow']['conflict'] ? 'Konflikt portu' : e($status['netflow']['service']) ?></strong><p>IPFIX UDP <?= (int) $settings['monitor_netflow_port'] ?> <?= $status['netflow']['listener']['listening'] ? '✓' : '×' ?> · poslední data <?= e(format_datetime($status['netflow']['newest_at'])) ?></p></div></article>
    <article class="system-version-card <?= $status['retention']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('archive') ?></span><div><small>RETENCE</small><strong><?= e($status['retention']['service']) ?></strong><p><?= (int) $settings['monitor_retention_days'] ?> dní</p></div></article>
</section>

<?php if ($status['syslog']['conflict'] || $status['netflow']['conflict']): ?><div class="info-banner warning"><?= icon('alert') ?><div><strong>Některý monitorovací port používá jiná služba</strong><p><?php if ($status['syslog']['conflict']): ?>Syslog: <?= e($status['syslog']['listeners']['tcp']['owner'] ?: $status['syslog']['listeners']['udp']['owner']) ?>. <?php endif; ?><?php if ($status['netflow']['conflict']): ?>Port <?= (int) $status['netflow']['listener']['port'] ?> používá <?= e($status['netflow']['listener']['owner'] ?: 'jiný proces') ?>. <?php if ($status['netflow']['conflicting_units'] !== []): ?>Aktivní konfliktní jednotky: <?= e(implode(', ', $status['netflow']['conflicting_units'])) ?>.<?php endif; ?><?php endif; ?> Změňte port nebo konfliktní službu zastavte.</p></div></div><?php endif; ?>

<?php if ($applyStatus): ?><div class="update-status <?= e($applyStatus['state'] ?? '') ?> system-apply-status"><strong><?= e($applyStatus['message'] ?? 'Stav použití systémového nastavení') ?></strong><span><?= e(format_datetime($applyStatus['updated_at'] ?? null)) ?></span></div><?php endif; ?>

<form method="post" action="<?= e(url('/system/monitoring')) ?>" class="panel settings-section system-form">
<?= csrf_field() ?>
<header class="panel-header"><div><span class="panel-kicker">MONITOROVACÍ SLUŽBY A ROUTEROS</span><h2>Syslog a IPFIX včetně NAT</h2><p>Lokální adresa určuje, kde server poslouchá. Cílová adresa je ta, kterou musí vidět MikroTik; při NAT může jít o veřejnou IP a jiné přesměrované porty.</p></div><button class="button primary" type="submit"><?= icon('settings') ?> Uložit a nastavit MikroTik</button></header>
<div class="settings-subtitle"><strong>Místní sběrač</strong><span>Porty na tomto serveru</span></div>
<div class="form-grid settings-grid">
    <label class="span-2"><span>Naslouchací IP adresa serveru</span><input name="monitor_listen_address" value="<?= e($settings['monitor_listen_address']) ?>" class="mono" required><small>Např. 192.168.1.192 nebo 0.0.0.0</small></label>
    <label><span>Syslog/CEF TCP port</span><input type="number" name="monitor_syslog_tcp_port" value="<?= (int) $settings['monitor_syslog_tcp_port'] ?>" min="1" max="65535" required></label>
    <label><span>Legacy syslog UDP port</span><input type="number" name="monitor_syslog_udp_port" value="<?= (int) $settings['monitor_syslog_udp_port'] ?>" min="1" max="65535" required></label>
    <label><span>IPFIX UDP port</span><input type="number" name="monitor_netflow_port" value="<?= (int) $settings['monitor_netflow_port'] ?>" min="1" max="65535" required></label>
    <label><span>Retence ve dnech</span><input type="number" name="monitor_retention_days" value="<?= (int) $settings['monitor_retention_days'] ?>" min="30" max="3650" required></label>
    <label><span>Limit syslogu v GiB</span><input type="number" name="monitor_syslog_max_gib" value="<?= (int) $settings['monitor_syslog_max_gib'] ?>" min="1" max="4096" required></label>
    <label><span>Limit IPFIX v GiB</span><input type="number" name="monitor_netflow_max_gib" value="<?= (int) $settings['monitor_netflow_max_gib'] ?>" min="1" max="16384" required></label>
</div>
<div class="settings-subtitle nat"><strong>Cíl nastavený do MikroTiku</strong><span>Při NAT přesměrujte tyto veřejné porty na místní porty výše</span></div>
<div class="form-grid settings-grid router-target-grid">
    <label class="span-2"><span>IP adresa viditelná z MikroTiku</span><input name="monitor_router_target_address" value="<?= e($settings['monitor_router_target_address']) ?>" class="mono" placeholder="203.0.113.10"><small>Může jít o veřejnou adresu NAT. Prázdná hodnota ponechá RouterOS beze změny.</small></label>
    <label><span>Cílový syslog port</span><input type="number" name="monitor_router_syslog_port" value="<?= (int) $settings['monitor_router_syslog_port'] ?>" min="1" max="65535" required></label>
    <label><span>Přenos syslogu</span><select name="monitor_router_syslog_transport"><option value="tcp" <?= $settings['monitor_router_syslog_transport'] === 'tcp' ? 'selected' : '' ?>>TCP / CEF – doporučeno</option><option value="udp" <?= $settings['monitor_router_syslog_transport'] === 'udp' ? 'selected' : '' ?>>UDP / syslog</option></select></label>
    <label><span>Cílový IPFIX UDP port</span><input type="number" name="monitor_router_netflow_port" value="<?= (int) $settings['monitor_router_netflow_port'] ?>" min="1" max="65535" required></label>
</div>
<div class="nat-map"><span>MikroTik → <strong><?= e($settings['monitor_router_target_address'] ?: 'cílová adresa') ?>:<?= (int) $settings['monitor_router_syslog_port'] ?></strong> → NAT → <strong><?= e($settings['monitor_listen_address']) ?>:<?= (int) ($settings['monitor_router_syslog_transport'] === 'tcp' ? $settings['monitor_syslog_tcp_port'] : $settings['monitor_syslog_udp_port']) ?></strong></span><span>IPFIX → <strong>:<?= (int) $settings['monitor_router_netflow_port'] ?></strong> → NAT → <strong>:<?= (int) $settings['monitor_netflow_port'] ?></strong></span></div>
<div class="storage-budget"><div><span>Syslog</span><strong><?= (int) $settings['monitor_syslog_max_gib'] ?> GiB</strong></div><div><span>IPFIX</span><strong><?= (int) $settings['monitor_netflow_max_gib'] ?> GiB</strong></div><div><span>Volné místo</span><strong><?= e(format_bytes($status['disk']['free'])) ?></strong></div></div>
</form>

<section class="service-settings-grid">
<form method="post" action="<?= e(url('/system/smtp')) ?>" class="panel settings-section system-form">
    <?= csrf_field() ?><header class="panel-header"><div><span class="panel-kicker">E-MAILOVÁ OZNÁMENÍ</span><h2>SMTP server</h2><p>Podporuje SMTP bez ověření, AUTH LOGIN, STARTTLS i přímé TLS.</p></div><button class="button primary" type="submit">Uložit SMTP</button></header>
    <div class="form-grid smtp-grid">
        <label class="checkbox-setting"><input type="checkbox" name="smtp_enabled" value="1" <?= $settings['smtp_enabled'] === '1' ? 'checked' : '' ?>><span>Odesílání e-mailů aktivní</span></label>
        <label><span>SMTP server</span><input name="smtp_host" value="<?= e($settings['smtp_host']) ?>" placeholder="smtp.example.cz"></label>
        <label><span>Port</span><input type="number" name="smtp_port" value="<?= (int) $settings['smtp_port'] ?>" min="1" max="65535"></label>
        <label><span>Šifrování</span><select name="smtp_encryption"><option value="none" <?= $settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Bez šifrování</option><option value="starttls" <?= $settings['smtp_encryption'] === 'starttls' ? 'selected' : '' ?>>STARTTLS</option><option value="tls" <?= $settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS od spojení</option></select></label>
        <label class="checkbox-setting"><input type="checkbox" name="smtp_auth_enabled" value="1" <?= $settings['smtp_auth_enabled'] === '1' ? 'checked' : '' ?>><span>Server vyžaduje přihlášení</span></label>
        <label><span>Uživatelské jméno</span><input name="smtp_username" value="<?= e($settings['smtp_username']) ?>" autocomplete="off"></label>
        <label><span>Heslo</span><input type="password" name="smtp_password" autocomplete="new-password" placeholder="Ponechat beze změny"></label>
        <label><span>E-mail odesílatele</span><input type="email" name="smtp_from_email" value="<?= e($settings['smtp_from_email']) ?>"></label>
        <label><span>Jméno odesílatele</span><input name="smtp_from_name" value="<?= e($settings['smtp_from_name']) ?>"></label>
        <label><span>Timeout v sekundách</span><input type="number" name="smtp_timeout_seconds" value="<?= (int) $settings['smtp_timeout_seconds'] ?>" min="3" max="60"></label>
    </div>
</form>
<section class="panel settings-section system-form smtp-test-card">
    <header class="panel-header"><div><span class="panel-kicker">OVĚŘENÍ SMTP</span><h2>Testovací zpráva</h2><p>Nejdříve SMTP nastavení uložte a potom odešlete test.</p></div></header>
    <form method="post" action="<?= e(url('/system/smtp-test')) ?>" class="inline-service-form"><?= csrf_field() ?><label><span>E-mail příjemce</span><input type="email" name="recipient" required placeholder="vas@email.cz"></label><button class="button subtle" type="submit">Odeslat test</button></form>
    <div class="info-banner"><?= icon('lock') ?><div><strong>Heslo se nezobrazuje</strong><p>Je uložené šifrovaně a používá ho pouze serverový worker.</p></div></div>
</section>
</section>

<section class="panel settings-section system-form">
    <header class="panel-header"><div><span class="panel-kicker">ZÁLOHY ROUTEROS</span><h2>Šifrované zálohy MikroTiku</h2><p>Router vytvoří binární AES-SHA256 zálohu, WiFi Manager ji stáhne a kopii z routeru odstraní.</p></div></header>
    <form method="post" action="<?= e(url('/system/backup-settings')) ?>" class="form-grid backup-settings-form"><?= csrf_field() ?>
        <label class="checkbox-setting"><input type="checkbox" name="backup_enabled" value="1" <?= $settings['backup_enabled'] === '1' ? 'checked' : '' ?>><span>Automatické zálohy aktivní</span></label>
        <label><span>Interval ve dnech</span><input type="number" name="backup_interval_days" value="<?= (int) $settings['backup_interval_days'] ?>" min="1" max="365"></label>
        <label><span>Počet uchovaných záloh</span><input type="number" name="backup_retention_count" value="<?= (int) $settings['backup_retention_count'] ?>" min="1" max="100"></label>
        <label><span>Heslo šifrování zálohy</span><input type="password" name="backup_password" minlength="8" autocomplete="new-password" placeholder="Ponechat beze změny"></label>
        <button class="button primary" type="submit">Uložit zálohování</button>
    </form>
    <form method="post" action="<?= e(url('/system/backup-now')) ?>" class="backup-now-form"><?= csrf_field() ?><label><span>Router</span><select name="router_id" required><?php foreach ($routers as $router): ?><option value="<?= (int) $router['id'] ?>"><?= e($router['identity']) ?> · <?= e($router['status']) ?></option><?php endforeach; ?></select></label><button class="button subtle" type="submit" <?= $routers === [] ? 'disabled' : '' ?>>Vytvořit zálohu nyní</button></form>
    <div class="table-wrap"><table class="data-table backup-table"><thead><tr><th>Vytvořeno</th><th>Router</th><th>Soubor</th><th>Verze</th><th>Stav</th><th></th></tr></thead><tbody>
    <?php if ($backupRows === []): ?><tr class="empty-row"><td colspan="6"><strong>Zatím nebyla vytvořena žádná záloha.</strong></td></tr><?php endif; ?>
    <?php foreach ($backupRows as $backup): ?><tr><td><?= e(format_datetime($backup['created_at'])) ?></td><td><?= e($backup['router_name']) ?></td><td class="mono"><?= e($backup['filename']) ?><small class="subline"><?= $backup['size_bytes'] ? e(format_bytes($backup['size_bytes'])) : e($backup['error'] ?? '') ?></small></td><td><?= e($backup['routeros_version'] ?: '—') ?></td><td><span class="badge <?= e($backup['status']) ?>"><i></i><?= e($backup['status']) ?></span></td><td><?php if ($backup['status'] === 'done'): ?><a class="button subtle small" href="<?= e(url('/system/backup-download?id=' . (int) $backup['id'])) ?>">Stáhnout</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel settings-section system-form">
<header class="panel-header"><div><span class="panel-kicker">GITHUB RELEASES</span><h2>Aktualizace aplikace</h2><p>Zdroj aktualizací je pevně zabudovaný. Není potřeba GitHub účet ani token.</p></div><a class="button subtle" href="https://github.com/standuss/wifi-manager" target="_blank" rel="noopener"><?= icon('github') ?> Otevřít GitHub</a></header>
<div class="release-details"><div><span>Repozitář</span><strong class="mono"><?= e($settings['update_github_repository']) ?></strong></div><div><span>Kanál</span><strong>Stabilní verze</strong></div><div><span>Instalace</span><strong>Vždy ručně potvrzená</strong></div></div>
</section>
<section class="panel update-panel"><header class="panel-header"><div><span class="panel-kicker">DOSTUPNÁ VERZE</span><h2><?= $latest ? e($latest['name']) : 'Kontrola aktualizací' ?></h2><p><?php if ($releaseError): ?><?= e($releaseError) ?><?php elseif (!$latest): ?>Kliknutím načtěte poslední stabilní release.<?php elseif (version_compare($latest['version'], $currentVersion, '>')): ?>Novější verze <?= e($latest['version']) ?> je připravená.<?php else: ?>Používáte aktuální verzi.<?php endif; ?></p></div><div class="panel-actions"><a class="button subtle" href="<?= e(url('/system?check=1')) ?>"><?= icon('refresh') ?> Zkontrolovat</a><?php if ($latest && $latest['installable'] && version_compare($latest['version'], $currentVersion, '>')): ?><form method="post" action="<?= e(url('/system/update-install')) ?>"><?= csrf_field() ?><button class="button primary" type="submit"><?= icon('download') ?> Aktualizovat na <?= e($latest['version']) ?></button></form><?php endif; ?></div></header>
<?php if ($latest): ?><div class="release-details"><div><span>Verze</span><strong><?= e($latest['version']) ?></strong></div><div><span>Vydáno</span><strong><?= e(format_datetime($latest['published_at'])) ?></strong></div><div><span>Balíček</span><strong><?= $latest['installable'] ? e(format_bytes($latest['asset_size'])) : 'chybí' ?></strong></div><a href="<?= e($latest['html_url']) ?>" target="_blank" rel="noopener">Poznámky na GitHubu <?= icon('arrow-right') ?></a></div><?php endif; ?>
<?php if ($updateStatus): ?><div class="update-status <?= e($updateStatus['state'] ?? '') ?>"><strong><?= e($updateStatus['message'] ?? 'Stav aktualizace') ?></strong><span><?= e(format_datetime($updateStatus['updated_at'] ?? null)) ?></span></div><?php endif; ?>
</section>
