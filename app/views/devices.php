<section class="page-intro">
    <div><p>Evidence schválených zařízení oddělená od samotného procesu registrace.</p></div>
    <div class="page-actions"><div class="summary-chip blue"><?= icon('devices') ?><span><strong><?= count($devices) ?></strong> zařízení</span></div><div class="search-box large"><?= icon('search') ?><input type="search" placeholder="Hledat držitele, zařízení, IP nebo MAC" data-table-search="devices-table"></div></div>
</section>

<section class="panel compact-panel">
<div class="table-wrap"><table class="data-table devices-table" id="devices-table"><thead><tr><th>Držitel</th><th>Zařízení</th><th>MAC adresa</th><th>Statická IP</th><th>Rychlost ↓ / ↑</th><th>Stav</th><th>Naposledy připojeno</th><th></th></tr></thead><tbody>
<?php if ($devices === []): ?><tr class="empty-row"><td colspan="8"><span><?= icon('devices') ?></span><strong>Zatím není evidované žádné zařízení</strong><small>Nové zařízení nejprve schvalte na stránce Registrace.</small></td></tr><?php endif; ?>
<?php foreach ($devices as $device):
    $rateDown = $device['rate_down'] ?: $settings['default_rate_down'];
    $rateUp = $device['rate_up'] ?: $settings['default_rate_up'];
    $blocked = ($device['registration_state'] ?? '') === 'blocked';
    $stateText = ['registered'=>'Registrovaný','registering'=>'Zapisuje se','pending'=>'Čeká','incomplete'=>'Neúplný','blocked'=>'Zakázaný'][$device['registration_state']] ?? $device['registration_state'];
?>
<tr data-search-row>
    <td><a class="detail-link" href="<?= e(url('/devices/detail?id=' . (int) $device['id'])) ?>"><strong><?= e($device['person_name'] ?: '—') ?></strong><small><?= e($device['person_note'] ?: 'Zobrazit historii a provoz') ?></small></a></td>
    <td><a class="detail-link" href="<?= e(url('/devices/detail?id=' . (int) $device['id'])) ?>"><strong><?= e($device['name']) ?></strong><small><?= ($device['capsman_type'] ?? 'wifi') === 'legacy' ? 'Starý CAPsMAN' : 'Nový WiFi CAPsMAN' ?><?= is_private_mac($device['mac_address']) ? ' · soukromá MAC' : '' ?></small></a></td>
    <td class="mono"><?= e($device['mac_address']) ?></td><td class="mono"><?= e($device['current_ip'] ?: '—') ?></td>
    <td><?= e($rateDown) ?> / <?= e($rateUp) ?></td>
    <td><span class="badge <?= e($device['registration_state']) ?>"><i></i><?= e($stateText) ?></span><?php if ((int) $device['online'] === 1): ?><small class="subline online-text">Právě online</small><?php endif; ?></td>
    <td><?= e(format_datetime($device['last_connected_at'])) ?></td>
    <td><?php if ($auth->isAdmin()): ?><div class="table-actions nowrap-actions">
        <button class="button subtle small" type="button" data-edit-device data-id="<?= (int) $device['id'] ?>" data-person="<?= e($device['person_name'] ?: '') ?>" data-note="<?= e($device['person_note'] ?: '') ?>" data-device="<?= e($device['name']) ?>" data-mac="<?= e($device['mac_address']) ?>" data-ip="<?= e($device['current_ip'] ?: '') ?>" data-rate-down="<?= e($rateDown) ?>" data-rate-up="<?= e($rateUp) ?>"><?= icon('edit') ?> Upravit</button>
        <form method="post" action="<?= e(url('/registrations/toggle')) ?>" data-confirm="Opravdu chcete <?= $blocked ? 'povolit' : 'zakázat' ?> zařízení <?= e($device['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>"><button class="button subtle small" type="submit"><?= icon($blocked ? 'check' : 'close') ?> <?= $blocked ? 'Povolit' : 'Zakázat' ?></button></form>
        <form method="post" action="<?= e(url('/registrations/delete')) ?>" data-confirm="Opravdu smazat registraci <?= e($device['name']) ?>? Odstraní se Access List, statický DHCP lease a Simple Queue."><?= csrf_field() ?><input type="hidden" name="device_id" value="<?= (int) $device['id'] ?>"><button class="button danger small" type="submit"><?= icon('trash') ?> Smazat</button></form>
    </div><?php endif; ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
</section>

<?php if ($auth->isAdmin()): ?>
<dialog class="modal" id="device-edit-dialog"><form method="post" action="<?= e(url('/registrations/update')) ?>" class="modal-card">
<?= csrf_field() ?><input type="hidden" name="device_id" data-edit-id>
<header><div><span class="modal-icon"><?= icon('edit') ?></span><h2>Upravit zařízení</h2><p>Změny se zapíší do Access Listu, DHCP i Simple Queue.</p></div><button type="button" class="icon-button" data-close-dialog aria-label="Zavřít"><?= icon('close') ?></button></header>
<div class="form-grid"><label class="span-2"><span>Jméno držitele</span><input name="person_name" required maxlength="120" data-edit-person></label><label class="span-2"><span>Poznámka</span><input name="note" maxlength="250" data-edit-note></label><label><span>Název zařízení</span><input name="device_name" required maxlength="120" data-edit-name></label><label><span>MAC adresa</span><input readonly class="mono" data-edit-mac></label><label><span>Statická IP</span><input name="ip_address" required class="mono" data-edit-ip></label><label><span>Rychlost ↓ / ↑</span><div class="split-input"><input name="rate_down" required data-edit-rate-down><input name="rate_up" required data-edit-rate-up></div></label></div>
<footer><button type="button" class="button subtle" data-close-dialog>Zrušit</button><button type="submit" class="button primary">Uložit do MikroTiku <?= icon('check') ?></button></footer>
</form></dialog>
<?php endif; ?>
