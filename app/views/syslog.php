<section class="archive-status-grid">
    <article class="archive-status-card <?= $status['syslog']['service'] === 'active' ? 'ok' : 'warn' ?>"><span><?= icon('terminal') ?></span><div><small>SBĚR SYSLOGU</small><strong><?= e($status['syslog']['service'] === 'active' ? 'Aktivní' : 'Neaktivní') ?></strong><p>Poslední data <?= e(format_datetime($status['syslog']['newest_at'])) ?></p></div></article>
    <article class="archive-status-card"><span><?= icon('archive') ?></span><div><small>VELIKOST ARCHIVU</small><strong><?= e(format_bytes($status['syslog']['bytes'])) ?></strong><p>Retence řízená denním úklidem</p></div></article>
    <article class="archive-status-card"><span><?= icon('database') ?></span><div><small>VOLNÉ MÍSTO</small><strong><?= e(format_bytes($status['disk']['free'])) ?></strong><p>z <?= e(format_bytes($status['disk']['total'])) ?></p></div></article>
</section>

<?php if (!$status['syslog']['readable']): ?><div class="info-banner warning"><?= icon('alert') ?><div><strong>Sběrač zatím není nainstalovaný</strong><p>Po spuštění <span class="mono">bin/install-monitoring.sh</span> se zde automaticky objeví události z routeru a CAPů.</p></div></div><?php endif; ?>
<?php if ($error): ?><div class="toast error"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

<form method="get" action="<?= e(url('/syslog')) ?>" class="panel archive-filters">
    <header class="panel-header"><div><span class="panel-kicker">DLOUHODOBÝ ARCHIV</span><h2>Vyhledat události</h2><p>Jeden dotaz může pokrýt nejvýše 31 dní; starší data hledejte po úsecích.</p></div><button class="button primary" type="submit"><?= icon('search') ?> Vyhledat</button></header>
    <div class="archive-filter-grid">
        <label><span>Od</span><input type="datetime-local" name="from" value="<?= e($filters['from']) ?>" required></label>
        <label><span>Do</span><input type="datetime-local" name="to" value="<?= e($filters['to']) ?>" required></label>
        <label><span>Zdroj / hostname</span><input name="host" value="<?= e($filters['host']) ?>" placeholder="cap-01 nebo 192.168…"></label>
        <label><span>Závažnost</span><select name="severity"><option value="">Všechny</option><?php foreach (['emerg'=>'Emergency','alert'=>'Alert','crit'=>'Critical','err'=>'Error','warning'=>'Warning','notice'=>'Notice','info'=>'Info','debug'=>'Debug'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $filters['severity'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label class="span-2"><span>Text zprávy, program nebo topic</span><input name="text" value="<?= e($filters['text']) ?>" placeholder="např. login failure, wifi, dhcp"></label>
        <label><span>Maximum záznamů</span><select name="limit"><?php foreach ([100,200,500] as $limit): ?><option value="<?= $limit ?>" <?= (int)$filters['limit'] === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php endforeach; ?></select></label>
    </div>
</form>

<section class="panel archive-results"><header class="panel-header"><div><span class="panel-kicker">VÝSLEDKY</span><h2><?= count($result['rows']) ?> událostí</h2></div><?php if ($result['truncated']): ?><span class="badge pending"><i></i>výpis zkrácen</span><?php endif; ?></header><div class="table-wrap"><table class="data-table log-table"><thead><tr><th>Čas</th><th>Zdroj</th><th>Závažnost</th><th>Program</th><th>Zpráva</th></tr></thead><tbody>
<?php if ($result['rows'] === []): ?><tr class="empty-row"><td colspan="5"><span><?= icon('terminal') ?></span><strong>Žádné odpovídající události</strong><small>Upravte období nebo filtry.</small></td></tr><?php endif; ?>
<?php foreach ($result['rows'] as $row): ?><tr><td class="mono"><?= e(format_datetime($row['timestamp'])) ?></td><td><strong><?= e($row['hostname'] ?: '—') ?></strong><small class="subline mono"><?= e($row['source_ip'] ?: '—') ?></small></td><td><span class="severity severity-<?= e($row['severity']) ?>"><?= e($row['severity']) ?></span></td><td><strong><?= e($row['program'] ?: '—') ?></strong><small class="subline"><?= e($row['facility']) ?></small></td><td class="log-message" title="<?= e($row['raw']) ?>"><?= e($row['message'] ?: $row['raw']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
