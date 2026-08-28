<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/helpers.php';
require dirname(__DIR__) . '/app/Services/JobProcessor.php';

use WifiManager\Services\JobProcessor;

$loggingMatch = new ReflectionMethod(JobProcessor::class, 'loggingActionMatches');
$modern = $loggingMatch->invoke(null, [
    'name' => 'wifimanagerRemote',
    'remote-port' => '192.0.2.10:5514',
], '192.0.2.10', 5514, '192.0.2.10:5514');
$legacy = $loggingMatch->invoke(null, [
    'name' => 'wifimanagerRemote',
    'remote' => '192.0.2.10',
    'remote-port' => '5514',
], '192.0.2.10', 5514, '192.0.2.10:5514');
$oldName = $loggingMatch->invoke(null, [
    'name' => 'wifimanager-remote',
    'remote' => '192.0.2.10',
    'remote-port' => '5514',
], '192.0.2.10', 5514, '192.0.2.10:5514');
if ($modern !== true || $legacy !== true || $oldName !== true) {
    throw new RuntimeException('Kompatibilita endpointu vzdáleného logování selhala.');
}

$flowMatch = new ReflectionMethod(JobProcessor::class, 'flowTargetMatches');
$flowLowercase = $flowMatch->invoke(null, [
    'dst-address' => '192.0.2.10',
    'port' => '2055',
    'version' => 'ipfix',
], '192.0.2.10', 2055);
$flowUppercase = $flowMatch->invoke(null, [
    'dst-address' => '192.0.2.10',
    'port' => '2055',
    'version' => 'IPFIX',
], '192.0.2.10', 2055);
$flowV9 = $flowMatch->invoke(null, [
    'dst-address' => '192.0.2.10',
    'port' => '2055',
    'version' => '9',
], '192.0.2.10', 2055);
if ($flowLowercase !== true || $flowUppercase !== true || $flowV9 !== false) {
    throw new RuntimeException('Kontrola IPFIX targetu selhala.');
}

$findQueue = new ReflectionMethod(JobProcessor::class, 'findQueueForDevice');
$queue = $findQueue->invoke(null, [
    ['.id' => '*1', 'target' => '192.0.2.20/32', 'comment' => 'AA:BB:CC:DD:EE:FF'],
], 'AA:BB:CC:DD:EE:FF', '192.0.2.30', '192.0.2.20', '*1');
if (($queue['.id'] ?? null) !== '*1') {
    throw new RuntimeException('Existující Simple Queue nebyla při změně IP nalezena.');
}

echo "Job processor compatibility test OK\n";
