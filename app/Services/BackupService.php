<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Config;
use WifiManager\Crypto;
use WifiManager\Database;
use WifiManager\RouterOS\RouterRepository;

final class BackupService
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
        private readonly SettingsService $settings,
        private readonly JobService $jobs,
        private readonly Crypto $crypto,
        private readonly NotificationService $notifications,
    ) {
    }

    public function scheduleIfDue(int $routerId): void
    {
        $settings = $this->settings->all();
        if (($settings['backup_enabled'] ?? '0') !== '1' || ($settings['backup_password_cipher'] ?? '') === '') return;
        $pending = $this->database->pdo()->prepare("SELECT COUNT(*) FROM sync_jobs WHERE router_id=:router AND type='backup_router' AND status IN ('pending','running')");
        $pending->execute(['router' => $routerId]);
        if ((int) $pending->fetchColumn() > 0) return;
        $last = $this->database->pdo()->prepare("SELECT status, COALESCE(completed_at,created_at) AS attempted_at FROM router_backups WHERE router_id=:router ORDER BY created_at DESC, id DESC LIMIT 1");
        $last->execute(['router' => $routerId]);
        $lastBackup = $last->fetch();
        $days = max(1, min(365, (int) ($settings['backup_interval_days'] ?? 7)));
        if (is_array($lastBackup)) {
            $wait = $lastBackup['status'] === 'done' ? $days * 86400 : 21600;
            if (strtotime((string) $lastBackup['attempted_at']) > time() - $wait) return;
        }
        $this->createJob($routerId, null);
    }

    public function createJob(int $routerId, ?int $userId): int
    {
        $filename = 'wfm-' . $routerId . '-' . gmdate('Ymd-His') . '.backup';
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO router_backups (router_id, filename, created_by) VALUES (:router, :filename, :user)'
        );
        $statement->execute(['router' => $routerId, 'filename' => $filename, 'user' => $userId]);
        $backupId = (int) $this->database->pdo()->lastInsertId();
        $this->jobs->enqueue($routerId, 'backup_router', ['backup_id' => $backupId], $userId);
        return $backupId;
    }

    /** @param array<string,mixed> $payload */
    public function run(int $jobId, RouterRepository $repository, array $payload): void
    {
        $backupId = (int) ($payload['backup_id'] ?? 0);
        $row = $this->database->pdo()->prepare('SELECT * FROM router_backups WHERE id=:id');
        $row->execute(['id' => $backupId]);
        $backup = $row->fetch();
        if (!is_array($backup)) throw new \RuntimeException('Záznam zálohy nebyl nalezen.');
        $settings = $this->settings->all();
        if (($settings['backup_password_cipher'] ?? '') === '') throw new \RuntimeException('Nejprve nastavte heslo zálohy MikroTiku.');
        $password = $this->crypto->decrypt((string) $settings['backup_password_cipher']);
        $directory = rtrim((string) $this->config->get('backup.directory'), '/');
        if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException('Adresář pro zálohy není zapisovatelný.');
        $filename = basename((string) $backup['filename']);
        $remoteBase = preg_replace('/\.backup$/', '', $filename) ?: ('wfm-' . $backupId);
        $remote = $remoteBase . '.backup';
        $path = $directory . '/' . $filename;
        $temporary = $path . '.part-' . getmypid();
        $this->database->pdo()->prepare("UPDATE router_backups SET status='running', error=NULL WHERE id=:id")->execute(['id' => $backupId]);

        try {
            $this->jobs->progress($jobId, 'Vytvářím šifrovanou zálohu RouterOS');
            $repository->action('/system/backup', 'save', ['name' => $remoteBase, 'password' => $password, 'encryption' => 'aes-sha256']);
            $this->jobs->progress($jobId, 'Stahuji zálohu z MikroTiku');
            $handle = fopen($temporary, 'xb');
            if ($handle === false) throw new \RuntimeException('Nelze vytvořit lokální soubor zálohy.');
            try {
                $offset = 0;
                $chunkSize = max(4096, min(32768, (int) $this->config->get('backup.chunk_size', 32768)));
                while (true) {
                    $chunk = $repository->readFileChunk($remote, $offset, $chunkSize);
                    if ($chunk === '') break;
                    if (fwrite($handle, $chunk) !== strlen($chunk)) throw new \RuntimeException('Zápis stažené zálohy selhal.');
                    $offset += strlen($chunk);
                    if (strlen($chunk) < $chunkSize) break;
                    if ($offset > 1073741824) throw new \RuntimeException('Záloha překročila bezpečný limit 1 GiB.');
                }
            } finally {
                fclose($handle);
            }
            if (!is_file($temporary) || filesize($temporary) < 64) throw new \RuntimeException('MikroTik vrátil prázdnou nebo neúplnou zálohu.');
            chmod($temporary, 0640);
            if (!rename($temporary, $path)) throw new \RuntimeException('Nelze aktivovat staženou zálohu.');
            try { $repository->removeFile($remote); } catch (\Throwable) {}
            $resource = $repository->rows('/system/resource/print', ['version'])[0] ?? [];
            $this->database->pdo()->prepare(
                "UPDATE router_backups SET status='done', local_path=:path, size_bytes=:size, routeros_version=:version, completed_at=CURRENT_TIMESTAMP WHERE id=:id"
            )->execute(['path' => $path, 'size' => filesize($path), 'version' => $resource['version'] ?? null, 'id' => $backupId]);
            $this->prune((int) $backup['router_id'], max(1, min(100, (int) ($settings['backup_retention_count'] ?? 12))));
            $this->notifications->queueBackupResult((int) $backup['router_id'], $backupId, true, "Soubor: $filename\nVelikost: " . filesize($path) . ' B');
        } catch (\Throwable $exception) {
            @unlink($temporary);
            try { $repository->removeFile($remote); } catch (\Throwable) {}
            $this->database->pdo()->prepare("UPDATE router_backups SET status='failed', error=:error, completed_at=CURRENT_TIMESTAMP WHERE id=:id")
                ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $backupId]);
            $this->notifications->queueBackupResult((int) $backup['router_id'], $backupId, false, $exception->getMessage());
            throw $exception;
        }
    }

    private function prune(int $routerId, int $keep): void
    {
        $statement = $this->database->pdo()->prepare("SELECT id, local_path FROM router_backups WHERE router_id=:router AND status='done' ORDER BY completed_at DESC, id DESC LIMIT -1 OFFSET :keep");
        $statement->bindValue(':router', $routerId, \PDO::PARAM_INT);
        $statement->bindValue(':keep', $keep, \PDO::PARAM_INT);
        $statement->execute();
        foreach ($statement->fetchAll() as $row) {
            $path = (string) ($row['local_path'] ?? '');
            if ($path !== '' && str_starts_with($path, rtrim((string) $this->config->get('backup.directory'), '/') . '/')) @unlink($path);
            $this->database->pdo()->prepare('DELETE FROM router_backups WHERE id=:id')->execute(['id' => $row['id']]);
        }
    }
}
