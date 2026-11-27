<?php
/**
 * HTTP client for the intents.fi public API.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Api;

use IntentsFi\Checkout\Model\Affiliate;

/**
 * The only code in this module that talks to intents.fi. Mirrors the WooCommerce plugin's
 * `Api_Client` (plugins/woocommerce/includes/class-api-client.php): the same calls, the same
 * error handling, and the key never appears in a log line or an exception message.
 */
class Client
{
    public const DEFAULT_BASE_URL = 'https://api.intents.fi';

    /** Invoice creation sits in the customer's checkout, so it fails fast rather than hold the page. */
    private const TIMEOUT = 20;

    /** @param callable(string): void|null $logError Receives one line per failure, never the key. */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly string $userAgent = 'intentsfi-checkout-magento',
        private $logError = null
    ) {
    }

    /** `GET /v0/merchant`: the merchant this key belongs to. Used by "Test connection". */
    public function getMerchant(): array
    {
        return $this->request('GET', '/v0/merchant');
    }

    /**
     * `POST /v0/invoices`: create an invoice, or get back the one this `reference` already made.
     * With an affiliate ID set, it goes along as `X-Intents-Affiliate`; Intents decides what it
     * does with it, and nothing here depends on the answer.
     */
    public function createInvoice(array $body, string $affiliateId = ''): array
    {
        return $this->request('POST', '/v0/invoices', $body, true, Affiliate::headers($affiliateId));
    }

    /** `GET /v0/invoices/{id}`. */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', '/v0/invoices/' . rawurlencode($invoiceId));
    }

    /**
     * `POST /v0/invoices/{id}/cancel`. The API refuses while a payment is in flight, which is
     * deliberate; callers treat that refusal as normal.
     */
    public function cancelInvoice(string $invoiceId): array
    {
        return $this->request('POST', '/v0/invoices/' . rawurlencode($invoiceId) . '/cancel');
    }

    /**
     * `POST /v0/webhooks`: register this store's webhook endpoint for the key's mode. The signing
     * secret (`whsec_…`) is in the response and nowhere else.
     *
     * @param list<string> $events
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->request('POST', '/v0/webhooks', ['url' => $url, 'events' => $events]);
    }

    /** `POST /v0/connect/revoke`: disconnect. Revokes this key's connection, its keys and webhooks. */
    public function revokeConnection(): array
    {
        return $this->request('POST', '/v0/connect/revoke');
    }

    /**
     * `POST /v0/connect/token`: exchange a Connect code for this store's keys. Unauthenticated (the
     * PKCE verifier is the credential), so it works on a client made with an empty key.
     *
     * @param array{code: string, code_verifier: string, site_url: string} $body
     */
    public function exchangeConnectCode(array $body): array
    {
        return $this->request('POST', '/v0/connect/token', $body, false);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $authed = true,
        array $extraHeaders = []
    ): array {
        $headers = $extraHeaders + [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        if ($authed) {
            if (trim($this->apiKey) === '') {
                throw new ApiException('MODULE_NO_KEY', 'No Intents API key is saved.');
            }
            $headers['Authorization'] = 'Bearer ' . trim($this->apiKey);
        }
        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new ApiException('MODULE_ENCODE_FAILED', 'Could not encode the request to Intents.');
            }
            $headers['Content-Type'] = 'application/json';
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = $this->transport->send($method, $url, $headers, $encoded, self::TIMEOUT);
        } catch (\RuntimeException $e) {
            $this->log(sprintf('%s %s failed: %s', $method, $path, self::redact($e->getMessage(), $this->apiKey)));
            throw new ApiException('MODULE_UNREACHABLE', 'Could not reach Intents. Please try again.');
        }

        $status = (int) $response['status'];
        $parsed = json_decode((string) $response['body'], true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($parsed)) {
                $this->log(sprintf('%s %s returned %d with a body that is not JSON', $method, $path, $status));
                throw new ApiException('MODULE_BAD_RESPONSE', 'Intents returned an unexpected response.', $status);
            }
            return $parsed;
        }

        $code = 'HTTP_' . $status;
        $message = sprintf('Intents returned an error (HTTP %d).', $status);
        if (is_array($parsed) && is_array($parsed['error'] ?? null)) {
            if (is_string($parsed['error']['code'] ?? null)) {
                $code = $parsed['error']['code'];
            }
            if (is_string($parsed['error']['message'] ?? null)) {
                $message = $parsed['error']['message'];
            }
        }
        $message = self::redact($message, $this->apiKey);

        $this->log(sprintf('%s %s returned %d %s: %s', $method, $path, $status, $code, $message));
        throw new ApiException($code, $message, $status);
    }

    private function log(string $line): void
    {
        if (is_callable($this->logError)) {
            ($this->logError)($line);
        }
    }

    /** Never let the key reach a log or a message, even if an upstream echoes it back. */
    public static function redact(string $text, string $key): string
    {
        $key = trim($key);
        $text = $key !== '' ? str_replace($key, '[redacted]', $text) : $text;
        return (string) preg_replace('/\b(ik|pk|whsec)_(live|test)?_?[A-Za-z0-9_\-]{6,}/', '$1_[redacted]', $text);
    }
}
