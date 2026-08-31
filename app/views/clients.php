<section class="page-intro"><div><p>Živý stav všech zařízení připojených přes CAPsMAN.</p></div><div class="search-box large"><?= icon('search') ?><input type="search" placeholder="Hledat podle jména, IP, MAC nebo SSID" data-table-search="clients-table"></div></section>
<section class="panel">
    <div class="table-wrap">
        <table class="data-table" id="clients-table">
            <thead><tr><th>Zařízení / držitel</th><th>IP adresa</th><th>Wi‑Fi síť</th><th>CAP / pásmo</th><th>Signál</th><th>Provoz</th><th>Stav</th><th></th></tr></thead>
            <tbody>
            <?php if ($clients === []): ?><tr class="empty-row"><td colspan="8"><span><?= icon('devices') ?></span><strong>Žádná připojená zařízení</strong><small>Data se objeví po první úspěšné synchronizaci.</small></td></tr><?php endif; ?>
            <?php foreach ($clients as $client): ?>
                <?php
                $clientName = $client['person_name'] ?: ($client['device_name'] ?: ($client['hostname'] ?: 'Neznámé zařízení'));
                $signal = isset($client['signal_dbm']) ? (int)$client['signal_dbm'] : null;
                $status = $client['registration_status'];
                $rxMbps = ((int)($client['rx_bps'] ?? 0)) / 1000000;
                $txMbps = ((int)($client['tx_bps'] ?? 0)) / 1000000;
                ?>
                <tr data-search-row>
                    <td><div class="device-cell"><span class="device-avatar <?= e($status) ?>"><?= icon('devices') ?></span><div><strong><?= e($clientName) ?></strong><small><?= e($client['mac_address']) ?></small></div></div></td>
                    <td><span class="mono ip-value"><?= e($client['ip_address'] ?: '—') ?></span><small class="subline"><?= e($client['hostname'] ?: '') ?></small></td>
                    <td><div class="network-cell"><?= icon('wifi') ?><div><strong><?= e($client['ssid'] ?: '—') ?></strong><small><?= $client['vlan_id'] ? 'VLAN '.(int)$client['vlan_id'] : 'VLAN —' ?></small></div></div></td>
                    <td><strong><?= e($client['access_point_name'] ?: '—') ?></strong><small class="subline"><?= e($client['band'] ?: '') ?></small></td>
                    <td><div class="signal-cell <?= e(signal_class($signal)) ?>"><span class="signal-bars"><i></i><i></i><i></i><i></i></span><strong><?= $signal !== null ? $signal.' dBm' : '—' ?></strong></div></td>
                    <td><strong>↓ <?= number_format($rxMbps, 1, ',', ' ') ?> Mb/s</strong><small class="subline">↑ <?= number_format($txMbps, 1, ',', ' ') ?> Mb/s</small></td>
                    <td><span class="badge <?= e($status) ?>"><i></i><?= e(['registered'=>'Registrovaný','pending'=>'Čeká','incomplete'=>'Neúplný','blocked'=>'Blokovaný'][$status] ?? $status) ?></span></td>
                    <td><?php if ($auth->isAdmin()): ?><div class="table-actions nowrap-actions"><form method="post" action="<?= e(url('/clients/disconnect')) ?>" data-confirm="Odpojit aktuální Wi‑Fi spojení zařízení <?= e($clientName) ?>? Klient se může hned připojit znovu."><?= csrf_field() ?><input type="hidden" name="mac_address" value="<?= e($client['mac_address']) ?>"><button class="button subtle small" type="submit"><?= icon('close') ?> Odpojit</button></form><?php if (!empty($client['device_id']) && ($client['device_state'] ?? '') !== 'blocked'): ?><form method="post" action="<?= e(url('/registrations/toggle')) ?>" data-confirm="Opravdu zablokovat zařízení <?= e($clientName) ?>? Aktuální spojení bude odpojeno a klient se znovu nepřipojí."><?= csrf_field() ?><input type="hidden" name="device_id" value="<?= (int) $client['device_id'] ?>"><input type="hidden" name="return_to" value="/clients"><button class="button danger small" type="submit"><?= icon('lock') ?> Zablokovat</button></form><?php endif; ?></div><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
