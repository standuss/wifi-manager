<?php
$clientName = $client['person_name'] ?: ($client['device_name'] ?: ($client['hostname'] ?: 'Neznámé zařízení'));
$status = $client['registration_status'] ?? 'pending';
$statusText = ['registered'=>'Registrovaný','pending'=>'Čeká na registraci','incomplete'=>'Neúplná registrace','blocked'=>'Blokovaný'][$status] ?? $status;
$signal = isset($client['signal_dbm']) ? (int) $client['signal_dbm'] : null;
?>
<tr data-search-row>
    <td><div class="device-cell"><span class="device-avatar <?= e($status) ?>"><?= icon('devices') ?></span><div><strong><?= e($clientName) ?></strong><small><?= e($client['mac_address']) ?></small></div></div></td>
    <td><span class="mono ip-value"><?= e($client['ip_address'] ?: '—') ?></span><small class="subline"><?= e($client['hostname'] ?: '') ?></small></td>
    <td><div class="network-cell"><?= icon('wifi') ?><div><strong><?= e($client['ssid'] ?: '—') ?></strong><small><?= $client['vlan_id'] ? 'VLAN ' . (int) $client['vlan_id'] : 'VLAN nezjištěna' ?></small></div></div></td>
    <td><strong><?= e($client['access_point_name'] ?: '—') ?></strong><small class="subline"><?= e($client['band'] ?: '') ?></small></td>
    <td><div class="signal-cell <?= e(signal_class($signal)) ?>"><span class="signal-bars"><i></i><i></i><i></i><i></i></span><strong><?= $signal !== null ? $signal . ' dBm' : '—' ?></strong></div></td>
    <td><span class="badge <?= e($status) ?>"><i></i><?= e($statusText) ?></span></td>
</tr>

