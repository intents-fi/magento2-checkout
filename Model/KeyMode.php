<?php
/**
 * Which mode an API key belongs to.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * The store holds one secret key, and its prefix says which mode it is (SPEC-checkout §4.1):
 * `ik_test_…` routes in test mode and never moves funds, `ik_live_…` is real money.
 */
class KeyMode
{
    public const TEST = 'test';
    public const LIVE = 'live';

    /** The mode a secret key belongs to, or null if it is not a secret key at all. */
    public static function detect(string $apiKey): ?string
    {
        $key = trim($apiKey);
        if (preg_match('/^ik_test_[A-Za-z0-9_\-]{8,}$/', $key) === 1) {
            return self::TEST;
        }
        if (preg_match('/^ik_live_[A-Za-z0-9_\-]{8,}$/', $key) === 1) {
            return self::LIVE;
        }
        return null;
    }

    /**
     * Why a key cannot be used, for the admin, or null when it can.
     *
     * A publishable key (`pk_…`) is the common mistake: it is meant for browsers and can only
     * create payment-link invoices, so the API would refuse every order with it.
     */
    public static function problem(string $apiKey): ?string
    {
        $key = trim($apiKey);
        if ($key === '') {
            return 'No API key is saved.';
        }
        if (self::detect($key) !== null) {
            return null;
        }
        if (str_starts_with($key, 'pk_')) {
            return 'This is a publishable key (pk_…). Paste the secret key (ik_test_… or ik_live_…) from your Intents dashboard.';
        }
        return 'This does not look like an Intents secret key. It should start with ik_test_ or ik_live_.';
    }
}
