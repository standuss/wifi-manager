<section class="page-intro">
    <div><p>Schválení zařízení vytvoří Access List, statický DHCP lease a omezení rychlosti.</p></div>
    <div class="summary-chip orange"><?= icon('register') ?><span><strong><?= count($pending) ?></strong> čeká nebo se zapisuje</span></div>
</section>

<div class="two-column-layout">
<section class="panel">
    <header class="panel-header"><div><span class="panel-kicker">VYŽADUJE POZORNOST</span><h2>Čekající zařízení</h2><p>Zařízení z registrační VLAN <?= e($settings['registration_vlan_id']) ?>.</p></div></header>
    <div class="card-list">
    <?php if ($pending === []): ?><div class="empty-state"><span><?= icon('check') ?></span><strong>Všechna zařízení jsou vyřešená</strong><p>Nově připojené zařízení se zde objeví automaticky.</p></div><?php endif; ?>
    <?php foreach ($pending as $client): ?>
        <article class="pending-card">
            <span class="device-avatar pending large"><?= icon('devices') ?></span>
            <div class="pending-main"><strong><?= e($client['hostname'] ?: 'Neznámé zařízení') ?></strong><span class="mono"><?= e($client['mac_address']) ?></span><small><?= e($client['ssid']) ?> · <?= e($client['access_point_name'] ?: 'CAP nezjištěn') ?></small><?php if (is_private_mac((string) $client['mac_address'])): ?><span class="private-mac-warning"><?= icon('alert') ?> Soukromá náhodná MAC</span><?php endif; ?></div>
            <div class="pending-signal"><span class="signal-value <?= e(signal_class(isset($client['signal_dbm']) ? (int)$client['signal_dbm'] : null)) ?>"><?= e($client['signal_dbm'] ?? '—') ?> dBm</span><small><?= e($client['ip_address'] ?: 'Bez IP') ?></small></div>
            <?php if (($client['device_registration_state'] ?? '') === 'registering'): ?><span class="badge running"><i></i>Zapisuje se</span><?php elseif ($auth->isAdmin()): ?><button class="button primary" type="button" data-open-register data-mac="<?= e($client['mac_address']) ?>" data-device="<?= e($client['hostname'] ?: 'Telefon') ?>" data-private-mac="<?= is_private_mac((string) $client['mac_address']) ? '1' : '0' ?>">Registrovat</button><?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</section>

<aside class="panel side-panel">
    <header class="panel-header"><div><span class="panel-kicker">VÝCHOZÍ PRAVIDLA</span><h2>Registrace zařízení</h2></div></header>
    <dl class="settings-summary">
        <div><dt>Provozní VLAN</dt><dd><span class="vlan-pill">VLAN <?= e($settings['approved_vlan_id']) ?></span></dd></div>
        <div><dt>Statický rozsah</dt><dd class="mono"><?= e($settings['static_ip_start']) ?>–<?= e(substr($settings['static_ip_end'], strrpos($settings['static_ip_end'], '.') + 1)) ?></dd></div>
        <div><dt>Výchozí rychlost</dt><dd><?= e($settings['default_rate_down']) ?> / <?= e($settings['default_rate_up']) ?></dd></div>
        <div><dt>DHCP server</dt><dd class="mono"><?= e($settings['approved_dhcp_server']) ?></dd></div>
        <div><dt>Zařízení na osobu</dt><dd><?= (int)$settings['max_devices_per_person'] ?></dd></div>
    </dl>
    <?php if ($auth->isAdmin()): ?><a href="<?= e(url('/settings')) ?>" class="button subtle wide"><?= icon('settings') ?> Upravit pravidla</a><?php endif; ?>
</aside>
</div>

<?php if ($auth->isAdmin()): ?>
<dialog class="modal" id="register-dialog">
    <form method="post" action="<?= e(url('/registrations')) ?>" class="modal-card">
        <?= csrf_field() ?>
        <header><div><span class="modal-icon"><?= icon('register') ?></span><h2>Registrovat zařízení</h2><p>WiFi Manager provede všechny kroky automaticky.</p></div><button type="button" class="icon-button" data-close-dialog aria-label="Zavřít"><?= icon('close') ?></button></header>
        <div class="form-grid">
            <label class="span-2"><span>Jméno držitele</span><input name="person_name" required maxlength="120" placeholder="např. Jan Novák"></label>
            <label class="span-2"><span>Poznámka <em>volitelné</em></span><input name="note" maxlength="250" placeholder="např. služební telefon"></label>
            <label><span>Název zařízení</span><input name="device_name" required maxlength="120" data-register-device></label>
            <label><span>MAC adresa</span><input name="mac_address" required readonly class="mono" data-register-mac></label>
            <label><span>Statická IP</span><input name="ip_address" required value="<?= e($suggestedIp) ?>" class="mono"></label>
            <label><span>Rychlost ↓ / ↑</span><div class="split-input"><input name="rate_down" value="<?= e($settings['default_rate_down']) ?>" required><input name="rate_up" value="<?= e($settings['default_rate_up']) ?>" required></div></label>
        </div>
        <div class="private-mac-notice" data-private-mac-notice hidden><?= icon('alert') ?><span><strong>Zařízení používá soukromou MAC adresu.</strong> Pokud si vytvoří novou náhodnou MAC, bude potřeba registraci zopakovat.</span></div>
        <div class="operation-preview"><span><?= icon('check') ?> Access List</span><span><?= icon('check') ?> VLAN <?= e($settings['approved_vlan_id']) ?></span><span><?= icon('check') ?> Statický DHCP</span><span><?= icon('check') ?> Simple Queue</span></div>
        <footer><button type="button" class="button subtle" data-close-dialog>Zrušit</button><button type="submit" class="button primary">Schválit a registrovat <?= icon('check') ?></button></footer>
    </form>
</dialog>

<?php endif; ?>
