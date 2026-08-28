<?php

declare(strict_types=1);

define('WFM_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'WifiManager\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = WFM_ROOT . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require WFM_ROOT . '/app/Support/helpers.php';

use WifiManager\Auth;
use WifiManager\Config;
use WifiManager\Crypto;
use WifiManager\Database;
use WifiManager\View;

$config = Config::load(WFM_ROOT);
$GLOBALS['wfm_config'] = $config;
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Prague'));

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) $config->get('session.name', 'wifimanager_session'));
    session_set_cookie_params([
        'lifetime' => (int) $config->get('session.lifetime', 28800),
        'path' => rtrim((string) $config->get('app.base_path', ''), '/') ?: '/',
        'secure' => (bool) $config->get('app.https', false),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

$database = new Database($config);
$auth = new Auth($database);
$view = new View(WFM_ROOT, $config, $auth);
$crypto = new Crypto($config);

return compact('config', 'database', 'auth', 'view', 'crypto');

