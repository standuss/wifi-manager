<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Services\LogArchiveService;
use WifiManager\View;

final class LoggingController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
        private readonly LogArchiveService $archive,
    ) {
    }

    public function syslog(): void
    {
        $this->auth->requireLogin();
        $filters = $this->baseFilters() + [
            'text' => trim((string) ($_GET['text'] ?? '')),
            'host' => trim((string) ($_GET['host'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
        ];
        $status = $this->archive->status();
        $result = ['rows' => [], 'truncated' => false];
        $error = null;
        if ($status['syslog']['readable']) {
            try {
                $result = $this->archive->searchSyslog($filters);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $this->view->render('syslog', compact('filters', 'status', 'result', 'error') + [
            'title' => 'Syslog události', 'activeNav' => 'syslog',
        ]);
    }

    public function flows(): void
    {
        $this->auth->requireLogin();
        $filters = $this->baseFilters() + [
            'ip' => trim((string) ($_GET['ip'] ?? '')),
            'port' => trim((string) ($_GET['port'] ?? '')),
            'protocol' => trim((string) ($_GET['protocol'] ?? '')),
        ];
        $status = $this->archive->status();
        $result = ['rows' => [], 'truncated' => false];
        $error = null;
        if ($status['netflow']['readable'] && $status['netflow']['nfdump']) {
            try {
                $result = $this->archive->searchFlows($filters);
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $this->view->render('flows', compact('filters', 'status', 'result', 'error') + [
            'title' => 'Síťové toky', 'activeNav' => 'flows',
        ]);
    }

    /** @return array{from:string,to:string,limit:int} */
    private function baseFilters(): array
    {
        return [
            'from' => trim((string) ($_GET['from'] ?? date('Y-m-d\TH:i', time() - 86400))),
            'to' => trim((string) ($_GET['to'] ?? date('Y-m-d\TH:i'))),
            'limit' => max(1, min(500, (int) ($_GET['limit'] ?? 200))),
        ];
    }
}
