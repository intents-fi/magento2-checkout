<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model\Webhook;

use IntentsFi\Checkout\Model\Webhook\Decision;
use IntentsFi\Checkout\Model\Webhook\EventLedgerInterface;
use IntentsFi\Checkout\Model\Webhook\OrderAction;
use IntentsFi\Checkout\Model\Webhook\OrderPortInterface;
use IntentsFi\Checkout\Model\Webhook\OrderState;

/**
 * An order store and ledger with the semantics the Magento ones have: apply() moves the order the
 * way the real port does, and transaction() rolls everything back if the work throws.
 */
final class InMemoryOrders implements OrderPortInterface, EventLedgerInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $orders = [];

    /** @var array<string, true> */
    public array $claimed = [];

    /** @var list<string> */
    public array $applied = [];

    public ?\Throwable $failNextApply = null;

    public function add(string $incrementId, string $invoiceId, string $secret, string $amount = '49.00'): void
    {
        $this->orders[$incrementId] = [
            'invoice' => $invoiceId,
            'secret' => $secret,
            'amount' => $amount,
            'state' => 'pending_payment',
            'heldBy' => OrderState::HELD_NONE,
            'rank' => 0,
            'comments' => [],
        ];
    }

    public function find(array $invoice): ?OrderState
    {
        $id = is_array($invoice['metadata'] ?? null) ? ($invoice['metadata']['magento_increment_id'] ?? '') : '';
        if (!isset($this->orders[$id])) {
            return null;
        }
        $o = $this->orders[$id];
        return new OrderState(
            $id,
            in_array($o['state'], ['processing', 'complete'], true),
            $o['state'] === 'canceled',
            $o['heldBy'],
            $o['invoice'],
            $o['amount'],
            'USD',
            $o['rank']
        );
    }

    public function secretMatches(OrderState $order, string $secret): bool
    {
        return hash_equals($this->orders[$order->incrementId]['secret'], $secret);
    }

    public function apply(OrderState $order, Decision $decision, array $event, ?int $newRank): void
    {
        if ($this->failNextApply !== null) {
            $e = $this->failNextApply;
            $this->failNextApply = null;
            throw $e;
        }
        $o = &$this->orders[$order->incrementId];
        $this->applied[] = $event['id'] . ':' . $decision->action;
        switch ($decision->action) {
            case OrderAction::ACTION_PAID:
                $o['state'] = 'processing';
                $o['heldBy'] = OrderState::HELD_NONE;
                break;
            case OrderAction::ACTION_HOLD:
                $o['state'] = 'holded';
                $o['heldBy'] = $decision->reason === OrderAction::REASON_UNDERPAID
                    ? OrderState::HELD_UNDERPAID
                    : OrderState::HELD_REVIEW;
                break;
            case OrderAction::ACTION_CANCEL:
                $o['state'] = 'canceled';
                $o['heldBy'] = OrderState::HELD_NONE;
                break;
        }
        $o['comments'][] = $decision->reason;
        if ($newRank !== null) {
            $o['rank'] = $newRank;
        }
    }

    public function transaction(callable $work)
    {
        $snapshot = [$this->orders, $this->claimed, $this->applied];
        try {
            return $work();
        } catch (\Throwable $e) {
            [$this->orders, $this->claimed, $this->applied] = $snapshot;
            throw $e;
        }
    }

    public function claim(string $eventId, string $incrementId, string $eventType): bool
    {
        if (isset($this->claimed[$eventId])) {
            return false;
        }
        $this->claimed[$eventId] = true;
        return true;
    }
}
