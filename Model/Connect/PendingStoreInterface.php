<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

/**
 * Where a Connect request waits while the admin is on dashboard.intents.fi. Server-side only:
 * the PKCE verifier never goes in a URL, a cookie or the page.
 */
interface PendingStoreInterface
{
    /** Keep the request this admin user started, replacing any earlier one. */
    public function put(int $userId, array $pending, int $ttl): void;

    /** The request this admin user started, removed as it is read: a return is answered once. */
    public function take(int $userId): ?array;

    /** Remember which admin URL a return carrying this `state` goes on to. */
    public function putReturn(string $state, string $callbackUrl, int $ttl): void;

    /** The admin URL for a `state`, or null when none is waiting. */
    public function returnFor(string $state): ?string;
}
