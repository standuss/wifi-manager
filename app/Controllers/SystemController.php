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

final class SystemController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Config $config,
        private readonly View $view,
        private readonly SettingsService $settings,
        private readonly SystemService $system,
        private readonly LogArchiveService $archive,
        private readonly GitHubReleaseService $releases,
        private readonly AuditService $audit,
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
        $this->view->render('system', compact('settings', 'status', 'currentVersion', 'latest', 'releaseError', 'updateStatus', 'applyStatus') + [
            'title' => 'Služby a aktualizace', 'activeNav' => 'system',
        ]);
    }

    public function saveMonitoring(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $result = $this->system->saveMonitoring($_POST);
        $message = $result['queued']
            ? 'Nastavení monitoringu bylo uloženo a předáno systémové službě.'
            : 'Nastavení bylo uloženo. Systémový modul ještě není nainstalovaný, proto se změny zatím neaplikovaly.';
        flash($result['queued'] ? 'success' : 'warning', $message);
        $this->audit->log((int) $this->auth->user()['id'], 'monitoring.settings.updated', $message, 'system', null, $result['values'], request_ip());
        redirect('/system');
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
            'update_github_repository' => (string) $this->config->get('update.repository'),
            'update_channel' => (string) $this->config->get('update.channel', 'stable'),
            'update_auto_check' => '1',
        ];
    }
}
