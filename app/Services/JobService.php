<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Crypto;
use WifiManager\Database;

final class JobService
{
    public function __construct(private readonly Database $database, private readonly Crypto $crypto)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(int $routerId, string $type, array $payload, ?int $userId): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO sync_jobs (router_id, type, payload_cipher, created_by) VALUES (:router_id, :type, :payload, :created_by)'
        );
        $statement->execute([
            'router_id' => $routerId,
            'type' => $type,
            'payload' => $this->crypto->encrypt($json),
            'created_by' => $userId,
        ]);
        return (int) $this->database->pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function next(): ?array
    {
        return $this->database->transaction(function (): ?array {
            $job = $this->database->pdo()->query(
                "SELECT * FROM sync_jobs WHERE status = 'pending' ORDER BY created_at, id LIMIT 1"
            )->fetch();
            if (!is_array($job)) {
                return null;
            }
            $update = $this->database->pdo()->prepare(
                "UPDATE sync_jobs SET status = 'running', attempts = attempts + 1, started_at = CURRENT_TIMESTAMP, progress = 'Připojování k MikroTiku' WHERE id = :id AND status = 'pending'"
            );
            $update->execute(['id' => $job['id']]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            $decoded = $this->crypto->decrypt((string) $job['payload_cipher']);
            $job['payload'] = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
            return $job;
        });
    }

    public function progress(int $jobId, string $message): void
    {
        $statement = $this->database->pdo()->prepare('UPDATE sync_jobs SET progress = :message WHERE id = :id');
        $statement->execute(['message' => $message, 'id' => $jobId]);
    }

    public function done(int $jobId): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE sync_jobs SET status = 'done', progress = 'Hotovo', finished_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = :id"
        );
        $statement->execute(['id' => $jobId]);
    }

    public function failed(int $jobId, string $message): void
    {
        $statement = $this->database->pdo()->prepare(
            "UPDATE sync_jobs SET status = 'failed', progress = 'Chyba', finished_at = CURRENT_TIMESTAMP, last_error = :message WHERE id = :id"
        );
        $statement->execute(['id' => $jobId, 'message' => mb_substr($message, 0, 1000)]);
    }
}
