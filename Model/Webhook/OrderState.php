<?php
/**
 * What the webhook decision needs to know about an order.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

/**
 * A read-only snapshot of an order, so the decision is a pure function of the event and this.
 */
class OrderState
{
    /** Not held by this module. */
    public const HELD_NONE = '';

    /** Held because part of the money arrived; a later `invoice.paid` may release it. */
    public const HELD_UNDERPAID = 'underpaid';

    /** Held for a human (a mismatch, a superseded payment, or held by an admin); never released here. */
    public const HELD_REVIEW = 'review';

    public function __construct(
        public readonly string $incrementId,
        /** True once the order has an invoice, or is processing/complete/closed. */
        public readonly bool $isPaid,
        public readonly bool $isCanceled,
        /** One of the HELD_* constants. An order on hold that this module did not hold is HELD_REVIEW. */
        public readonly string $heldBy,
        /** The invoice id the order is waiting on now; '' when it has none. */
        public readonly string $currentInvoiceId,
        /** The order's grand total, as {@see \IntentsFi\Checkout\Model\Amount::format()} writes it. */
        public readonly string $expectedAmount,
        public readonly string $expectedCurrency,
        /** The highest {@see OrderAction::rank()} already applied from a webhook. */
        public readonly int $appliedRank
    ) {
    }

    /** Is this event about the invoice the order points at now? Only the order's own record decides. */
    public function isCurrentInvoice(string $invoiceId): bool
    {
        return $this->currentInvoiceId !== '' && $invoiceId !== ''
            && hash_equals($this->currentInvoiceId, $invoiceId);
    }
}
