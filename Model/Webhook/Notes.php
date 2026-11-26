<?php
/**
 * The order comments a webhook leaves.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

use IntentsFi\Checkout\Model\SettlementAsset;

/**
 * Merchant-facing copy for order comments and admin notices. Merchants read these, so they say
 * what happened and what to do, in the same words the WooCommerce plugin uses.
 */
class Notes
{
    public static function paid(array $invoice, bool $late): string
    {
        $id = self::str($invoice, 'id');
        $note = (string) __('Payment confirmed by Intents (%1). The funds have been delivered to your settlement wallet.', $id);

        $settlement = is_array($invoice['settlement'] ?? null) ? $invoice['settlement'] : [];
        $asset = self::str($settlement, 'asset');
        $net = self::str($settlement, 'amountNet');
        if ($asset !== '' && $net !== '') {
            $note .= ' ' . (string) __('Settled: %1 to your wallet, after the Intents fee.', SettlementAsset::format($net, $asset));
        }
        if (($invoice['overpaid'] ?? false) === true) {
            $note .= ' ' . (string) __('The customer sent more than the order total; the extra is shown in your Intents dashboard.');
        }
        if ($late) {
            $note .= ' ' . (string) __('The payment arrived after the payment window closed.');
        }
        return $note;
    }

    public static function hold(string $reason, array $invoice, OrderState $order): string
    {
        $id = self::str($invoice, 'id');
        $paid = trim(self::str($invoice, 'amount') . ' ' . self::str($invoice, 'currency'));
        $expected = $order->expectedAmount . ' ' . $order->expectedCurrency;

        switch ($reason) {
            case OrderAction::REASON_UNDERPAID:
                $note = (string) __('The customer paid part of payment %1. The order is on hold until the rest arrives; it moves to Processing on its own when it does.', $id);
                $settlement = is_array($invoice['settlement'] ?? null) ? $invoice['settlement'] : [];
                $remaining = self::str($settlement, 'amountRemaining');
                if ($remaining !== '') {
                    $note .= ' ' . (string) __('Still owed: %1.', SettlementAsset::format($remaining, self::str($settlement, 'asset')));
                }
                return $note;

            case OrderAction::REASON_SUPERSEDED_PAID:
                return (string) __(
                    'The customer paid an earlier payment for this order (%1), not the current one. The order is on hold: check the amount before fulfilling. %2 was paid; this order is for %3.',
                    $id,
                    $paid !== '' ? $paid : (string) __('an unknown amount'),
                    $expected
                );

            case OrderAction::REASON_AMOUNT_MISMATCH:
            default:
                return (string) __(
                    'Intents confirmed payment %1 for %2, but this order is for %3. The order is on hold: check it before fulfilling.',
                    $id,
                    $paid !== '' ? $paid : (string) __('an unknown amount'),
                    $expected
                );
        }
    }

    public static function review(string $reason, array $invoice, OrderState $order): string
    {
        $id = self::str($invoice, 'id');
        switch ($reason) {
            case OrderAction::REASON_PAID_AFTER_CANCEL:
                return (string) __('Intents confirmed payment %1 after this order was canceled. The funds are in your settlement wallet. Decide whether to fulfil it as a new order or refund the customer.', $id);
            case OrderAction::REASON_PAID_WHILE_HELD:
                return (string) __('Intents confirmed payment %1 while this order is on hold. It was left on hold: release and invoice it yourself once you have checked it.', $id);
            case OrderAction::REASON_AMOUNT_MISMATCH:
                return self::hold($reason, $invoice, $order);
            case OrderAction::REASON_SUPERSEDED_PAID:
                return (string) __('The customer also paid an earlier payment for this order (%1). Check your Intents dashboard and refund the duplicate.', $id);
            case OrderAction::REASON_NEEDS_REVIEW:
            default:
                return (string) __('Intents flagged payment %1 for review. Open it in your Intents dashboard to see why.', $id);
        }
    }

    public static function cancel(string $eventType): string
    {
        return $eventType === 'invoice.expired'
            ? (string) __('The Intents payment window closed without a payment. Order canceled.')
            : (string) __('The Intents payment was canceled. Order canceled.');
    }

    public static function note(string $reason, string $eventType, array $invoice): string
    {
        $id = self::str($invoice, 'id');
        switch ($reason) {
            case OrderAction::REASON_PROCESSING:
                return (string) __('The customer has sent payment %1; waiting for it to be confirmed. Do not ship yet.', $id);
            case OrderAction::REASON_UNDERPAID:
                return self::hold($reason, $invoice, new OrderState('', false, false, '', '', '', '', 0));
            case OrderAction::REASON_HELD_FOR_REVIEW:
                return (string) __('Intents sent %1 for payment %2; the order is on hold for review, so it was not canceled.', $eventType, $id);
            case OrderAction::REASON_SUPERSEDED:
            default:
                return (string) __('Intents sent %1 for an earlier payment of this order (%2). It no longer drives this order; no change made.', $eventType, $id);
        }
    }

    private static function str(array $a, string $k): string
    {
        return is_string($a[$k] ?? null) ? $a[$k] : '';
    }
}
