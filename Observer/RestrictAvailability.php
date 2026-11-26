<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Observer;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\CurrencySupport;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;

/**
 * `payment_method_is_active`: hide the method when it cannot complete a payment — no valid
 * secret key for the store, or a cart not priced and charged in USD. Nothing is ever converted.
 */
class RestrictAvailability implements ObserverInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $method = $event->getData('method_instance');
        $result = $event->getData('result');
        if ($method === null || $result === null || $method->getCode() !== Config::METHOD_CODE) {
            return;
        }
        if (!$result->getData('is_available')) {
            return;
        }

        $quote = $event->getData('quote');
        $storeId = $quote instanceof Quote ? (int) $quote->getStoreId() : null;

        if ($this->config->mode($storeId) === null) {
            $result->setData('is_available', false);
            return;
        }

        if ($quote instanceof Quote) {
            $problem = CurrencySupport::problem(
                (string) $quote->getBaseCurrencyCode(),
                (string) $quote->getQuoteCurrencyCode()
            );
            if ($problem !== null) {
                $result->setData('is_available', false);
            }
        }
    }
}
