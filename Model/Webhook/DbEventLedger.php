<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/** {@see EventLedgerInterface} on the `intentsfi_checkout_webhook_event` table and its unique key. */
class DbEventLedger implements EventLedgerInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function claim(string $eventId, string $incrementId, string $eventType): bool
    {
        $connection = $this->resource->getConnection();
        try {
            $connection->insert($this->resource->getTableName('intentsfi_checkout_webhook_event'), [
                'event_id' => mb_substr($eventId, 0, 128),
                'event_type' => mb_substr($eventType, 0, 64),
                'order_increment_id' => mb_substr($incrementId, 0, 64),
            ]);
            return true;
        } catch (DuplicateException $e) {
            return false;
        }
    }
}
