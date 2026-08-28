<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Database;

final class SettingsService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $rows = $this->database->pdo()->query('SELECT key, value FROM app_settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }
        return $settings;
    }

    /** @param array<string,string|int> $settings */
    public function save(array $settings): void
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO app_settings (key, value, updated_at) VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
        );
        foreach ($settings as $key => $value) {
            $statement->execute(['key' => $key, 'value' => (string) $value]);
        }
    }
}

