<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * The keys this module keeps in the order payment's `additional_information`.
 */
class PaymentInfo
{
    /** The intents.fi invoice the order is waiting on. Only {@see InvoiceManager} writes it. */
    public const INVOICE_ID = 'intentsfi_invoice_id';
    public const INVOICE_URL = 'intentsfi_invoice_url';
    public const REFERENCE = 'intentsfi_reference';
    /** The order total the invoice was created for, as sent to the API. */
    public const AMOUNT = 'intentsfi_amount';
    /** `test` or `live`: the mode of the key that created the invoice. */
    public const MODE = 'intentsfi_mode';
    /** How many invoices this order has needed; grows only when an invoice is superseded. */
    public const SEQUENCE = 'intentsfi_sequence';
    /** The highest event rank applied from a webhook. */
    public const EVENT_RANK = 'intentsfi_event_rank';
    /** Why this module put the order on hold: `underpaid` or `review`. */
    public const HELD_BY = 'intentsfi_held_by';
    public const PAID_ASSET = 'intentsfi_paid_asset';
    public const PAYER_ADDRESS = 'intentsfi_payer_address';
    public const ORIGIN_TX = 'intentsfi_origin_tx';
    public const SETTLEMENT_TX = 'intentsfi_settlement_tx';

    /** Order data flag: the order is being canceled because its invoice already is. */
    public const SKIP_INVOICE_CANCEL = 'intentsfi_skip_invoice_cancel';

    /**
     * The value bound into each invoice's metadata so a webhook can only ever move the order it
     * was created for. Derived from the order's `protect_code`, which never leaves the store.
     */
    public static function orderCheck(string $incrementId, string $protectCode): string
    {
        return hash('sha256', 'intentsfi|' . $incrementId . '|' . $protectCode);
    }
}
