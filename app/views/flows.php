<section class="archive-status-grid">
    <article class="archive-status-card <?= $status['netflow']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('flow') ?></span><div><small>SBĚR IPFIX</small><strong><?= e($status['netflow']['service'] === 'active' ? 'Aktivní' : 'Neaktivní') ?></strong><p>Poslední data <?= e(format_datetime($status['netflow']['newest_at'])) ?></p></div></article>
    <article class="archive-status-card"><span><?= icon('archive') ?></span><div><small>VELIKOST ARCHIVU</small><strong><?= e(format_bytes($status['netflow']['bytes'])) ?></strong><p>Binární komprimované nfdump soubory</p></div></article>
    <article class="archive-status-card"><span><?= icon('database') ?></span><div><small>VOLNÉ MÍSTO</small><strong><?= e(format_bytes($status['disk']['free'])) ?></strong><p>z <?= e(format_bytes($status['disk']['total'])) ?></p></div></article>
</section>

<?php if (!$status['netflow']['readable'] || !$status['netflow']['nfdump']): ?><div class="info-banner warning"><?= icon('alert') ?><div><strong>Sběrač IPFIX zatím není připravený</strong><p>Instalační modul doplní nfdump, službu nfcapd i automatickou retenci.</p></div></div><?php endif; ?>
<?php if ($error): ?><div class="toast error"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

<form method="get" action="<?= e(url('/flows')) ?>" class="panel archive-filters">
    <header class="panel-header"><div><span class="panel-kicker">IPFIX / NETFLOW</span><h2>Vyhledat komunikaci</h2><p>IP adresu lze spojit s historickou evidencí zařízení a držitele.</p></div><button class="button primary" type="submit"><?= icon('search') ?> Vyhledat</button></header>
    <div class="archive-filter-grid">
        <label><span>Od</span><input type="datetime-local" name="from" value="<?= e($filters['from']) ?>" required></label>
        <label><span>Do</span><input type="datetime-local" name="to" value="<?= e($filters['to']) ?>" required></label>
        <label><span>IP adresa</span><input name="ip" value="<?= e($filters['ip']) ?>" class="mono" placeholder="zdrojová nebo cílová"></label>
        <label><span>Port</span><input type="number" name="port" value="<?= e($filters['port']) ?>" min="1" max="65535" placeholder="443"></label>
        <label><span>Protokol</span><select name="protocol"><option value="">Všechny</option><?php foreach (['tcp','udp','icmp','icmp6','gre','esp'] as $protocol): ?><option value="<?= e($protocol) ?>" <?= $filters['protocol'] === $protocol ? 'selected' : '' ?>><?= e(strtoupper($protocol)) ?></option><?php endforeach; ?></select></label>
        <label><span>Maximum záznamů</span><select name="limit"><?php foreach ([100,200,500] as $limit): ?><option value="<?= $limit ?>" <?= (int)$filters['limit'] === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php endforeach; ?></select></label>
    </div>
</form>

<section class="flow-summary-grid">
    <article><span>Toky ve výpisu</span><strong><?= number_format((int) ($result['summary']['flows'] ?? 0), 0, ',', ' ') ?></strong></article>
    <article><span>Přenesená data</span><strong><?= e(format_bytes($result['summary']['bytes'] ?? 0)) ?></strong></article>
    <article><span>Pakety</span><strong><?= number_format((int) ($result['summary']['packets'] ?? 0), 0, ',', ' ') ?></strong></article>
    <article><span>Unikátní IP adresy</span><strong><?= number_format((int) ($result['summary']['endpoints'] ?? 0), 0, ',', ' ') ?></strong></article>
</section>

<section class="panel archive-results"><header class="panel-header"><div><span class="panel-kicker">VÝSLEDKY</span><h2><?= count($result['rows']) ?> síťových toků</h2></div><?php if ($result['truncated']): ?><span class="badge pending"><i></i>výpis zkrácen</span><?php endif; ?></header><div class="table-wrap"><table class="data-table flow-table"><thead><tr><th>Začátek</th><th>Zdroj</th><th></th><th>Cíl</th><th>Protokol</th><th>Objem</th></tr></thead><tbody>
<?php if ($result['rows'] === []): ?><tr class="empty-row"><td colspan="6"><span><?= icon('flow') ?></span><strong>Žádné odpovídající toky</strong><small>Upravte období nebo filtry.</small></td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $row): ?>
<tr><td class="mono"><?= e(format_datetime($row['first_at'])) ?><small class="subline">do <?= e(format_datetime($row['last_at'])) ?></small></td>
<td><strong class="mono"><?= e($row['source_ip']) ?><?= $row['source_port'] ? ':'.(int)$row['source_port'] : '' ?></strong><?php if ($row['source_identity']): ?><small class="subline"><?= e($row['source_identity']['name']) ?><?= $row['source_identity']['person'] ? ' · '.e($row['source_identity']['person']) : '' ?></small><?php elseif ($row['source_mac']): ?><small class="subline mono"><?= e($row['source_mac']) ?></small><?php endif; ?></td>
<td class="flow-arrow"><?= icon('arrow-right') ?></td>
<td><strong class="mono"><?= e($row['destination_ip']) ?><?= $row['destination_port'] ? ':'.(int)$row['destination_port'] : '' ?></strong><?php if ($row['destination_identity']): ?><small class="subline"><?= e($row['destination_identity']['name']) ?><?= $row['destination_identity']['person'] ? ' · '.e($row['destination_identity']['person']) : '' ?></small><?php elseif ($row['destination_mac']): ?><small class="subline mono"><?= e($row['destination_mac']) ?></small><?php endif; ?></td>
<td><span class="protocol-pill"><?= e($row['protocol'] ?: '—') ?></span><small class="subline">if <?= e((string) ($row['input_interface'] ?? '—')) ?> → <?= e((string) ($row['output_interface'] ?? '—')) ?></small></td>
<td><strong><?= e(format_bytes($row['bytes'])) ?></strong><small class="subline"><?= number_format((int)$row['packets'], 0, ',', ' ') ?> paketů</small>
<details class="flow-details"><summary>Podrobnosti</summary><div><span>Zdrojová MAC</span><code><?= e($row['source_mac'] ?: '—') ?></code><span>Cílová MAC</span><code><?= e($row['destination_mac'] ?: '—') ?></code><span>NAT zdroj</span><code><?= e($row['nat_source_ip'] ?: '—') ?><?= $row['nat_source_port'] ? ':' . (int) $row['nat_source_port'] : '' ?></code><span>NAT cíl</span><code><?= e($row['nat_destination_ip'] ?: '—') ?><?= $row['nat_destination_port'] ? ':' . (int) $row['nat_destination_port'] : '' ?></code></div></details></td></tr>
<?php endforeach; ?></tbody></table></div></section>
