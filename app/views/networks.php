<section class="page-intro"><div><p>Konfigurace načtené z <code>/interface/wifi/configuration</code>.</p></div><div class="page-actions"><div class="summary-chip blue"><?= icon('wifi') ?><span><strong><?= count(array_unique(array_column($networks, 'ssid'))) ?></strong> Wi‑Fi sítí</span></div><?php if ($auth->isAdmin()): ?><button class="button primary" type="button" data-open-network><?= icon('plus') ?> Přidat Wi‑Fi</button><?php endif; ?></div></section>
<section class="network-grid">
<?php if ($networks === []): ?><div class="panel empty-state full"><span><?= icon('wifi') ?></span><strong>Wi‑Fi konfigurace zatím nejsou načtené</strong><p>Po první plné synchronizaci se zde objeví všechny CAPsMAN sítě.</p></div><?php endif; ?>
<?php foreach ($networks as $network): ?>
    <article class="network-card <?= $network['enabled'] ? '' : 'disabled' ?>">
        <header><span class="network-icon"><?= icon('wifi') ?></span><div><h2><?= e($network['ssid']) ?></h2><span><?= e($network['config_name']) ?></span></div><span class="badge <?= $network['enabled'] ? 'registered' : 'offline' ?>"><i></i><?= $network['enabled'] ? 'Zapnuto' : 'Vypnuto' ?></span></header>
        <div class="network-facts"><div><span>VLAN</span><strong><?= $network['vlan_id'] !== null ? (int)$network['vlan_id'] : '—' ?></strong></div><div><span>Pásmo</span><strong><?= e($network['band'] ?: 'dle profilu') ?></strong></div><div><span>Klienti</span><strong><?= (int)$network['client_count'] ?></strong></div></div>
        <?php if ($auth->isAdmin()): ?>
        <div class="network-password <?= $network['password_cipher'] === '' ? 'unavailable' : '' ?>">
            <span><?= icon('lock') ?> Heslo</span>
            <code data-network-password-value><?= $network['password_cipher'] === '' ? 'nenačteno' : '••••••••' ?></code>
            <?php if ($network['password_cipher'] !== ''): ?><button type="button" class="password-toggle" data-network-password="<?= (int) $network['id'] ?>" aria-label="Zobrazit heslo" title="Zobrazit heslo"><?= icon('eye') ?></button><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="network-tags"><?php if ($network['registration_enabled']): ?><span class="tag orange"><?= icon('register') ?> Registrace</span><?php endif; ?><span class="tag"><?= $network['managed'] ? 'Spravovaná' : 'Načtená z MikroTiku' ?></span></div>
        <footer><span class="sync-note"><?= icon('refresh') ?> <?= e(format_datetime($network['last_seen_at'])) ?></span><?php if ($auth->isAdmin()): ?><div class="table-actions"><form method="post" action="<?= e(url('/networks/toggle')) ?>" data-confirm="Opravdu chcete <?= $network['enabled'] ? 'vypnout' : 'zapnout' ?> síť <?= e($network['ssid']) ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$network['id'] ?>"><button class="switch-button <?= $network['enabled'] ? 'on' : '' ?>" title="<?= $network['enabled'] ? 'Vypnout' : 'Zapnout' ?>"><i></i></button></form><form method="post" action="<?= e(url('/networks/delete')) ?>" data-confirm="Opravdu smazat Wi‑Fi profil <?= e($network['config_name']) ?>? Profil se odstraní i z provisioning pravidel MikroTiku."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$network['id'] ?>"><button class="button danger small" type="submit" title="Smazat Wi‑Fi profil"><?= icon('trash') ?> Smazat</button></form></div><?php endif; ?></footer>
    </article>
<?php endforeach; ?>
</section>
<div class="info-banner"><?= icon('alert') ?><div><strong>Automatická konfigurace</strong><p>Nová síť se vytvoří jako slave konfigurace v odpovídajících provisioning pravidlech. U registrační sítě je výchozí VLAN registrační a schválená zařízení dostanou provozní VLAN přes Access List.</p></div></div>

<?php if ($auth->isAdmin()): ?>
<dialog class="modal" id="network-dialog">
<form method="post" action="<?= e(url('/networks')) ?>" class="modal-card">
<?= csrf_field() ?>
<header><div><span class="modal-icon"><?= icon('wifi') ?></span><h2>Nová Wi‑Fi síť</h2><p>Zadejte základní údaje, ostatní provede WiFi Manager.</p></div><button type="button" class="icon-button" data-close-dialog aria-label="Zavřít"><?= icon('close') ?></button></header>
<div class="form-grid"><label><span>Název v administraci</span><input name="name" required maxlength="80" placeholder="např. Konference"></label><label><span>SSID</span><input name="ssid" required maxlength="32" placeholder="Název sítě"></label><label class="span-2"><span>Wi‑Fi heslo</span><div class="password-field"><input type="password" name="password" required minlength="8" maxlength="63" autocomplete="new-password"><button type="button" data-toggle-password aria-label="Zobrazit heslo" title="Zobrazit heslo"><?= icon('eye') ?></button></div></label><label><span data-network-vlan-label>Provozní VLAN ID</span><input type="number" name="vlan_id" min="1" max="4094" value="<?= (int)$approvedVlan ?>" data-network-vlan data-approved-vlan="<?= (int)$approvedVlan ?>" data-registration-vlan="<?= (int)$registrationVlan ?>" required></label><label><span>Pásmo</span><select name="band"><option value="both">2,4 GHz i 5 GHz</option><option value="2ghz-ax">Pouze 2,4 GHz AX</option><option value="5ghz-ax">Pouze 5 GHz AX</option></select></label><label class="span-2 check-label"><input type="checkbox" name="registration_enabled" value="1" data-registration-network><span><strong>Vyžadovat registraci zařízení</strong><small>Nová zařízení začnou v registrační VLAN; schválená zařízení dostanou provozní VLAN přes Access List.</small></span></label></div>
<footer><button type="button" class="button subtle" data-close-dialog>Zrušit</button><button type="submit" class="button primary">Vytvořit Wi‑Fi <?= icon('check') ?></button></footer>
</form>
</dialog>
<?php endif; ?>
