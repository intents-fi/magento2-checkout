<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model\Connect;

use IntentsFi\Checkout\Model\Api\TransportInterface;
use IntentsFi\Checkout\Model\Connect\ConnectFlow;
use IntentsFi\Checkout\Model\Connect\PendingStoreInterface;
use IntentsFi\Checkout\Model\Connect\SettingsInterface;

/** The pending store, in memory. */
final class MemoryPending implements PendingStoreInterface
{
    /** @var array<int, array> */
    public array $byUser = [];
    /** @var array<string, string> */
    public array $returns = [];

    public function put(int $userId, array $pending, int $ttl): void
    {
        $this->byUser[$userId] = $pending;
    }

    public function take(int $userId): ?array
    {
        $p = $this->byUser[$userId] ?? null;
        unset($this->byUser[$userId]);
        return $p;
    }

    public function putReturn(string $state, string $callbackUrl, int $ttl): void
    {
        $this->returns[$state] = $callbackUrl;
    }

    public function returnFor(string $state): ?string
    {
        return $this->returns[$state] ?? null;
    }
}

/** One scope's settings, in memory, with the same fingerprint rule as ScopeSettings. */
final class MemorySettings implements SettingsInterface
{
    public string $key = '';
    public string $webhookSecret = '';
    public ?array $saved = null;

    public function apiKey(): string
    {
        return $this->key;
    }

    public function connection(): ?array
    {
        if ($this->saved === null || $this->saved['key'] !== ConnectFlow::fingerprint($this->key)) {
            return null;
        }
        $c = $this->saved;
        unset($c['key']);
        return $c;
    }

    public function save(array $token, string $webhookSecret, int $now): void
    {
        $this->key = $token['secret_key'];
        $this->webhookSecret = $webhookSecret;
        $this->saved = [
            'key' => ConnectFlow::fingerprint($token['secret_key']),
            'mode' => $token['mode'],
            'business' => $token['business'],
            'merchant_status' => $token['merchant_status'],
            'connection_id' => $token['connection_id'],
            'site' => $token['site'],
            'webhook' => $webhookSecret !== '',
            'connected_at' => $now,
        ];
    }

    public function forget(): void
    {
        $this->key = '';
        $this->webhookSecret = '';
        $this->saved = null;
    }
}

/** Answers by `METHOD path`, and records each request (headers and decoded body). */
final class ScriptedTransport implements TransportInterface
{
    /** @var array<string, list<array{status: int, body: string}>> */
    public array $script = [];
    /** @var list<array{method: string, path: string, headers: array, body: mixed}> */
    public array $sent = [];

    public function on(string $method, string $path, int $status, array $body): void
    {
        $this->script[$method . ' ' . $path][] = ['status' => $status, 'body' => (string) json_encode($body)];
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->sent[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];
        $queue = $this->script[$method . ' ' . $path] ?? [];
        if ($queue === []) {
            throw new \RuntimeException('unscripted ' . $method . ' ' . $path);
        }
        return array_shift($this->script[$method . ' ' . $path]);
    }

    /** @return list<string> */
    public function calls(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $this->sent);
    }
}
