<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/LogArchiveService.php';

use WifiManager\Services\LogArchiveService;

$service = (new ReflectionClass(LogArchiveService::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(LogArchiveService::class, 'normalizeFlow');
$row = $method->invoke($service, [
    't_first' => '2026-08-28T10:00:00+02:00',
    'proto' => '6',
    'src4_addr' => '192.0.2.10',
    'dst4_addr' => '198.51.100.20',
    'src_port' => 43210,
    'dst_port' => 443,
    'src4_xlt_ip' => '203.0.113.5',
    'src_xlt_port' => 55000,
]);

if (($row['protocol'] ?? null) !== 'TCP') throw new RuntimeException('Číselný IP protokol nebyl převeden na TCP.');
if (($row['nat_source_ip'] ?? null) !== '203.0.113.5' || ($row['nat_source_port'] ?? null) !== 55000) {
    throw new RuntimeException('NAT údaje toku nebyly převedeny.');
}

echo "Flow normalization test OK\n";
