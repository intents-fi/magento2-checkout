<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

/** The module's key, webhook secret and connection, at one configuration scope. */
interface SettingsInterface
{
    /** The secret key saved at this scope (decrypted), or ''. */
    public function apiKey(): string;

    /**
     * What Connect saved at this scope, or null when the key here did not come from Connect (never
     * connected, disconnected, or replaced by a key entered manually).
     *
     * @return array{mode: string, business: string, merchant_status: string, connection_id: string, site: string, webhook: bool, connected_at: int}|null
     */
    public function connection(): ?array;

    /**
     * Save a connection's key and webhook secret where manual entry would have put them.
     *
     * @param array{secret_key: string, mode: string, business: string, merchant_status: string, connection_id: string, site: string} $token
     */
    public function save(array $token, string $webhookSecret, int $now): void;

    /** Forget the key, the webhook secret and the connection at this scope. */
    public function forget(): void;
}
