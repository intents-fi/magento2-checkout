<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

/**
 * Everything the webhook processor needs from the order store. The Magento implementation is
 * {@see MagentoOrderPort}; the tests use an in-memory one.
 */
interface OrderPortInterface
{
    /**
     * Find the order an invoice is about, or null when it is not one of this store's.
     *
     * Implementations must check the binding the module put in the invoice's metadata, so an
     * event for another store on the same intents.fi merchant can never move an order here.
     *
     * @param array<string, mixed> $invoice The webhook `data`.
     */
    public function find(array $invoice): ?OrderState;

    /** Is `$secret` the webhook secret configured for the store this order belongs to? */
    public function secretMatches(OrderState $order, string $secret): bool;

    /**
     * Apply a decision to the order and save it.
     *
     * @param array<string, mixed> $event   The whole verified event.
     * @param int|null             $newRank The rank to store, or null to leave it alone.
     */
    public function apply(OrderState $order, Decision $decision, array $event, ?int $newRank): void;

    /**
     * Run `$work` in one database transaction: everything it wrote is rolled back if it throws.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work);
}
