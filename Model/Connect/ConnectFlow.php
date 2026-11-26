<?php
/**
 * intents.fi Connect for Magento: link a store without copying a key.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

use IntentsFi\Checkout\Model\Api\ApiException;
use IntentsFi\Checkout\Model\Api\Client;
use IntentsFi\Checkout\Lib\ConnectProtocol;

/**
 * The Magento half of intents.fi Connect (specs/BRIEF-merchant-onboarding.md Part D), without
 * Magento: the controllers hand it the request, a pending store and the settings of one scope.
 *
 *   1. {@see start()}: "Connect intents.fi" (test) or "Go live" (live). Makes a `state` and a PKCE
 *      verifier, keeps them for this admin user, and returns the dashboard URL to send them to.
 *   2. The admin approves on dashboard.intents.fi and comes back to the storefront's
 *      `intentsfi/connect/back`, which only forwards `code` and `state` to the admin callback (the
 *      API wants `return_url` on the store's own origin, and Magento's admin is often elsewhere).
 *   3. {@see finish()}: checks the state, exchanges the code with the verifier, registers this
 *      store's webhook with the new key and saves the key and the webhook secret.
 *
 * The module holds one key per scope, so going live replaces the test key, and the test
 * connection it replaces is revoked once the live one is saved. {@see disconnect()} revokes the
 * scope's connection on intents.fi, then forgets it here.
 */
class ConnectFlow
{
    public const PLATFORM = 'magento';

    /** Every invoice event the module acts on. */
    public const WEBHOOK_EVENTS = [
        'invoice.processing',
        'invoice.underpaid',
        'invoice.paid',
        'invoice.expired',
        'invoice.canceled',
        'invoice.needs_review',
    ];

    /** Plain http is accepted only for a store on this machine, as the API does (connect.ts). */
    private const DEV_HOST = '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.localhost|.+\.test)$/';

    /**
     * @param callable(string): Client $clientFor Builds a client for a key ('' for the token exchange).
     * @param callable(string): void|null $log    One line per problem; never a key or a code.
     */
    public function __construct(
        private readonly PendingStoreInterface $pending,
        private $clientFor,
        private readonly string $dashboardUrl = '',
        private $log = null
    ) {
    }

    /**
     * Step 1. Returns `['ok' => true, 'url' => …]`, or `['ok' => false, 'result' => 'origin'|'https']`.
     *
     * @param string   $siteUrl     The store, as `site_url` names it (no trailing slash).
     * @param string   $returnUrl   The storefront's `intentsfi/connect/back`.
     * @param string   $callbackUrl The admin callback the return is forwarded to.
     * @param string   $webhookUrl  This store's `intentsfi/webhook`.
     * @param int|null $websiteId   The configuration scope being edited (null: default).
     * @return array{ok: bool, url?: string, result?: string}
     */
    public function start(
        string $mode,
        int $userId,
        string $siteUrl,
        string $returnUrl,
        string $callbackUrl,
        string $webhookUrl,
        ?int $websiteId,
        int $now
    ): array {
        if (!ConnectProtocol::same_origin($siteUrl, $returnUrl)) {
            return ['ok' => false, 'result' => 'origin'];
        }
        if (!self::secureEnough($siteUrl)) {
            return ['ok' => false, 'result' => 'https'];
        }

        $pending = ConnectProtocol::begin($mode, $now);
        $pending['site_url'] = $siteUrl;
        $pending['webhook_url'] = $webhookUrl;
        $pending['website'] = $websiteId;
        $this->pending->put($userId, $pending, ConnectProtocol::PENDING_TTL);
        $this->pending->putReturn($pending['state'], $callbackUrl, ConnectProtocol::PENDING_TTL);

        $dashboard = $this->dashboardUrl !== '' ? $this->dashboardUrl : ConnectProtocol::DASHBOARD_URL;
        return [
            'ok' => true,
            'url' => ConnectProtocol::authorize_url($dashboard, $pending, self::PLATFORM, $siteUrl, $returnUrl),
        ];
    }

    /**
     * Step 2, on the storefront: where to forward a return, or null when no request is waiting.
     * The target is the admin URL saved at step 1, never anything from the query.
     *
     * @param array<string, mixed> $query
     */
    public function forwardUrl(array $query): ?string
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        if ($state === '') {
            return null;
        }
        $target = $this->pending->returnFor($state);
        if ($target === null) {
            return null;
        }
        $forward = ['state' => $state];
        foreach (['code', 'error'] as $field) {
            if (is_string($query[$field] ?? null)) {
                $forward[$field] = $query[$field];
            }
        }
        return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($forward, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Step 3, in the admin. Returns the result the settings page words (never reflected text), and
     * the scope the request was started from, so the admin lands back on it.
     *
     * @param array<string, mixed>                     $query       `state`, `code`, `error`.
     * @param callable(int|null): SettingsInterface    $settingsFor The settings of a scope.
     * @return array{result: string, website: int|null}
     */
    public function finish(array $query, int $userId, callable $settingsFor, int $now): array
    {
        $pending = $this->pending->take($userId);
        $website = is_array($pending) && is_int($pending['website'] ?? null) ? $pending['website'] : null;

        $checked = ConnectProtocol::check_return($query, $pending, $now);
        if (!$checked['ok']) {
            if (($checked['error'] ?? '') === 'state') {
                $this->log('Connect: a return arrived with a state this admin did not start; ignored.');
            }
            return ['result' => (string) ($checked['error'] ?? 'failed'), 'website' => $website];
        }

        $mode = ($pending['mode'] ?? '') === 'live' ? 'live' : 'test';
        try {
            $response = ($this->clientFor)('')->exchangeConnectCode(
                ConnectProtocol::token_body((string) $checked['code'], $pending, (string) $pending['site_url'])
            );
        } catch (ApiException $e) {
            return [
                'result' => $e->getApiCode() === 'LIVE_MODE_LOCKED' ? 'live_locked' : 'exchange_failed',
                'website' => $website,
            ];
        }

        $token = ConnectProtocol::check_token($response, $mode);
        if ($token === null) {
            $this->log('Connect: the token response was not the mode asked for, or malformed; nothing saved.');
            return ['result' => 'exchange_failed', 'website' => $website];
        }

        // The webhook, registered with the new key, so it belongs to this connection and stops
        // when the connection is revoked.
        $secret = '';
        try {
            $webhook = ($this->clientFor)($token['secret_key'])->createWebhook((string) $pending['webhook_url'], self::WEBHOOK_EVENTS);
            $secret = is_string($webhook['secret'] ?? null) ? $webhook['secret'] : '';
        } catch (ApiException $e) {
            $this->log('Connect: webhook registration failed: ' . $e->getApiCode());
        }

        $settings = $settingsFor($website);
        $replaced = $settings->connection() !== null ? $settings->apiKey() : '';
        $settings->save($token, $secret, $now);

        // Going live (or connecting again) replaced a key Connect made: revoke it, so its keys
        // and its webhook stop at once instead of lingering on intents.fi.
        if ($replaced !== '' && $replaced !== $token['secret_key']) {
            $this->revoke($replaced);
        }

        return ['result' => $secret === '' ? 'connected_no_webhook' : 'connected', 'website' => $website];
    }

    /** Revoke this scope's connection on intents.fi, then forget it here. */
    public function disconnect(SettingsInterface $settings): string
    {
        if ($settings->connection() === null) {
            return 'not_connected';
        }
        $key = $settings->apiKey();
        if ($key !== '') {
            $this->revoke($key);
        }
        // Forget it here even if the revoke failed: the merchant can still revoke it from
        // Integrations on the dashboard.
        $settings->forget();
        return 'disconnected';
    }

    /** A fingerprint of a key, to tell later whether the saved key is still the one Connect made. */
    public static function fingerprint(string $key): string
    {
        return substr(hash('sha256', trim($key)), 0, 16);
    }

    private function revoke(string $key): void
    {
        try {
            ($this->clientFor)($key)->revokeConnection();
        } catch (ApiException $e) {
            $this->log('Connect: revoke failed: ' . $e->getApiCode());
        }
    }

    private static function secureEnough(string $url): bool
    {
        // The protocol's own origin parser: `scheme://host[:port]`, lower-cased, or null.
        $origin = ConnectProtocol::origin($url);
        if ($origin === null || preg_match('#^(https?)://(.+?)(?::\d+)?$#', $origin, $m) !== 1) {
            return false;
        }
        return $m[1] === 'https' || preg_match(self::DEV_HOST, $m[2]) === 1;
    }

    private function log(string $line): void
    {
        if (is_callable($this->log)) {
            ($this->log)($line);
        }
    }
}
