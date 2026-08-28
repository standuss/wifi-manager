<?php

declare(strict_types=1);

namespace WifiManager\Services;

use WifiManager\Config;

final class GitHubReleaseService
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string,mixed>|null */
    public function latest(string $repository, string $channel = 'stable'): ?array
    {
        if (preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository) !== 1) return null;
        $path = $channel === 'beta'
            ? '/repos/' . $repository . '/releases?per_page=10'
            : '/repos/' . $repository . '/releases/latest';
        $payload = $this->request($path);
        if ($channel === 'beta') {
            if (!is_array($payload)) return null;
            $payload = array_values(array_filter($payload, static fn ($release): bool => is_array($release) && !($release['draft'] ?? true)))[0] ?? null;
        }
        if (!is_array($payload)) return null;
        $tag = ltrim((string) ($payload['tag_name'] ?? ''), 'v');
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $tag) !== 1) return null;
        $asset = null;
        $attestation = null;
        $expectedAsset = 'wifi-manager-' . $tag . '.zip';
        foreach (($payload['assets'] ?? []) as $candidate) {
            if (!is_array($candidate)) continue;
            if ((string) ($candidate['name'] ?? '') === $expectedAsset) {
                $asset = $candidate;
            } elseif ((string) ($candidate['name'] ?? '') === $expectedAsset . '.attestation.jsonl') {
                $attestation = $candidate;
            }
        }
        return [
            'version' => $tag,
            'tag' => (string) ($payload['tag_name'] ?? ''),
            'name' => (string) ($payload['name'] ?? $tag),
            'notes' => (string) ($payload['body'] ?? ''),
            'published_at' => $payload['published_at'] ?? null,
            'html_url' => (string) ($payload['html_url'] ?? ''),
            'prerelease' => (bool) ($payload['prerelease'] ?? false),
            'asset_name' => (string) ($asset['name'] ?? ''),
            'asset_size' => (int) ($asset['size'] ?? 0),
            'attestation_name' => (string) ($attestation['name'] ?? ''),
            'installable' => is_array($asset) && is_array($attestation),
        ];
    }

    /** @return array<string,mixed> */
    private function request(string $path): array
    {
        $handle = curl_init(rtrim((string) $this->config->get('update.github_api'), '/') . $path);
        if ($handle === false) throw new \RuntimeException('Nelze připravit spojení s GitHubem.');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) $this->config->get('update.timeout_seconds', 12),
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: WiFi-Manager-Updater',
            ],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('GitHub Releases API není dostupné' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ').'));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new \RuntimeException('GitHub vrátil neplatnou odpověď.');
        return $decoded;
    }
}
