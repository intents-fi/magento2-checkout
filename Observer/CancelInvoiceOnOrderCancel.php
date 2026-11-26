<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Observer;

use IntentsFi\Checkout\Model\Api\ApiException;
use IntentsFi\Checkout\Model\ClientFactory;
use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\PaymentInfo;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * `order_cancel_after`: an order canceled in Magento (by an admin, or by the pending-payment
 * cleanup cron) closes its intents.fi invoice, so the customer can no longer pay for it.
 *
 * The API refuses while a payment is in flight, deliberately. If that payment then lands, the
 * webhook flags the canceled order for review rather than invoicing it.
 */
class CancelInvoiceOnOrderCancel implements ObserverInterface
{
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof Order || $order->getData(PaymentInfo::SKIP_INVOICE_CANCEL)) {
            return;
        }
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            return;
        }
        $invoiceId = (string) $payment->getAdditionalInformation(PaymentInfo::INVOICE_ID);
        if ($invoiceId === '') {
            return;
        }

        try {
            $this->clientFactory->forStore((int) $order->getStoreId())->cancelInvoice($invoiceId);
            $order->addCommentToStatusHistory((string) __('The Intents payment %1 was closed, so it can no longer be paid.', $invoiceId));
        } catch (ApiException $e) {
            $order->addCommentToStatusHistory((string) __(
                'The Intents payment %1 could not be closed: %2. If the customer pays it, this order is flagged for you to review.',
                $invoiceId,
                $e->getMessage()
            ));
            $this->logger->info(sprintf('invoice %s for canceled order %s not closed: %s', $invoiceId, $order->getIncrementId(), $e->getApiCode()));
        }
    }
}
