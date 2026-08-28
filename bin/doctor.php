#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$required = ['pdo_sqlite', 'sqlite3', 'openssl', 'sodium', 'mbstring', 'curl', 'intl'];
$ok = true;
foreach ($required as $extension) {
    $loaded = extension_loaded($extension);
    printf("%-15s %s\n", $extension, $loaded ? 'OK' : 'CHYBÍ');
    $ok = $ok && $loaded;
}
$configOk = is_file($root . '/config/local.php');
$storageOk = is_writable($root . '/storage');
printf("%-15s %s\n", 'config', $configOk ? 'OK' : 'CHYBÍ – spusťte bin/install.php');
printf("%-15s %s\n", 'storage', $storageOk ? 'OK' : 'NENÍ ZAPISOVATELNÝ');
$ok = $ok && $configOk && $storageOk;

if (is_file($root . '/config/local.php')) {
    try {
        $container = require $root . '/app/bootstrap.php';
        $container['database']->migrate($root . '/database/schema.sql');
        echo "database        OK\n";
    } catch (Throwable $exception) {
        $ok = false;
        echo 'database        CHYBA: ' . $exception->getMessage() . "\n";
    }
}

echo "\nVolitelné monitorovací služby\n";
foreach (['/usr/bin/nfdump' => 'nfdump', '/usr/sbin/rsyslogd' => 'rsyslog', '/usr/bin/gh' => 'GitHub CLI'] as $binary => $label) {
    printf("%-15s %s\n", $label, is_executable($binary) ? 'OK' : 'nenainstalováno');
}
foreach (['/var/lib/wifimanager/syslog' => 'syslog archiv', '/var/lib/wifimanager/netflow' => 'IPFIX archiv', '/var/lib/wifimanager/backups' => 'zálohy RouterOS'] as $directory => $label) {
    printf("%-15s %s\n", $label, is_dir($directory) && is_readable($directory) ? 'OK' : 'nenainstalováno');
}
exit($ok ? 0 : 1);
