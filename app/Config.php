<?php

declare(strict_types=1);

namespace WifiManager;

final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public static function load(string $root): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $root . '/config/defaults.php';
        $localPath = $root . '/config/local.php';
        $local = is_file($localPath) ? require $localPath : [];

        if (!is_array($local)) {
            throw new \RuntimeException('Soubor config/local.php musí vracet pole.');
        }

        return new self(self::merge($defaults, $local));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $override */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

