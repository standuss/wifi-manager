<?php

declare(strict_types=1);

use WifiManager\Controllers\AccessPointsController;
use WifiManager\Controllers\AuditController;
use WifiManager\Controllers\AuthController;
use WifiManager\Controllers\ClientsController;
use WifiManager\Controllers\DashboardController;
use WifiManager\Controllers\NetworksController;
use WifiManager\Controllers\LoggingController;
use WifiManager\Controllers\RegistrationActionsController;
use WifiManager\Controllers\RegistrationsController;
use WifiManager\Controllers\SettingsController;
use WifiManager\Controllers\SystemController;
use WifiManager\Controllers\UsersController;
use WifiManager\Services\AuditService;
use WifiManager\Services\JobService;
use WifiManager\Services\LogArchiveService;
use WifiManager\Services\RouterFactory;
use WifiManager\Services\SettingsService;
use WifiManager\Services\SystemService;
use WifiManager\Services\GitHubReleaseService;
use WifiManager\Services\SmtpMailer;
use WifiManager\Services\NotificationService;
use WifiManager\Services\BackupService;

if (is_file(dirname(__DIR__) . '/storage/update-in-progress')) {
    http_response_code(503);
    header('Retry-After: 20');
    echo '<!doctype html><html lang="cs"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Probíhá aktualizace</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f7fb;font:16px system-ui;color:#142033}.box{max-width:520px;margin:20px;padding:36px;border:1px solid #dce3ed;border-radius:20px;background:white;box-shadow:0 16px 50px #10243b18}h1{font-size:24px}p{line-height:1.6;color:#607089}</style><div class="box"><h1>WiFi Manager se aktualizuje</h1><p>Probíhá ověření, migrace a kontrola nové verze. Stránku obnovte přibližně za půl minuty.</p></div></html>';
    exit;
}

$container = require dirname(__DIR__) . '/app/bootstrap.php';
extract($container);

$audit = new AuditService($database);
$settingsService = new SettingsService($database);
$jobs = new JobService($database, $crypto);
$routerFactory = new RouterFactory($database, $crypto);
$logArchive = new LogArchiveService($database, $config);
$smtpMailer = new SmtpMailer();
$notifications = new NotificationService($database, $settingsService, $crypto, $smtpMailer);
$backups = new BackupService($database, $config, $settingsService, $jobs, $crypto, $notifications);
$systemService = new SystemService($settingsService, $config, $database, $jobs, $crypto, $smtpMailer);
$githubReleases = new GitHubReleaseService($config);

$controllers = [
    'auth' => new AuthController($auth, $view, $audit),
    'dashboard' => new DashboardController($database, $auth, $view),
    'clients' => new ClientsController($database, $auth, $view, $routerFactory, $audit),
    'registrations' => new RegistrationsController($database, $auth, $view, $settingsService, $jobs, $audit),
    'registration-actions' => new RegistrationActionsController($database, $auth, $routerFactory, $audit),
    'networks' => new NetworksController($database, $auth, $view, $jobs, $audit, $crypto, $routerFactory),
    'access-points' => new AccessPointsController($database, $auth, $view),
    'logging' => new LoggingController($auth, $view, $logArchive),
    'users' => new UsersController($database, $auth, $view, $audit),
    'audit' => new AuditController($database, $auth, $view),
    'settings' => new SettingsController($database, $auth, $view, $settingsService, $crypto, $routerFactory, $audit),
    'system' => new SystemController($database, $auth, $config, $view, $settingsService, $systemService, $logArchive, $githubReleases, $audit, $backups),
];

$base = rtrim((string) $config->get('app.base_path', ''), '/');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base)) ?: '/';
$path = '/' . trim($uri, '/');
$path = $path === '//' ? '/' : $path;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = [
    'GET /' => ['dashboard', 'index'],
    'GET /api/live' => ['dashboard', 'live'],
    'GET /login' => ['auth', 'showLogin'],
    'POST /login' => ['auth', 'login'],
    'POST /logout' => ['auth', 'logout'],
    'GET /clients' => ['clients', 'index'],
    'POST /clients/disconnect' => ['clients', 'disconnect'],
    'GET /registrations' => ['registrations', 'index'],
    'POST /registrations' => ['registrations', 'store'],
    'POST /registrations/update' => ['registrations', 'update'],
    'POST /registrations/toggle' => ['registration-actions', 'toggle'],
    'POST /registrations/delete' => ['registration-actions', 'delete'],
    'GET /networks' => ['networks', 'index'],
    'POST /networks' => ['networks', 'store'],
    'POST /networks/toggle' => ['networks', 'toggle'],
    'POST /networks/delete' => ['networks', 'delete'],
    'POST /networks/password' => ['networks', 'password'],
    'GET /access-points' => ['access-points', 'index'],
    'GET /syslog' => ['logging', 'syslog'],
    'GET /flows' => ['logging', 'flows'],
    'GET /users' => ['users', 'index'],
    'POST /users' => ['users', 'store'],
    'POST /users/toggle' => ['users', 'toggle'],
    'POST /users/update' => ['users', 'update'],
    'GET /audit' => ['audit', 'index'],
    'GET /settings' => ['settings', 'index'],
    'POST /settings' => ['settings', 'save'],
    'GET /system' => ['system', 'index'],
    'POST /system/monitoring' => ['system', 'saveMonitoring'],
    'POST /system/smtp' => ['system', 'saveSmtp'],
    'POST /system/smtp-test' => ['system', 'testSmtp'],
    'POST /system/backup-settings' => ['system', 'saveBackup'],
    'POST /system/backup-now' => ['system', 'backupNow'],
    'GET /system/backup-download' => ['system', 'downloadBackup'],
    'POST /system/update-install' => ['system', 'installUpdate'],
];

try {
    $route = $routes[$method . ' ' . $path] ?? null;
    if (!$route) {
        http_response_code(404);
        $auth->requireLogin();
        $view->render('error', ['title' => 'Stránka nenalezena', 'message' => 'Požadovaná stránka neexistuje.', 'activeNav' => '']);
        exit;
    }
    [$controller, $action] = $route;
    $controllers[$controller]->{$action}();
} catch (\Throwable $exception) {
    if ((bool) $config->get('app.debug', false)) {
        error_log((string) $exception);
    } else {
        error_log($exception->getMessage());
    }
    if ($method === 'POST') {
        flash('error', $exception->getMessage());
        $fallback = match (true) {
            str_starts_with($path, '/clients') => '/clients',
            str_starts_with($path, '/registrations') => '/registrations',
            str_starts_with($path, '/networks') => '/networks',
            str_starts_with($path, '/users') => '/users',
            str_starts_with($path, '/settings') => '/settings',
            str_starts_with($path, '/system') => '/system',
            default => '/',
        };
        redirect($fallback);
    }
    http_response_code($exception instanceof \InvalidArgumentException ? 422 : 500);
    if ($auth->check()) {
        $view->render('error', ['title' => 'Nastala chyba', 'message' => $exception->getMessage(), 'activeNav' => '']);
    } else {
        $view->render('login', ['title' => 'Přihlášení', 'fatalError' => $exception->getMessage()], false);
    }
}
