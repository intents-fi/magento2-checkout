<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;

/**
 * Runs when the order is placed (`can_initialize`). It only parks the order at
 * pending_payment: nothing is charged, nothing is invoiced, and the order confirmation email waits
 * until the payment is confirmed. The invoice is created by Controller/Checkout/Redirect, after the
 * order exists, so a failed API call never rolls back a placed order.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $stateObject = $commandSubject['stateObject'] ?? null;

        $payment = $paymentDO->getPayment();
        if ($payment instanceof Order\Payment) {
            // Sent by the webhook once intents.fi confirms the payment.
            $payment->getOrder()->setCanSendNewEmailFlag(false);
            $payment->setIsTransactionPending(true);
        }

        if ($stateObject instanceof \Magento\Framework\DataObject) {
            $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
            $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
            $stateObject->setData('is_notified', false);
        }
        return null;
    }
}
