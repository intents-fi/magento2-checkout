<?php
/**
 * What one webhook does to one order.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

class Decision
{
    public function __construct(
        /** One of the OrderAction::ACTION_* constants. */
        public readonly string $action,
        /** One of the OrderAction::REASON_* constants: why, for the order comment and the logs. */
        public readonly string $reason
    ) {
    }
}
