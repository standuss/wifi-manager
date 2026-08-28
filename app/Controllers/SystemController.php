<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Config;
use WifiManager\Csrf;
use WifiManager\Services\AuditService;
use WifiManager\Services\GitHubReleaseService;
use WifiManager\Services\LogArchiveService;
use WifiManager\Services\SettingsService;
use WifiManager\Services\SystemService;
use WifiManager\View;
use WifiManager\Database;
use WifiManager\Services\BackupService;

final class SystemController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly Config $config,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly SystemService $system,
        private readonly LogArchiveService $archive,
        private readonly GitHubReleaseService $releases,
        private readonly AuditService $audit,
        private readonly BackupService $backups,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAdmin();
        $settings = $this->settings->all() + $this->defaults();
        $status = $this->archive->status();
        $currentVersion = trim((string) file_get_contents(WFM_ROOT . '/VERSION'));
        $latest = null;
        $releaseError = null;
        $repository = (string) $this->config->get('update.repository');
        $channel = (string) $this->config->get('update.channel', 'stable');
        $settings['update_github_repository'] = $repository;
        $settings['update_channel'] = $channel;
        if ((string) $settings['update_auto_check'] === '1' || isset($_GET['check'])) {
            try {
                $latest = $this->releases->latest($repository, $channel);
            } catch (\Throwable $exception) {
                $releaseError = $exception->getMessage();
            }
        }
        $updateStatus = $this->system->updateStatus();
        $applyStatus = $this->system->applyStatus();
        $routers = $this->database->pdo()->query('SELECT id, name, COALESCE(identity,name) AS identity, status FROM routers WHERE enabled=1 ORDER BY name')->fetchAll();
        $backupRows = $this->database->pdo()->query(
            'SELECT b.*, COALESCE(r.identity,r.name) AS router_name FROM router_backups b JOIN routers r ON r.id=b.router_id ORDER BY b.created_at DESC LIMIT 20'
        )->fetchAll();
        $this->view->render('system', compact('settings', 'status', 'currentVersion', 'latest', 'releaseError', 'updateStatus', 'applyStatus', 'routers', 'backupRows') + [
            'title' => 'Služby a aktualizace', 'activeNav' => 'system',
        ]);
    }

    public function saveMonitoring(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $userId = (int) $this->auth->user()['id'];
        $result = $this->system->saveMonitoring($_POST, $userId);
        $message = $result['queued']
            ? 'Nastavení monitoringu bylo uloženo; místní služby i MikroTik byly zařazeny ke konfiguraci.'
            : 'Nastavení bylo uloženo. Systémový modul ještě není nainstalovaný, proto se změny zatím neaplikovaly.';
        flash($result['queued'] ? 'success' : 'warning', $message);
        $this->audit->log((int) $this->auth->user()['id'], 'monitoring.settings.updated', $message, 'system', null, $result['values'], request_ip());
        redirect('/system');
    }

    public function saveSmtp(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $values = $this->system->saveSmtp($_POST);
        $this->audit->log((int) $this->auth->user()['id'], 'smtp.settings.updated', 'SMTP nastavení bylo uloženo', 'system', null, [
            'enabled' => $values['smtp_enabled'], 'host' => $values['smtp_host'], 'port' => $values['smtp_port'],
        ], request_ip());
        flash('success', 'SMTP nastavení bylo bezpečně uloženo.');
        redirect('/system');
    }

    public function testSmtp(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $recipient = trim((string) ($_POST['recipient'] ?? ''));
        $this->system->testSmtp($recipient);
        $this->audit->log((int) $this->auth->user()['id'], 'smtp.test.sent', 'Testovací e-mail byl odeslán', 'system', null, ['recipient' => $recipient], request_ip());
        flash('success', 'Testovací e-mail byl úspěšně odeslán.');
        redirect('/system');
    }

    public function saveBackup(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $values = $this->system->saveBackup($_POST);
        $this->audit->log((int) $this->auth->user()['id'], 'backup.settings.updated', 'Nastavení záloh bylo uloženo', 'system', null, [
            'enabled' => $values['backup_enabled'], 'interval_days' => $values['backup_interval_days'], 'retention_count' => $values['backup_retention_count'],
        ], request_ip());
        flash('success', 'Nastavení záloh MikroTiku bylo uloženo.');
        redirect('/system');
    }

    public function backupNow(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $routerId = (int) ($_POST['router_id'] ?? 0);
        $check = $this->database->pdo()->prepare('SELECT COUNT(*) FROM routers WHERE id=:id AND enabled=1');
        $check->execute(['id' => $routerId]);
        if ((int) $check->fetchColumn() !== 1) throw new \RuntimeException('MikroTik nebyl nalezen.');
        if (($this->settings->all()['backup_password_cipher'] ?? '') === '') throw new \RuntimeException('Nejprve uložte heslo šifrování zálohy.');
        $backupId = $this->backups->createJob($routerId, (int) $this->auth->user()['id']);
        $this->audit->log((int) $this->auth->user()['id'], 'backup.queued', 'Záloha MikroTiku byla zařazena', 'router_backup', $backupId, ['router_id' => $routerId], request_ip());
        flash('success', 'Záloha byla předána workeru. Průběh se zobrazí po obnovení stránky.');
        redirect('/system');
    }

    public function downloadBackup(): void
    {
        $this->auth->requireAdmin();
        $id = (int) ($_GET['id'] ?? 0);
        $statement = $this->database->pdo()->prepare("SELECT * FROM router_backups WHERE id=:id AND status='done'");
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new \RuntimeException('Záloha nebyla nalezena.');
        $path = (string) ($row['local_path'] ?? '');
        $root = rtrim((string) $this->config->get('backup.directory'), '/') . '/';
        if ($path === '' || !str_starts_with($path, $root) || !is_readable($path)) throw new \RuntimeException('Soubor zálohy není dostupný.');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addcslashes(basename((string) $row['filename']), '"\\') . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function installUpdate(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $repository = (string) $this->config->get('update.repository');
        $release = $this->releases->latest($repository, (string) $this->config->get('update.channel', 'stable'));
        if (!$release || !$release['installable']) throw new \RuntimeException('Release neobsahuje očekávaný instalační ZIP.');
        $currentVersion = trim((string) file_get_contents(WFM_ROOT . '/VERSION'));
        if (version_compare((string) $release['version'], $currentVersion, '<=')) {
            throw new \RuntimeException('Není dostupná novější verze.');
        }
        $queued = $this->system->queueUpdate([
            'repository' => $repository,
            'tag' => $release['tag'],
            'version' => $release['version'],
            'asset_name' => $release['asset_name'],
            'requested_by' => (int) $this->auth->user()['id'],
        ]);
        if (!$queued) throw new \RuntimeException('Aktualizační služba není nainstalovaná nebo nemá přístup k frontě.');
        $this->audit->log((int) $this->auth->user()['id'], 'update.queued', 'Aktualizace ' . $release['version'] . ' byla zařazena', 'release', (string) $release['tag'], ['repository' => $repository], request_ip());
        flash('success', 'Aktualizace ' . $release['version'] . ' byla předána systémové službě. Průběh se zobrazí na této stránce.');
        redirect('/system');
    }

    /** @return array<string,string> */
    private function defaults(): array
    {
        return [
            'monitor_listen_address' => '0.0.0.0',
            'monitor_syslog_tcp_port' => '5514',
            'monitor_syslog_udp_port' => '514',
            'monitor_netflow_port' => '2055',
            'monitor_retention_days' => '1825',
            'monitor_syslog_max_gib' => '60',
            'monitor_netflow_max_gib' => '280',
            'monitor_router_target_address' => '',
            'monitor_router_syslog_port' => '5514',
            'monitor_router_syslog_transport' => 'tcp',
            'monitor_router_netflow_port' => '2055',
            'smtp_enabled' => '0',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'starttls',
            'smtp_auth_enabled' => '1',
            'smtp_username' => '',
            'smtp_from_email' => '',
            'smtp_from_name' => 'WiFi Manager',
            'smtp_timeout_seconds' => '10',
            'backup_enabled' => '0',
            'backup_interval_days' => '7',
            'backup_retention_count' => '12',
            'update_github_repository' => (string) $this->config->get('update.repository'),
            'update_channel' => (string) $this->config->get('update.channel', 'stable'),
            'update_auto_check' => '1',
        ];
    }
}
