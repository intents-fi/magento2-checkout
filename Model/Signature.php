<?php
/**
 * Webhook signature verification.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * Verifies the `X-Intents-Signature` header intents.fi sends with every webhook.
 *
 * The header is `t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`. The timestamp
 * is part of the signed material, so an old body cannot be replayed under a new timestamp. The
 * scheme is the one the WooCommerce plugin verifies (plugins/woocommerce/includes/class-signature.php),
 * copied rather than shared, with one addition the webhook docs ask for: a header may carry more
 * than one `v1` (a secret rotation), and any one of them matching is enough.
 *
 * Free of Magento on purpose: it decides whether an order may be marked paid, so it is unit-tested
 * on its own.
 */
class Signature
{
    /** Allowed clock skew in seconds; the receiver tolerance intents.fi documents (five minutes). */
    public const DEFAULT_TOLERANCE = 300;

    /** The header name. */
    public const HEADER = 'X-Intents-Signature';

    /**
     * @param string   $secret    The webhook signing secret (`whsec_…`).
     * @param string   $payload   The raw request body, byte for byte as received.
     * @param string   $header    The `X-Intents-Signature` header value.
     * @param int      $tolerance Maximum clock skew in seconds; 0 disables the check.
     * @param int|null $now       Current unix time; defaults to `time()`.
     */
    public static function verify(
        string $secret,
        string $payload,
        string $header,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): bool {
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = self::parse($header);
        if ($parts === null) {
            return false;
        }

        if ($tolerance > 0) {
            $current = $now ?? time();
            if (abs($current - $parts['t']) > $tolerance) {
                return false;
            }
        }

        $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);

        $matched = false;
        foreach ($parts['v1'] as $candidate) {
            // Compare every candidate, in constant time, and never short-circuit on the first.
            $matched = hash_equals($expected, $candidate) || $matched;
        }
        return $matched;
    }

    /**
     * Split the header into its timestamp and its v1 signatures.
     *
     * @return array{t: int, v1: list<string>}|null Null when the header is not a well-formed v1 signature.
     */
    public static function parse(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $pair) {
            $split = explode('=', trim($pair), 2);
            if (count($split) !== 2) {
                continue;
            }
            $key = trim($split[0]);
            $value = trim($split[1]);
            if ($key === 't') {
                if ($timestamp !== null) {
                    // Two timestamps: there is no telling which one was signed.
                    return null;
                }
                $timestamp = $value;
            } elseif ($key === 'v1') {
                if (preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                    $signatures[] = strtolower($value);
                }
            }
        }

        if ($timestamp === null || $signatures === []) {
            return null;
        }
        if (preg_match('/^[0-9]{1,19}$/', $timestamp) !== 1) {
            return null;
        }

        return ['t' => (int) $timestamp, 'v1' => $signatures];
    }

    /** Sign a payload the way intents.fi does. Used by the tests and for local debugging. */
    public static function sign(string $secret, string $payload, int $timestamp): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }
}
