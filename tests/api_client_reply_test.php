<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/RouterOS/RouterOsException.php';
require dirname(__DIR__) . '/app/RouterOS/ApiClient.php';

use WifiManager\RouterOS\ApiClient;

function sentence(array $words): string
{
    $payload = '';
    foreach ($words as $word) {
        $length = strlen($word);
        if ($length >= 0x80) {
            throw new RuntimeException('Testovací slovo je příliš dlouhé.');
        }
        $payload .= chr($length) . $word;
    }
    return $payload . chr(0);
}

$wire = sentence(['!empty']) . sentence(['!done', '=ret=complete']);
$stream = fopen('php://temp', 'r+');
if ($stream === false) {
    throw new RuntimeException('Nelze vytvořit testovací stream.');
}
fwrite($stream, $wire);
rewind($stream);

$client = new ApiClient([
    'host' => '127.0.0.1',
    'port' => 8728,
    'username' => 'test',
    'password' => 'test',
]);

$socket = new ReflectionProperty(ApiClient::class, 'socket');
$socket->setValue($client, $stream);
$readResponse = new ReflectionMethod(ApiClient::class, 'readResponse');
$response = $readResponse->invoke($client);

if ($response['rows'] !== [] || ($response['done']['ret'] ?? null) !== 'complete') {
    throw new RuntimeException('!empty nebylo dočteno až k závěrečnému !done.');
}
if (ftell($stream) !== strlen($wire)) {
    throw new RuntimeException('Po odpovědi zůstala ve streamu nepřečtená data.');
}

echo "RouterOS API reply test OK\n";
