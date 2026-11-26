<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Gateway\Validator;

use IntentsFi\Checkout\Model\CurrencySupport;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * The Adapter's `currency` validator. Magento passes the store's base currency here; the
 * currency the customer is charged in is checked by Observer/RestrictAvailability.
 */
class CurrencyValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = strtoupper((string) ($validationSubject['currency'] ?? ''));
        $ok = $currency === CurrencySupport::SUPPORTED;
        return $this->createResult($ok, $ok ? [] : [__('Intents invoices are priced in USD only.')]);
    }
}
