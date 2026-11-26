<?php
/**
 * Creates, reuses and supersedes the intents.fi invoice for an order.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

use IntentsFi\Checkout\Model\Api\ApiException;
use IntentsFi\Checkout\Model\Api\Client;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The invoice's `reference` is the order's increment id plus a store key ({@see InvoicePlan}), and
 * the API treats it as an idempotency key, so a customer who reloads or retries lands on the same
 * invoice and is never charged twice. An invoice whose metadata names another order is never used.
 * When the invoice can no longer be paid for what the order asks (SPEC-checkout §11.30) the order
 * gets `<increment id>-<store key>-r2`, then `-r3`, and the old invoice is canceled only after the order
 * points at the new one.
 */
class InvoiceManager
{
    public function __construct(
        private readonly Config $config,
        private readonly ClientFactory $clientFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The hosted payment page for this order: its current invoice, or a new one.
     *
     * @throws LocalizedException With a message safe to show the customer.
     */
    public function paymentUrl(Order $order): string
    {
        $storeId = (int) $order->getStoreId();
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::METHOD_CODE) {
            throw new LocalizedException(__('This order is not paid with Intents Checkout.'));
        }
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT && $order->getState() !== Order::STATE_NEW) {
            throw new LocalizedException(__('This order is no longer waiting for payment.'));
        }

        $currencyProblem = CurrencySupport::problem(
            (string) $order->getBaseCurrencyCode(),
            (string) $order->getOrderCurrencyCode()
        );
        if ($currencyProblem !== null) {
            $this->logger->error(sprintf('order %s not sent to intents.fi: %s', $order->getIncrementId(), $currencyProblem));
            throw new LocalizedException(__('This payment method is not available for this order\'s currency.'));
        }

        $apiKey = $this->config->apiKey($storeId);
        $mode = KeyMode::detect($apiKey);
        if ($mode === null) {
            $this->logger->error(sprintf('order %s not sent to intents.fi: %s', $order->getIncrementId(), (string) KeyMode::problem($apiKey)));
            throw new LocalizedException(__('This payment method is not set up yet. Please choose another one.'));
        }

        $client = $this->clientFactory->create($apiKey);
        $amount = Amount::format((string) $order->getGrandTotal());

        $incrementId = (string) $order->getIncrementId();
        $orderCheck = PaymentInfo::orderCheck($incrementId, (string) $order->getProtectCode());
        $storeKey = InvoicePlan::storeKey(
            (string) $this->storeManager->getStore((int) $order->getStoreId())->getBaseUrl(UrlInterface::URL_TYPE_WEB)
        );

        $stored = [
            'invoice_id' => (string) $payment->getAdditionalInformation(PaymentInfo::INVOICE_ID),
            'amount' => (string) $payment->getAdditionalInformation(PaymentInfo::AMOUNT),
            'mode' => (string) $payment->getAdditionalInformation(PaymentInfo::MODE),
        ];
        $plan = InvoicePlan::assess($stored, $amount, $mode, static function (string $id) use ($client): ?array {
            try {
                return $client->getInvoice($id);
            } catch (ApiException $e) {
                return null;
            }
        }, null, ['increment_id' => $incrementId, 'order_check' => $orderCheck]);

        if ($plan['state'] === InvoicePlan::REUSE) {
            return (string) $plan['invoice']['url'];
        }

        $previousId = $stored['invoice_id'];
        $previousMode = $stored['mode'];
        // A FOREIGN invoice is someone else's: it is moved off, never canceled.
        $supersede = $plan['state'] === InvoicePlan::STALE && $previousId !== '';

        $sequence = max(1, (int) $payment->getAdditionalInformation(PaymentInfo::SEQUENCE));
        if ($supersede || $plan['state'] === InvoicePlan::FOREIGN) {
            $sequence++;
        }
        if ($plan['state'] === InvoicePlan::FOREIGN) {
            $this->logger->error(sprintf('stored invoice %s does not belong to order %s; creating a new one', $previousId, $incrementId));
        }

        try {
            $created = InvoicePlan::createOwned(
                fn (string $reference): array => $client->createInvoice($this->invoiceBody($order, $amount, $reference)),
                $incrementId,
                $storeKey,
                $sequence,
                $orderCheck
            );
        } catch (ApiException $e) {
            $order->addCommentToStatusHistory(
                (string) __('intents.fi could not create a payment: %1', $e->getMessage())
            );
            $this->orderRepository->save($order);
            throw new LocalizedException(__('We could not start your payment. Please try again, or email %1.', Config::SUPPORT_EMAIL));
        } catch (\RuntimeException $e) {
            // Every reference tried came back as another order's invoice: never redirect through one.
            $this->logger->error($e->getMessage());
            $order->addCommentToStatusHistory(
                (string) __('intents.fi returned a payment made for a different order, so it was not used. Check that no other store shares this intents.fi key with the same base URL.')
            );
            $this->orderRepository->save($order);
            throw new LocalizedException(__('We could not start your payment. Please try again, or email %1.', Config::SUPPORT_EMAIL));
        }
        $invoice = $created['invoice'];
        $reference = $created['reference'];
        $sequence = $created['sequence'];

        $invoiceId = is_string($invoice['id'] ?? null) ? $invoice['id'] : '';
        $url = is_string($invoice['url'] ?? null) ? $invoice['url'] : '';
        if ($invoiceId === '' || preg_match('#^https://#', $url) !== 1) {
            $this->logger->error(sprintf('invoice for order %s came back without an id or an https url', $order->getIncrementId()));
            throw new LocalizedException(__('We could not start your payment. Please try again, or email %1.', Config::SUPPORT_EMAIL));
        }

        $payment->setAdditionalInformation(PaymentInfo::INVOICE_ID, $invoiceId);
        $payment->setAdditionalInformation(PaymentInfo::INVOICE_URL, $url);
        $payment->setAdditionalInformation(PaymentInfo::REFERENCE, $reference);
        $payment->setAdditionalInformation(PaymentInfo::AMOUNT, $amount);
        $payment->setAdditionalInformation(PaymentInfo::MODE, $mode);
        $payment->setAdditionalInformation(PaymentInfo::SEQUENCE, (string) $sequence);
        if ($supersede) {
            // A new invoice has its own lifecycle.
            $payment->setAdditionalInformation(PaymentInfo::EVENT_RANK, '0');
        }
        $order->addCommentToStatusHistory(
            (string) __(
                'intents.fi payment %1 created for %2 USD (%3 mode, reference %4). Waiting for the customer to pay.',
                $invoiceId,
                $amount,
                $mode,
                $reference
            )
        );
        $this->orderRepository->save($order);
        $this->logger->info(sprintf('created invoice %s (%s) for order %s', $invoiceId, $reference, $order->getIncrementId()));

        // Only now, with the order pointing at the new invoice, is the old one closed: the other
        // way round, its invoice.canceled webhook could arrive while the order still pointed at it.
        if ($supersede) {
            $this->cancelSuperseded($order, $client, $previousId, $previousMode, $mode);
        }

        return $url;
    }

    /**
     * The customer left the payment page without paying. Close the invoice, and report whether
     * that worked: false means a payment may be on its way, so the order must stay as it is.
     */
    public function cancelForCustomer(Order $order): bool
    {
        $payment = $order->getPayment();
        $invoiceId = $payment !== null ? (string) $payment->getAdditionalInformation(PaymentInfo::INVOICE_ID) : '';
        if ($invoiceId === '') {
            return true;
        }
        try {
            $this->clientFactory->forStore((int) $order->getStoreId())->cancelInvoice($invoiceId);
            return true;
        } catch (ApiException $e) {
            // INVOICE_NOT_OPEN: a payment is in flight or done. Anything else: we cannot tell.
            $this->logger->info(sprintf('invoice %s for order %s not canceled on customer return: %s', $invoiceId, $order->getIncrementId(), $e->getApiCode()));
            return false;
        }
    }

    /** @return array<string, mixed> The `POST /v0/invoices` body (SPEC-checkout §4.2). */
    private function invoiceBody(Order $order, string $amount, string $reference): array
    {
        $store = $this->storeManager->getStore((int) $order->getStoreId());
        $incrementId = (string) $order->getIncrementId();
        $storeName = trim((string) $store->getFrontendName());

        $body = [
            'amount' => $amount,
            'currency' => CurrencySupport::SUPPORTED,
            'reference' => $reference,
            'description' => mb_substr(
                (string) __('Order #%1 at %2', $incrementId, $storeName !== '' ? $storeName : (string) $store->getName()),
                0,
                500
            ),
            'successUrl' => $this->url->getUrl('intentsfi/checkout/success', ['_scope' => $store->getId(), '_nosid' => true, '_secure' => true]),
            'cancelUrl' => $this->url->getUrl('intentsfi/checkout/cancel', ['_scope' => $store->getId(), '_nosid' => true, '_secure' => true]),
            'metadata' => [
                'source' => 'magento',
                'module_version' => Config::VERSION,
                'magento_increment_id' => $incrementId,
                'magento_store_id' => (string) $store->getId(),
                'magento_order_check' => PaymentInfo::orderCheck($incrementId, (string) $order->getProtectCode()),
                'magento_store_key' => InvoicePlan::storeKey((string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB)),
                'magento_base_url' => mb_substr((string) $store->getBaseUrl(), 0, 500),
            ],
        ];

        $email = (string) $order->getCustomerEmail();
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $body['customerEmail'] = $email;
        }
        return $body;
    }

    /**
     * Close the invoice the order just moved off. A refusal is normal: the API never cancels an
     * invoice with a payment in flight, and if that payment lands the webhook holds the order.
     */
    private function cancelSuperseded(Order $order, Client $client, string $invoiceId, string $previousMode, string $mode): void
    {
        try {
            if ($previousMode !== '' && $previousMode !== $mode) {
                throw new ApiException('MODULE_MODE_CHANGED', (string) __('it was created with a %1 key and the saved key is now %2', $previousMode, $mode));
            }
            $client->cancelInvoice($invoiceId);
            $order->addCommentToStatusHistory(
                (string) __('The earlier payment %1 for this order was closed, so only the new one can be paid.', $invoiceId)
            );
            $this->logger->info(sprintf('canceled superseded invoice %s for order %s', $invoiceId, $order->getIncrementId()));
        } catch (ApiException $e) {
            $order->addCommentToStatusHistory(
                (string) __(
                    'The earlier payment %1 for this order could not be closed: %2. If the customer pays it, this order is put on hold for you to check rather than marked paid.',
                    $invoiceId,
                    $e->getMessage()
                )
            );
            $this->logger->error(sprintf('could not cancel superseded invoice %s for order %s: %s', $invoiceId, $order->getIncrementId(), $e->getApiCode()));
        }
        $this->orderRepository->save($order);
    }
}
