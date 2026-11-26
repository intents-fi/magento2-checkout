<?php
/**
 * Invoice event to Magento order action.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

use IntentsFi\Checkout\Model\Amount;

/**
 * Decides what one invoice webhook does to an order. Mirrors the WooCommerce plugin's
 * `Order_Status` (plugins/woocommerce/includes/class-order-status.php), with the differences a
 * Magento order needs:
 *
 *  - **Only `invoice.paid` invoices the order** (invariant 8: it fires only on core `completed`).
 *  - **Underpaid holds the order**, with a comment, and a later `invoice.paid` releases that hold.
 *  - **A paid amount or currency that is not the order's holds the order** rather than invoicing
 *    it: the money is real, but not what this order asks for, so a human decides.
 *  - **A payment on a canceled order is flagged for review**, never invoiced: Magento cannot
 *    invoice a canceled order, and reinstating one silently would ship goods nobody checked.
 *
 * Deliveries repeat and arrive out of order, so each event has a rank, the highest rank applied
 * is kept on the order, and a lower-ranked event never moves it backwards: a late `expired` or
 * `canceled` never undoes a paid order.
 *
 * Free of Magento, so the whole table is unit-tested.
 */
class OrderAction
{
    /** Invoice the order and move it to processing. */
    public const ACTION_PAID = 'paid';

    /** Put the order on hold with a comment, and raise an admin notice. */
    public const ACTION_HOLD = 'hold';

    /** Cancel the order: the invoice expired or was canceled and nothing was paid. */
    public const ACTION_CANCEL = 'cancel';

    /** Add a comment and change nothing else. */
    public const ACTION_NOTE = 'note';

    /** Add a comment and raise an admin notice: a human should look. Status unchanged. */
    public const ACTION_REVIEW = 'review';

    /** Change nothing: a stale or repeated lifecycle event. A comment says it arrived. */
    public const ACTION_IGNORE = 'ignore';

    public const REASON_PAID = 'paid';
    public const REASON_UNDERPAID = 'underpaid';
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';
    public const REASON_SUPERSEDED_PAID = 'superseded_paid';
    public const REASON_SUPERSEDED = 'superseded';
    public const REASON_PAID_AFTER_CANCEL = 'paid_after_cancel';
    public const REASON_PAID_WHILE_HELD = 'paid_while_held';
    public const REASON_NEEDS_REVIEW = 'needs_review';
    public const REASON_PROCESSING = 'processing';
    public const REASON_EXPIRED = 'expired';
    public const REASON_CANCELED = 'canceled';
    public const REASON_HELD_FOR_REVIEW = 'held_for_review';
    public const REASON_ALREADY_PAID = 'already_paid';
    public const REASON_ALREADY_FINAL = 'already_final';
    public const REASON_UNKNOWN_TYPE = 'unknown_type';

    /**
     * Event rank. A higher rank overrides a lower one.
     *
     * `invoice.needs_review` is 0: it is orthogonal and never changes the order's status.
     */
    private const RANKS = [
        'invoice.needs_review' => 0,
        'invoice.processing' => 1,
        'invoice.underpaid' => 1,
        'invoice.expired' => 2,
        'invoice.canceled' => 2,
        'invoice.paid' => 3,
    ];

    /** The rank at and above which the invoice's lifecycle is over. */
    private const FINAL_RANK = 2;

    public static function isKnown(string $eventType): bool
    {
        return isset(self::RANKS[$eventType]);
    }

    /** The rank of an event type; -1 for an unknown one. */
    public static function rank(string $eventType): int
    {
        return self::RANKS[$eventType] ?? -1;
    }

    /**
     * Decide what to do with one verified event.
     *
     * @param string               $eventType The webhook `type`.
     * @param array<string, mixed> $invoice   The webhook `data` (the invoice view).
     * @param OrderState           $order     The order as it is now.
     */
    public static function decide(string $eventType, array $invoice, OrderState $order): Decision
    {
        if (!self::isKnown($eventType)) {
            return new Decision(self::ACTION_IGNORE, self::REASON_UNKNOWN_TYPE);
        }

        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';
        $rank = self::rank($eventType);

        if (!$order->isCurrentInvoice($invoiceId)) {
            // Not the invoice this order waits on, so its lifecycle may not move the order: the
            // current invoice may still be paid. A payment is the exception: real money for an
            // amount the order no longer asks for, so the order is held for a human — unless it
            // is already paid or canceled, when a review notice is all that is left to do.
            if ($eventType === 'invoice.paid') {
                if ($order->isPaid || $order->isCanceled) {
                    return new Decision(self::ACTION_REVIEW, self::REASON_SUPERSEDED_PAID);
                }
                return new Decision(self::ACTION_HOLD, self::REASON_SUPERSEDED_PAID);
            }
            if ($eventType === 'invoice.needs_review') {
                return new Decision(self::ACTION_REVIEW, self::REASON_NEEDS_REVIEW);
            }
            return new Decision(self::ACTION_NOTE, self::REASON_SUPERSEDED);
        }

        switch ($eventType) {
            case 'invoice.needs_review':
                return new Decision(self::ACTION_REVIEW, self::REASON_NEEDS_REVIEW);

            case 'invoice.paid':
                if ($order->isPaid) {
                    return new Decision(self::ACTION_IGNORE, self::REASON_ALREADY_PAID);
                }
                if ($order->isCanceled) {
                    return new Decision(self::ACTION_REVIEW, self::REASON_PAID_AFTER_CANCEL);
                }
                if (!self::paidAmountMatches($invoice, $order)) {
                    if ($order->heldBy === OrderState::HELD_REVIEW) {
                        return new Decision(self::ACTION_REVIEW, self::REASON_AMOUNT_MISMATCH);
                    }
                    return new Decision(self::ACTION_HOLD, self::REASON_AMOUNT_MISMATCH);
                }
                if ($order->heldBy === OrderState::HELD_REVIEW) {
                    // A human (or a mismatch) holds this order; money arriving does not overrule them.
                    return new Decision(self::ACTION_REVIEW, self::REASON_PAID_WHILE_HELD);
                }
                return new Decision(self::ACTION_PAID, self::REASON_PAID);

            case 'invoice.expired':
            case 'invoice.canceled':
                $reason = $eventType === 'invoice.expired' ? self::REASON_EXPIRED : self::REASON_CANCELED;
                if ($order->isPaid) {
                    return new Decision(self::ACTION_IGNORE, self::REASON_ALREADY_PAID);
                }
                if ($order->isCanceled || $rank <= $order->appliedRank) {
                    return new Decision(self::ACTION_IGNORE, self::REASON_ALREADY_FINAL);
                }
                if ($order->heldBy === OrderState::HELD_REVIEW) {
                    return new Decision(self::ACTION_NOTE, self::REASON_HELD_FOR_REVIEW);
                }
                return new Decision(self::ACTION_CANCEL, $reason);

            case 'invoice.underpaid':
            case 'invoice.processing':
            default:
                if ($order->isPaid) {
                    return new Decision(self::ACTION_IGNORE, self::REASON_ALREADY_PAID);
                }
                if ($order->isCanceled || $order->appliedRank >= self::FINAL_RANK) {
                    return new Decision(self::ACTION_IGNORE, self::REASON_ALREADY_FINAL);
                }
                if ($eventType === 'invoice.underpaid') {
                    if ($order->heldBy !== OrderState::HELD_NONE) {
                        // Already on hold: a second underpaid (a partial top-up) is a comment.
                        return new Decision(self::ACTION_NOTE, self::REASON_UNDERPAID);
                    }
                    return new Decision(self::ACTION_HOLD, self::REASON_UNDERPAID);
                }
                return new Decision(self::ACTION_NOTE, self::REASON_PROCESSING);
        }
    }

    /**
     * Was the invoice that was paid for exactly this order's total, in its currency?
     *
     * @param array<string, mixed> $invoice
     */
    public static function paidAmountMatches(array $invoice, OrderState $order): bool
    {
        $amount = is_string($invoice['amount'] ?? null) ? $invoice['amount'] : '';
        $currency = is_string($invoice['currency'] ?? null) ? strtoupper($invoice['currency']) : '';

        return $amount !== ''
            && $currency !== ''
            && $currency === strtoupper($order->expectedCurrency)
            && Amount::equals($amount, $order->expectedAmount);
    }
}
