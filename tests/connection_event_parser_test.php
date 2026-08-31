<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/helpers.php';
require dirname(__DIR__) . '/app/Config.php';
require dirname(__DIR__) . '/app/Database.php';
require dirname(__DIR__) . '/app/Services/ConnectionEventService.php';

use WifiManager\Config;
use WifiManager\Database;
use WifiManager\Services\ConnectionEventService;

$path = sys_get_temp_dir() . '/wfm-events-' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $config = new Config([
        'app' => ['timezone' => 'Europe/Prague'],
        'database' => ['path' => $path, 'busy_timeout_ms' => 1000],
        'logging' => ['syslog_dir' => sys_get_temp_dir()],
    ]);
    $service = new ConnectionEventService(new Database($config), $config);
    $parse = new ReflectionMethod(ConnectionEventService::class, 'parse');
    $connected = $parse->invoke($service, [
        'timegenerated' => '2026-08-31T12:00:00+02:00',
        'hostname' => 'gateway',
        'msg' => 'wifi,info 7A:33:B5:13:E3:E8 connected ssid=Signal156 interface=cap-prvni',
    ]);
    if (($connected['event'] ?? null) !== 'connected' || ($connected['mac'] ?? null) !== '7A:33:B5:13:E3:E8'
        || ($connected['ssid'] ?? null) !== 'Signal156' || ($connected['occurred_at'] ?? null) !== '2026-08-31 10:00:00') {
        throw new RuntimeException('Syslogové připojení nebylo správně rozpoznáno.');
    }
    $disconnected = $parse->invoke($service, [
        'timestamp' => '2026-08-31T12:03:00+02:00',
        'message' => 'caps-man,info 7A-33-B5-13-E3-E8 disconnected reason=signal lost',
    ]);
    if (($disconnected['event'] ?? null) !== 'disconnected' || ($disconnected['type'] ?? null) !== 'legacy') {
        throw new RuntimeException('Odpojení starého CAPsMANu nebylo správně rozpoznáno.');
    }
    if (!is_private_mac('7A:33:B5:13:E3:E8') || is_private_mac('78:33:B5:13:E3:E8')) {
        throw new RuntimeException('Rozpoznání soukromé MAC adresy selhalo.');
    }
    echo "Connection event parser test OK\n";
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
