<?php
/**
 * One webhook delivery, from raw body to order change.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

use IntentsFi\Checkout\Model\Signature;

/**
 * The only thing in this module that changes an order's payment state.
 *
 *  1. The HMAC signature is verified, in constant time, before the body is parsed.
 *  2. The order is found, and must belong to the store whose secret signed the body.
 *  3. Inside one transaction: the event id is claimed (a duplicate stops here), the decision is
 *     made from the order as it is now, and applied.
 *
 * Free of Magento: the order store and the ledger are interfaces.
 */
class Processor
{
    /**
     * @param list<string> $secrets Every distinct webhook secret configured on this install.
     */
    public function __construct(
        private readonly array $secrets,
        private readonly OrderPortInterface $orders,
        private readonly EventLedgerInterface $ledger,
        private readonly int $tolerance = Signature::DEFAULT_TOLERANCE
    ) {
    }

    public function handle(string $payload, string $signatureHeader, ?int $now = null): Result
    {
        $secret = $this->verifiedSecret($payload, $signatureHeader, $now);
        if ($secret === null) {
            // Non-2xx so the delivery is retried: the usual cause is a secret not pasted in yet.
            return new Result(401, ['error' => 'invalid_signature'], 'rejected: signature did not verify');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            return new Result(400, ['error' => 'invalid_payload'], 'rejected: body is not JSON');
        }

        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $eventType = is_string($event['type'] ?? null) ? $event['type'] : '';
        $invoice = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($eventId === '' || $eventType === '') {
            return new Result(400, ['error' => 'invalid_payload'], 'rejected: no event id or type');
        }

        if (!OrderAction::isKnown($eventType)) {
            // Signed, but not ours to act on (`webhook.test`, `order.updated`): acknowledge it,
            // or it is retried for a day.
            return new Result(200, ['received' => true, 'handled' => false], "ignored {$eventType}: type not handled");
        }

        if ($invoice === []) {
            return new Result(400, ['error' => 'invalid_payload'], "rejected {$eventType}: no data");
        }

        $order = $this->orders->find($invoice);
        if ($order === null) {
            return new Result(200, ['received' => true, 'handled' => false], "ignored {$eventType}: no matching order");
        }

        if (!$this->orders->secretMatches($order, $secret)) {
            // Signed by a secret on this install, but not the one for the order's store: another
            // merchant account's event naming this order. Never applied.
            return new Result(
                200,
                ['received' => true, 'handled' => false],
                "ignored {$eventType} for order {$order->incrementId}: signed for another store"
            );
        }

        return $this->orders->transaction(function () use ($eventId, $eventType, $invoice, $event): Result {
            // Re-read inside the transaction, so two deliveries at once both see the latest state.
            $order = $this->orders->find($invoice);
            if ($order === null) {
                return new Result(200, ['received' => true, 'handled' => false], "ignored {$eventType}: order vanished");
            }

            if (!$this->ledger->claim($eventId, $order->incrementId, $eventType)) {
                return new Result(
                    200,
                    ['received' => true, 'duplicate' => true],
                    "duplicate {$eventId} ({$eventType}) for order {$order->incrementId}"
                );
            }

            $decision = OrderAction::decide($eventType, $invoice, $order);
            $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';
            $rank = OrderAction::rank($eventType);
            $newRank = $order->isCurrentInvoice($invoiceId)
                && $decision->action !== OrderAction::ACTION_IGNORE
                && $rank > $order->appliedRank
                ? $rank
                : null;

            $this->orders->apply($order, $decision, $event, $newRank);

            return new Result(
                200,
                ['received' => true, 'handled' => true],
                sprintf('%s on order %s: %s (%s)', $eventType, $order->incrementId, $decision->action, $decision->reason)
            );
        });
    }

    /** The configured secret that signed this body, or null when none did. */
    private function verifiedSecret(string $payload, string $header, ?int $now): ?string
    {
        if ($payload === '' || $header === '') {
            return null;
        }
        foreach ($this->secrets as $secret) {
            if ($secret !== '' && Signature::verify($secret, $payload, $header, $this->tolerance, $now)) {
                return $secret;
            }
        }
        return null;
    }
}
