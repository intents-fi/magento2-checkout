<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

/**
 * Remembers which webhook event ids have been applied, so a redelivery is a no-op.
 */
interface EventLedgerInterface
{
    /**
     * Record an event id as handled. Returns false, and records nothing, when it already was.
     *
     * Called inside {@see OrderPortInterface::transaction()}, so a failure later in the same
     * delivery rolls the claim back and the retry is handled in full.
     */
    public function claim(string $eventId, string $incrementId, string $eventType): bool;
}
