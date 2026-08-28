#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$localConfig = $root . '/config/local.php';

if (!is_file($localConfig)) {
    $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    $basePath = rtrim(trim((string) (getenv('WFM_BASE_PATH') ?: '')), '/');
    if ($basePath !== '' && preg_match('~^/[A-Za-z0-9/_-]+$~', $basePath) !== 1) {
        fwrite(STDERR, "Neplatná WFM_BASE_PATH.\n");
        exit(1);
    }
    $https = filter_var(getenv('WFM_HTTPS') ?: 'false', FILTER_VALIDATE_BOOL);
    $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'app' => [\n        'environment' => 'production',\n        'debug' => false,\n        'base_path' => " . var_export($basePath, true) . ",\n        'https' => " . ($https ? 'true' : 'false') . ",\n        'key' => " . var_export($key, true) . ",\n    ],\n    'database' => [\n        'path' => dirname(__DIR__) . '/storage/wifimanager.sqlite',\n    ],\n];\n";
    if (file_put_contents($localConfig, $content, LOCK_EX) === false) {
        fwrite(STDERR, "Nelze vytvořit config/local.php.\n");
        exit(1);
    }
    @chmod($localConfig, 0640);
    echo "Vytvořen bezpečný aplikační klíč.\n";
}

$container = require $root . '/app/bootstrap.php';
$database = $container['database'];
$database->migrate($root . '/database/schema.sql');
@chmod((string) $container['config']->get('database.path'), 0660);

$count = (int) $database->pdo()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($count > 0) {
    echo "Databáze je připravená a administrátorský účet už existuje.\n";
    exit(0);
}

echo "\nPrvní administrátorský účet\n";
$username = prompt('Přihlašovací jméno', 'admin');
$displayName = prompt('Zobrazované jméno', 'Administrátor');
$password = secretPrompt('Heslo (minimálně 10 znaků)');
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Heslo je příliš krátké. Spusťte instalaci znovu.\n");
    exit(1);
}

$statement = $database->pdo()->prepare(
    'INSERT INTO admin_users (username, username_normalized, display_name, password_hash, role) VALUES (:username, :normalized, :display_name, :password_hash, \'admin\')'
);
$statement->execute([
    'username' => $username,
    'normalized' => mb_strtolower($username),
    'display_name' => $displayName,
    'password_hash' => password_hash($password, password_algorithm()),
]);

echo "\nWiFi Manager je nainstalovaný.\n";

function prompt(string $label, string $default = ''): string
{
    fwrite(STDOUT, $label . ($default !== '' ? " [$default]" : '') . ': ');
    $value = trim((string) fgets(STDIN));
    return $value !== '' ? $value : $default;
}

function secretPrompt(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $hidden = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($hidden) shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $value;
}
