<?php
/**
 * The optional affiliate ID (specs/BRIEF-affiliate-05-plugins.md).
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

use IntentsFi\Checkout\Model\Api\TransportInterface;
use IntentsFi\Checkout\Model\Connect\Protocol;
use IntentsFi\Connect\Connect_Protocol;

/**
 * The affiliate ID an agency or developer gives the merchant, without Magento.
 *
 * The rules are the shared Connect protocol's ({@see Connect_Protocol::affiliate_id()}): 6 to 16
 * lowercase letters, digits or hyphens, lowercased on save. The ID goes to Intents in three
 * places only: `ref` on the Connect URL, the `X-Intents-Affiliate` header on invoice create, and
 * `GET /v0/ref/resolve` when the setting is saved, to name the affiliate. Intents decides whether
 * it attributes anything; the module never assumes it did.
 */
class Affiliate
{
    /** `payment/intentsfi_checkout/affiliate_id`. */
    public const FIELD = 'affiliate_id';

    public const LINKED = 'linked';
    public const UNKNOWN = 'unknown';
    public const UNAVAILABLE = 'unavailable';

    /** The resolve call runs while the admin waits on Save Config. */
    private const RESOLVE_TIMEOUT = 5;

    /**
     * What was typed, trimmed and lowercased: `''` when empty, null when it is not a valid ID.
     *
     * @param mixed $value
     */
    public static function normalize($value): ?string
    {
        Protocol::load();
        return Connect_Protocol::affiliate_id($value);
    }

    /** A stored value as an ID to send, or `''` when it is empty or not valid. */
    public static function usable(string $value): string
    {
        return (string) self::normalize($value);
    }

    /**
     * The headers invoice create adds: `X-Intents-Affiliate` when an ID is set, nothing otherwise.
     *
     * @return array<string, string>
     */
    public static function headers(string $value): array
    {
        $id = self::usable($value);
        return $id === '' ? [] : [Connect_Protocol::AFFILIATE_HEADER => $id];
    }

    /**
     * `GET /v0/ref/resolve?code=<id>`: who the ID belongs to. Unauthenticated, and it sends only
     * the ID. A refusal, a non-200 answer or a body that is not `{valid, displayName}` is never an
     * error the admin has to deal with: the setting is saved either way.
     *
     * @return array{status: string, name: string}
     */
    public static function resolve(TransportInterface $transport, string $apiBase, string $id, string $userAgent): array
    {
        $id = self::usable($id);
        if ($id === '') {
            return ['status' => self::UNKNOWN, 'name' => ''];
        }
        try {
            $response = $transport->send(
                'GET',
                Connect_Protocol::resolve_url($apiBase, $id),
                ['Accept' => 'application/json', 'User-Agent' => $userAgent],
                null,
                self::RESOLVE_TIMEOUT
            );
        } catch (\RuntimeException $e) {
            return ['status' => self::UNAVAILABLE, 'name' => ''];
        }
        if ((int) $response['status'] !== 200) {
            return ['status' => self::UNAVAILABLE, 'name' => ''];
        }
        $checked = Connect_Protocol::check_resolve(json_decode((string) $response['body'], true));
        if (!$checked['valid']) {
            return ['status' => self::UNKNOWN, 'name' => ''];
        }
        return ['status' => self::LINKED, 'name' => $checked['display_name'] !== '' ? $checked['display_name'] : $id];
    }

    /**
     * What the settings page says after a resolve: [message type, text with `%1`, arguments].
     *
     * @param array{status: string, name: string} $result
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public static function notice(array $result): array
    {
        return match ($result['status']) {
            self::LINKED => ['success', 'Linked to %1.', [$result['name']]],
            self::UNKNOWN => [
                'warning',
                'Unknown affiliate ID. It was saved; check it with whoever gave it to you.',
                [],
            ],
            default => ['warning', 'Couldn\'t check the affiliate ID right now. It was saved.', []],
        };
    }

    /**
     * The affiliate `GET /v0/merchant` names, if Intents reports the merchant as referred.
     *
     * @param array<string, mixed> $merchant
     */
    public static function referredBy(array $merchant): ?string
    {
        $affiliate = $merchant['affiliate'] ?? null;
        if (!is_array($affiliate) || !is_string($affiliate['displayName'] ?? null)) {
            return null;
        }
        $name = trim($affiliate['displayName']);
        return $name === '' ? null : $name;
    }
}
