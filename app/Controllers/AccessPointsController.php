<?php

declare(strict_types=1);

namespace WifiManager\Controllers;

use WifiManager\Auth;
use WifiManager\Csrf;
use WifiManager\Database;
use WifiManager\Services\AuditService;
use WifiManager\Services\JobService;
use WifiManager\View;

final class AccessPointsController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly View $view,
        private readonly JobService $jobs,
        private readonly AuditService $audit,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireLogin();
        $aps = $this->database->pdo()->query(
            'SELECT a.*, (SELECT COUNT(*) FROM connected_clients c WHERE c.router_id=a.router_id AND c.capsman_type=a.capsman_type AND c.access_point_name = a.name) AS client_count FROM access_points a ORDER BY a.status DESC, COALESCE(a.custom_name, a.name)'
        )->fetchAll();
        $this->view->render('access_points', [
            'title' => 'Přístupové body', 'activeNav' => 'access-points', 'aps' => $aps,
        ]);
    }

    public function provision(): void
    {
        $this->auth->requireAdmin();
        Csrf::enforce();
        $id = (int) ($_POST['id'] ?? 0);
        $statement = $this->database->pdo()->prepare('SELECT * FROM access_points WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $ap = $statement->fetch();
        if (!is_array($ap)) throw new \RuntimeException('Přístupový bod nebyl nalezen.');
        if ((string) $ap['status'] !== 'online') throw new \RuntimeException('Offline přístupový bod nelze reprovisionovat.');

        $type = (string) ($ap['capsman_type'] ?? 'wifi') === 'legacy' ? 'legacy' : 'wifi';
        $remoteCapId = (string) ($ap['mikrotik_raw_id'] ?? '');
        if ($remoteCapId === '') throw new \RuntimeException('Přístupový bod nemá uložený identifikátor CAPsMANu. Proveďte plnou synchronizaci.');

        $user = $this->auth->user();
        $jobId = $this->jobs->enqueue((int) $ap['router_id'], 'provision_ap', [
            'access_point_id' => $id, 'access_point_name' => (string) $ap['name'],
            'capsman_type' => $type, 'remote_cap_id' => $remoteCapId,
        ], (int) $user['id']);
        $this->audit->log((int) $user['id'], 'access_point.provision.requested', 'Reprovisionování AP bylo zařazeno: ' . $ap['name'], 'access_point', $id, [
            'job_id' => $jobId, 'capsman_type' => $type, 'remote_cap_id' => $remoteCapId,
        ], request_ip());
        flash('success', 'Reprovisionování přístupového bodu bylo zařazeno do synchronizace.');
        redirect('/access-points');
    }
}
