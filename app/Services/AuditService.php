<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Database;

final class AuditService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @param array<string,mixed> $details */
    public function log(?int $userId, string $action, string $summary, ?string $entityType = null, string|int|null $entityId = null, array $details = [], ?string $ip = null): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, summary, details_json, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :summary, :details, :ip)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'summary' => $summary,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ip' => $ip,
        ]);
    }
}

