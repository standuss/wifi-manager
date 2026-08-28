<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Support/helpers.php';
require dirname(__DIR__) . '/app/Services/JobProcessor.php';

use WifiManager\Services\JobProcessor;

$loggingMatch = new ReflectionMethod(JobProcessor::class, 'loggingActionMatches');
$modern = $loggingMatch->invoke(null, [
    'name' => 'wifimanager-remote',
    'remote-port' => '192.0.2.10:5514',
], '192.0.2.10', 5514, '192.0.2.10:5514');
$legacy = $loggingMatch->invoke(null, [
    'name' => 'wifimanager-remote',
    'remote' => '192.0.2.10',
    'remote-port' => '5514',
], '192.0.2.10', 5514, '192.0.2.10:5514');
if ($modern !== true || $legacy !== true) {
    throw new RuntimeException('Kompatibilita endpointu vzdáleného logování selhala.');
}

$findQueue = new ReflectionMethod(JobProcessor::class, 'findQueueForDevice');
$queue = $findQueue->invoke(null, [
    ['.id' => '*1', 'target' => '192.0.2.20/32', 'comment' => 'AA:BB:CC:DD:EE:FF'],
], 'AA:BB:CC:DD:EE:FF', '192.0.2.30', '192.0.2.20', '*1');
if (($queue['.id'] ?? null) !== '*1') {
    throw new RuntimeException('Existující Simple Queue nebyla při změně IP nalezena.');
}

echo "Job processor compatibility test OK\n";
