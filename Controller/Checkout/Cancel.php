<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Checkout;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\InvoiceManager;
use IntentsFi\Checkout\Model\PaymentInfo;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * `GET /intentsfi/checkout/cancel`: the customer left the hosted page without paying.
 *
 * The invoice is closed through the API first. Only if that succeeds — nothing is on its way — is
 * the order canceled and the cart restored. If the API refuses, a payment may be in flight, so the
 * order is left alone and the customer is told it is being confirmed.
 */
class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly InvoiceManager $invoiceManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order instanceof Order || !$order->getEntityId()
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== Config::METHOD_CODE
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
        ) {
            return $redirect->setPath('checkout/cart');
        }

        if (!$this->invoiceManager->cancelForCustomer($order)) {
            $this->messageManager->addNoticeMessage(
                __('Your payment may already be on its way. We will confirm your order by email as soon as it arrives.')
            );
            return $redirect->setPath('checkout/onepage/success');
        }

        try {
            if ($order->canCancel()) {
                // The invoice was closed a moment ago; the cancel observer must not try again.
                $order->setData(PaymentInfo::SKIP_INVOICE_CANCEL, true);
                $order->cancel();
                $order->addCommentToStatusHistory((string) __('The customer left the payment page without paying. Order canceled and cart restored.'));
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('could not cancel order %s on customer return: %s', $order->getIncrementId(), $e->getMessage()));
        }
        $this->checkoutSession->restoreQuote();
        $this->messageManager->addNoticeMessage(__('Your payment was not completed. Your cart is ready when you are.'));
        return $redirect->setPath('checkout/cart');
    }
}
