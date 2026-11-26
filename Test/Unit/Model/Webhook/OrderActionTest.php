<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model\Webhook;

use IntentsFi\Checkout\Model\Webhook\OrderAction;
use IntentsFi\Checkout\Model\Webhook\OrderState;
use PHPUnit\Framework\TestCase;

final class OrderActionTest extends TestCase
{
    private const INVOICE = 'inv_3QkR7mVt9zLpXdB2hJn6Yw';
    private const OLD_INVOICE = 'inv_9TzW2pLx6QmRv8JdK3nYcB';

    private static function order(array $o = []): OrderState
    {
        return new OrderState(
            $o['incrementId'] ?? '100000042',
            $o['isPaid'] ?? false,
            $o['isCanceled'] ?? false,
            $o['heldBy'] ?? OrderState::HELD_NONE,
            $o['currentInvoiceId'] ?? self::INVOICE,
            $o['expectedAmount'] ?? '49.00',
            $o['expectedCurrency'] ?? 'USD',
            $o['appliedRank'] ?? 0
        );
    }

    private static function invoice(array $o = []): array
    {
        return $o + ['id' => self::INVOICE, 'status' => 'paid', 'amount' => '49.00', 'currency' => 'USD'];
    }

    private static function action(string $type, ?array $invoice = null, ?OrderState $order = null): string
    {
        return OrderAction::decide($type, $invoice ?? self::invoice(), $order ?? self::order())->action;
    }

    public function testOnlyInvoicePaidInvoicesTheOrder(): void
    {
        self::assertSame(OrderAction::ACTION_PAID, self::action('invoice.paid'));
        foreach (['invoice.processing', 'invoice.underpaid', 'invoice.expired', 'invoice.canceled', 'invoice.needs_review', 'order.updated', 'webhook.test', 'invoice.detected'] as $type) {
            self::assertNotSame(OrderAction::ACTION_PAID, self::action($type), $type);
        }
    }

    public function testProcessingIsOnlyAComment(): void
    {
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.processing', self::invoice(['status' => 'processing'])));
    }

    public function testUnderpaidHoldsTheOrder(): void
    {
        $d = OrderAction::decide('invoice.underpaid', self::invoice(['status' => 'underpaid']), self::order());
        self::assertSame(OrderAction::ACTION_HOLD, $d->action);
        self::assertSame(OrderAction::REASON_UNDERPAID, $d->reason);
        // A second underpaid while already held is a comment, not a second hold.
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.underpaid', null, self::order(['heldBy' => OrderState::HELD_UNDERPAID, 'appliedRank' => 1])));
    }

    public function testATopUpAfterUnderpaidReleasesTheHoldAndInvoices(): void
    {
        $order = self::order(['heldBy' => OrderState::HELD_UNDERPAID, 'appliedRank' => 1]);
        self::assertSame(OrderAction::ACTION_PAID, self::action('invoice.paid', null, $order));
    }

    public function testAnAmountOrCurrencyMismatchHoldsInsteadOfInvoicing(): void
    {
        $d = OrderAction::decide('invoice.paid', self::invoice(['amount' => '48.99']), self::order());
        self::assertSame(OrderAction::ACTION_HOLD, $d->action);
        self::assertSame(OrderAction::REASON_AMOUNT_MISMATCH, $d->reason);
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', self::invoice(['currency' => 'EUR'])));
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', self::invoice(['amount' => null])));
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', null, self::order(['expectedAmount' => '54.00'])));
        // Formatting differences are not a mismatch.
        self::assertSame(OrderAction::ACTION_PAID, self::action('invoice.paid', self::invoice(['amount' => '49', 'currency' => 'usd'])));
    }

    public function testLateExpiredOrCanceledNeverUndoesAPaidOrder(): void
    {
        $paid = self::order(['isPaid' => true, 'appliedRank' => 3]);
        foreach (['invoice.expired', 'invoice.canceled', 'invoice.processing', 'invoice.underpaid'] as $type) {
            self::assertSame(OrderAction::ACTION_IGNORE, self::action($type, null, $paid), $type);
        }
        // Even if the rank was never recorded, a paid order is never canceled.
        $paidNoRank = self::order(['isPaid' => true, 'appliedRank' => 0]);
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.expired', null, $paidNoRank));
    }

    public function testPaidArrivingBeforeProcessingStillWins(): void
    {
        // Out of order: paid first, then the processing that preceded it.
        self::assertSame(OrderAction::ACTION_PAID, self::action('invoice.paid'));
        $after = self::order(['isPaid' => true, 'appliedRank' => 3]);
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.processing', null, $after));
    }

    public function testExpiredCancelsAnUnpaidOrderOnce(): void
    {
        self::assertSame(OrderAction::ACTION_CANCEL, self::action('invoice.expired'));
        self::assertSame(OrderAction::ACTION_CANCEL, self::action('invoice.canceled'));
        self::assertSame(OrderAction::ACTION_CANCEL, self::action('invoice.expired', null, self::order(['heldBy' => OrderState::HELD_UNDERPAID, 'appliedRank' => 1])));
        $canceled = self::order(['isCanceled' => true, 'appliedRank' => 2]);
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.canceled', null, $canceled));
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.expired', null, self::order(['appliedRank' => 2])));
        // A late processing after expiry is stale.
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.processing', null, self::order(['appliedRank' => 2])));
    }

    public function testAnOrderHeldForReviewIsNeverCanceledOrInvoicedByAWebhook(): void
    {
        $held = self::order(['heldBy' => OrderState::HELD_REVIEW]);
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.expired', null, $held));
        self::assertSame(OrderAction::ACTION_REVIEW, self::action('invoice.paid', null, $held));
        self::assertSame(OrderAction::ACTION_REVIEW, self::action('invoice.paid', self::invoice(['amount' => '1.00']), $held));
    }

    public function testALatePaymentOnACanceledOrderIsFlaggedNotInvoiced(): void
    {
        $d = OrderAction::decide('invoice.paid', self::invoice(), self::order(['isCanceled' => true, 'appliedRank' => 2]));
        self::assertSame(OrderAction::ACTION_REVIEW, $d->action);
        self::assertSame(OrderAction::REASON_PAID_AFTER_CANCEL, $d->reason);
    }

    public function testADuplicatePaidOnAPaidOrderIsIgnored(): void
    {
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('invoice.paid', null, self::order(['isPaid' => true, 'appliedRank' => 3])));
    }

    public function testASupersededInvoiceNeverDrivesTheOrder(): void
    {
        $old = self::invoice(['id' => self::OLD_INVOICE]);
        $d = OrderAction::decide('invoice.paid', $old, self::order());
        self::assertSame(OrderAction::ACTION_HOLD, $d->action);
        self::assertSame(OrderAction::REASON_SUPERSEDED_PAID, $d->reason);
        // Even when its amount happens to match.
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', $old));
        // The canceled event our own supersede fires is a comment, not a cancellation.
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.canceled', $old));
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.expired', $old));
        self::assertSame(OrderAction::ACTION_NOTE, self::action('invoice.underpaid', $old));
        self::assertSame(OrderAction::ACTION_REVIEW, self::action('invoice.needs_review', $old));
        self::assertSame(OrderAction::ACTION_REVIEW, self::action('invoice.paid', $old, self::order(['isPaid' => true])));
    }

    public function testAnOrderWithNoInvoiceIdIsNeverPaidFromAPayload(): void
    {
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', null, self::order(['currentInvoiceId' => ''])));
        self::assertSame(OrderAction::ACTION_HOLD, self::action('invoice.paid', self::invoice(['id' => '']), self::order()));
    }

    public function testNeedsReviewAlwaysReachesAHuman(): void
    {
        foreach ([self::order(), self::order(['isPaid' => true, 'appliedRank' => 3]), self::order(['isCanceled' => true])] as $order) {
            self::assertSame(OrderAction::ACTION_REVIEW, self::action('invoice.needs_review', null, $order));
        }
    }

    public function testUnknownTypesAreIgnored(): void
    {
        self::assertFalse(OrderAction::isKnown('webhook.test'));
        self::assertSame(-1, OrderAction::rank('order.updated'));
        self::assertSame(OrderAction::ACTION_IGNORE, self::action('order.updated'));
    }
}
