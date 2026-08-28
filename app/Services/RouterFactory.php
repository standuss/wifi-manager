<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Crypto;
use WifiManager\Database;
use WifiManager\RouterOS\ApiClient;
use WifiManager\RouterOS\RouterRepository;

final class RouterFactory
{
    public function __construct(private readonly Database $database, private readonly Crypto $crypto)
    {
    }

    /** @return array<string,mixed> */
    public function router(int $routerId): array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM routers WHERE id = :id AND enabled = 1');
        $statement->execute(['id' => $routerId]);
        $router = $statement->fetch();
        if (!is_array($router)) {
            throw new \RuntimeException('Aktivní MikroTik nebyl nalezen.');
        }
        return $router;
    }

    public function repository(int $routerId): RouterRepository
    {
        $router = $this->router($routerId);
        return new RouterRepository(new ApiClient([
            'host' => (string) $router['host'],
            'port' => (int) $router['port'],
            'username' => (string) $router['username'],
            'password' => $this->crypto->decrypt((string) $router['password_cipher']),
            'connect_timeout' => 5,
            'read_timeout' => 8,
        ]));
    }
}
