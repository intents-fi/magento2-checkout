<?php
/**
 * Applies webhook decisions to Magento orders.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Webhook;

use IntentsFi\Checkout\Model\Amount;
use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\InvoicePlan;
use IntentsFi\Checkout\Model\PaymentInfo;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Psr\Log\LoggerInterface;

/**
 * {@see OrderPortInterface} for Magento. The decision itself is {@see OrderAction}; this class
 * only finds the order, describes it, and carries the decision out.
 */
class MagentoOrderPort implements OrderPortInterface
{
    /** @var array<int, Order> Orders loaded in the current unit of work, by entity id. */
    private array $loaded = [];

    private bool $inTransaction = false;

    public function __construct(
        private readonly CollectionFactory $orderCollectionFactory,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly OrderSender $orderSender,
        private readonly NotifierInterface $notifier,
        private readonly UrlInterface $backendUrl,
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function find(array $invoice): ?OrderState
    {
        $order = $this->load($invoice);
        if ($order === null) {
            return null;
        }
        $payment = $order->getPayment();
        $state = (string) $order->getState();

        $heldBy = OrderState::HELD_NONE;
        if ($state === Order::STATE_HOLDED) {
            // An order held by an admin, or for any reason we did not record, is a human's.
            $heldBy = (string) $payment->getAdditionalInformation(PaymentInfo::HELD_BY) === OrderState::HELD_UNDERPAID
                ? OrderState::HELD_UNDERPAID
                : OrderState::HELD_REVIEW;
        }

        return new OrderState(
            (string) $order->getIncrementId(),
            $order->hasInvoices() || in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true),
            $state === Order::STATE_CANCELED,
            $heldBy,
            (string) $payment->getAdditionalInformation(PaymentInfo::INVOICE_ID),
            Amount::format((string) $order->getGrandTotal()),
            (string) $order->getOrderCurrencyCode(),
            (int) $payment->getAdditionalInformation(PaymentInfo::EVENT_RANK)
        );
    }

    public function secretMatches(OrderState $state, string $secret): bool
    {
        $order = $this->loadByIncrementId($state->incrementId, null);
        if ($order === null) {
            return false;
        }
        $own = $this->config->webhookSecret((int) $order->getStoreId());
        return $own !== '' && hash_equals($own, $secret);
    }

    public function transaction(callable $work)
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        // Everything read before this point is stale: reload, under a row lock, inside.
        $this->loaded = [];
        $this->inTransaction = true;
        try {
            $result = $work();
            $connection->commit();
            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        } finally {
            $this->inTransaction = false;
            $this->loaded = [];
        }
    }

    public function apply(OrderState $state, Decision $decision, array $event, ?int $newRank): void
    {
        $invoice = is_array($event['data'] ?? null) ? $event['data'] : [];
        $order = $this->load($invoice);
        if ($order === null) {
            return;
        }
        $payment = $order->getPayment();
        $eventType = (string) ($event['type'] ?? '');
        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';

        if ($newRank !== null) {
            $payment->setAdditionalInformation(PaymentInfo::EVENT_RANK, (string) $newRank);
        }

        switch ($decision->action) {
            case OrderAction::ACTION_PAID:
                $this->markPaid($order, $invoice, (bool) ($event['late'] ?? false));
                return;

            case OrderAction::ACTION_HOLD:
                $note = Notes::hold($decision->reason, $invoice, $state);
                $heldBy = $decision->reason === OrderAction::REASON_UNDERPAID ? OrderState::HELD_UNDERPAID : OrderState::HELD_REVIEW;
                if ($order->canHold()) {
                    $order->hold();
                    $payment->setAdditionalInformation(PaymentInfo::HELD_BY, $heldBy);
                } elseif ($order->getState() === Order::STATE_HOLDED && $heldBy === OrderState::HELD_REVIEW) {
                    // Already on hold; it now needs a human whatever held it.
                    $payment->setAdditionalInformation(PaymentInfo::HELD_BY, $heldBy);
                }
                $order->addCommentToStatusHistory($note);
                $this->notify($order, $note, $heldBy === OrderState::HELD_REVIEW);
                break;

            case OrderAction::ACTION_CANCEL:
                $note = Notes::cancel($eventType);
                if ($order->getState() === Order::STATE_HOLDED && $order->canUnhold()) {
                    $order->unhold();
                }
                if ($order->canCancel()) {
                    // The invoice is already expired or canceled; do not ask the API to cancel it again.
                    $order->setData(PaymentInfo::SKIP_INVOICE_CANCEL, true);
                    $order->cancel();
                    $payment->unsAdditionalInformation(PaymentInfo::HELD_BY);
                }
                $order->addCommentToStatusHistory($note);
                break;

            case OrderAction::ACTION_REVIEW:
                $note = Notes::review($decision->reason, $invoice, $state);
                $order->addCommentToStatusHistory($note);
                $this->notify($order, $note, true);
                break;

            case OrderAction::ACTION_NOTE:
                $order->addCommentToStatusHistory(Notes::note($decision->reason, $eventType, $invoice));
                break;

            case OrderAction::ACTION_IGNORE:
            default:
                $order->addCommentToStatusHistory(
                    (string) __('Intents sent %1 after a later update; no change made.', $eventType)
                );
                break;
        }

        $this->orderRepository->save($order);
        $this->logger->info(sprintf('%s on order %s (invoice %s): %s', $eventType, $order->getIncrementId(), $invoiceId, $decision->reason));
    }

    /** Invoice the order offline and move it to processing. Only ever reached on invoice.paid. */
    private function markPaid(Order $order, array $invoice, bool $late): void
    {
        $payment = $order->getPayment();
        $invoiceId = (string) ($invoice['id'] ?? '');

        if ($order->getState() === Order::STATE_HOLDED && $order->canUnhold()) {
            // Only an underpaid hold reaches here (OrderAction never pays a review hold).
            $order->unhold();
        }
        $payment->unsAdditionalInformation(PaymentInfo::HELD_BY);
        $this->recordPaymentDetails($payment, $invoice);

        if (!$order->canInvoice()) {
            $note = (string) __('Intents confirmed payment %1, but Magento cannot invoice this order in its current state. Please check it.', $invoiceId);
            $order->addCommentToStatusHistory($note);
            $this->orderRepository->save($order);
            $this->notify($order, $note, true);
            return;
        }

        $mageInvoice = $this->invoiceService->prepareInvoice($order);
        // The funds are already at the merchant's settlement address: nothing to capture online.
        $mageInvoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $mageInvoice->setTransactionId($invoiceId);
        $mageInvoice->register();

        $payment->setTransactionId($invoiceId);
        $payment->setLastTransId($invoiceId);

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus((string) $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        $order->addCommentToStatusHistory(Notes::paid($invoice, $late));

        $this->transactionFactory->create()
            ->addObject($mageInvoice)
            ->addObject($order)
            ->save();

        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->error(sprintf('order email for %s not sent: %s', $order->getIncrementId(), $e->getMessage()));
            }
        }
        $this->logger->info(sprintf('invoice.paid on order %s (invoice %s): invoiced', $order->getIncrementId(), $invoiceId));
    }

    private function recordPaymentDetails(\Magento\Sales\Model\Order\Payment $payment, array $invoice): void
    {
        $payer = is_array($invoice['payer'] ?? null) ? $invoice['payer'] : [];
        if (is_string($payer['asset'] ?? null) && $payer['asset'] !== '') {
            $payment->setAdditionalInformation(PaymentInfo::PAID_ASSET, $payer['asset']);
        }
        if (is_string($payer['address'] ?? null) && $payer['address'] !== '') {
            $payment->setAdditionalInformation(PaymentInfo::PAYER_ADDRESS, $payer['address']);
        }
        $txs = is_array($invoice['txs'] ?? null) ? $invoice['txs'] : [];
        foreach (['origin' => PaymentInfo::ORIGIN_TX, 'settlement' => PaymentInfo::SETTLEMENT_TX] as $kind => $key) {
            $list = is_array($txs[$kind] ?? null) ? array_values(array_filter($txs[$kind], 'is_string')) : [];
            if ($list !== []) {
                $payment->setAdditionalInformation($key, implode(',', $list));
            }
        }
    }

    private function notify(Order $order, string $note, bool $major): void
    {
        $title = (string) __('Intents Checkout: order #%1 needs a look', $order->getIncrementId());
        $url = $this->backendUrl->getUrl('sales/order/view', ['order_id' => $order->getEntityId()]);
        try {
            $major
                ? $this->notifier->addMajor($title, $note, $url)
                : $this->notifier->addNotice($title, $note, $url);
        } catch (\Exception $e) {
            $this->logger->error(sprintf('admin notice for order %s not added: %s', $order->getIncrementId(), $e->getMessage()));
        }
    }

    /**
     * The order this invoice was created for: found by increment id (from the metadata, else the
     * reference), paid with this method, and carrying the same order check the invoice was made
     * with. Anything else is not ours.
     */
    private function load(array $invoice): ?Order
    {
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $incrementId = is_string($metadata['magento_increment_id'] ?? null) ? $metadata['magento_increment_id'] : '';
        if ($incrementId === '' && is_string($invoice['reference'] ?? null)) {
            $incrementId = (string) InvoicePlan::incrementIdFromReference($invoice['reference']);
        }
        $check = is_string($metadata['magento_order_check'] ?? null) ? $metadata['magento_order_check'] : '';
        if ($incrementId === '' || $check === '') {
            return null;
        }
        $storeId = is_string($metadata['magento_store_id'] ?? null) && ctype_digit($metadata['magento_store_id'])
            ? (int) $metadata['magento_store_id']
            : null;

        $order = $this->loadByIncrementId($incrementId, $storeId);
        if ($order === null || $order->getPayment() === null || $order->getPayment()->getMethod() !== Config::METHOD_CODE) {
            return null;
        }
        if (!hash_equals(PaymentInfo::orderCheck($incrementId, (string) $order->getProtectCode()), $check)) {
            $this->logger->error(sprintf('webhook for order %s rejected: order check does not match', $incrementId));
            return null;
        }
        return $order;
    }

    private function loadByIncrementId(string $incrementId, ?int $storeId): ?Order
    {
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $incrementId)
            ->setPageSize(2);
        if ($storeId !== null) {
            $collection->addFieldToFilter('store_id', $storeId);
        }
        if ($collection->getSize() !== 1) {
            // None, or the same increment id in two stores with no store id to tell them apart.
            return null;
        }
        $id = (int) $collection->getFirstItem()->getEntityId();
        if (isset($this->loaded[$id])) {
            return $this->loaded[$id];
        }
        if ($this->inTransaction) {
            // Two deliveries for one order at once wait for each other here, so the second
            // decides from what the first wrote.
            $connection = $this->resource->getConnection();
            $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order'), ['entity_id'])
                    ->where('entity_id = ?', $id)
                    ->forUpdate(true)
            );
        }
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $id);
        if (!$order->getEntityId()) {
            return null;
        }
        return $this->loaded[$id] = $order;
    }
}
