<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Checkout;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\InvoiceManager;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * `GET /intentsfi/checkout/redirect`: the checkout comes here once the order is placed. It sends
 * the customer to the hosted payment page for the session's last order. Reloading it, or coming
 * back to it, lands on the same invoice (the reference is the idempotency key).
 */
class Redirect implements HttpGetActionInterface
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
        ) {
            return $redirect->setPath('checkout/cart');
        }

        try {
            return $redirect->setUrl($this->invoiceManager->paymentUrl($order));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('We could not start your payment. Please try again, or email %1.', Config::SUPPORT_EMAIL)
            );
        }

        // No payment page: give the customer their cart back, and close this order so it cannot
        // be paid later. Closing it also closes any invoice that was made (the cancel observer).
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            try {
                if ($order->canCancel()) {
                    $order->cancel();
                    $order->addCommentToStatusHistory((string) __('The payment page could not be opened, so the order was canceled and the customer\'s cart restored.'));
                    $this->orderRepository->save($order);
                }
            } catch (\Exception $e) {
                $this->logger->error(sprintf('could not cancel order %s after a failed redirect: %s', $order->getIncrementId(), $e->getMessage()));
            }
            $this->checkoutSession->restoreQuote();
        }
        return $redirect->setPath('checkout/cart');
    }
}
