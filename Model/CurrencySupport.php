<?php
/**
 * Whether a store's currencies can be used for an invoice.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * Invoices are priced in USD only (SPEC-checkout §4.2, `currency: "USD"`). The module never
 * converts: a USD display price over a EUR base currency is a figure Magento converted at a rate
 * the merchant keyed in, and an invoice for it would charge an amount the books do not hold. So
 * both the base currency and the currency the customer is charged in must be USD, or the method
 * is hidden and the admin is told why.
 */
class CurrencySupport
{
    public const SUPPORTED = 'USD';

    /**
     * Why these currencies cannot be used, or null when they can.
     *
     * @param string $baseCurrency  The website's base currency (what the books are kept in).
     * @param string $orderCurrency The currency the customer is charged in (quote / order currency).
     */
    public static function problem(string $baseCurrency, string $orderCurrency): ?string
    {
        $base = strtoupper(trim($baseCurrency));
        $order = strtoupper(trim($orderCurrency));

        if ($base !== self::SUPPORTED) {
            return sprintf(
                'The base currency is %s. intents.fi invoices are priced in %s and this module never converts prices, so the payment method stays hidden at checkout until the base currency is %s.',
                $base === '' ? 'not set' : $base,
                self::SUPPORTED,
                self::SUPPORTED
            );
        }
        if ($order !== self::SUPPORTED) {
            return sprintf(
                'Customers are being charged in %s. intents.fi invoices are priced in %s and this module never converts prices, so the payment method is hidden for %s carts.',
                $order === '' ? 'an unknown currency' : $order,
                self::SUPPORTED,
                $order === '' ? 'these' : $order
            );
        }
        return null;
    }
}
