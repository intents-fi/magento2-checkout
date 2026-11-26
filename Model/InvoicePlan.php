<?php
/**
 * Whether an order's existing invoice can be reused, and what to call the next one.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

/**
 * The reference is `<increment id>-<store key>`: the increment id a merchant recognises, plus eight
 * hex characters of a hash of the store's base URL. Two Magento installs on one intents.fi
 * merchant both start at `000000001`, and `reference` is the API's idempotency key, so without
 * the store key the second store's order would be handed the first store's invoice.
 *
 * An invoice is priced when it is created (SPEC-checkout §11.30). If the order total changes, the
 * key mode changes, or the invoice can no longer be paid, the order needs a new invoice with a
 * new reference: `<increment id>-<store key>-r2`, then `-r3`. The old one is canceled through the API only
 * after the order points at the new one, so the `invoice.canceled` that cancellation fires can
 * never cancel the order.
 *
 * Free of Magento so the whole table is unit-tested.
 */
class InvoicePlan
{
    /** No invoice yet: create the first. */
    public const NONE = 'none';

    /** The stored invoice is still payable for this total: send the customer back to it. */
    public const REUSE = 'reuse';

    /** The stored invoice exists but cannot be paid for what the order now asks: supersede it. */
    public const STALE = 'stale';

    /**
     * The stored invoice could not be read. Nothing is superseded on a failed read: the same
     * reference is sent again and the API's idempotency hands back the same invoice.
     */
    public const UNKNOWN = 'unknown';

    /**
     * The stored or returned invoice is not this order's: its metadata names another order. It is
     * never reused, never redirected to and never canceled (it belongs to someone else's order);
     * a fresh invoice is created under the next reference.
     */
    public const FOREIGN = 'foreign';

    /** How many references to try when an idempotent create keeps returning someone else's invoice. */
    public const MAX_CREATE_ATTEMPTS = 3;

    /** Invoice statuses a customer can still pay. */
    private const PAYABLE = ['open', 'processing', 'underpaid'];

    /**
     * Decide what the stored invoice is good for.
     *
     * @param array{invoice_id?: string, amount?: string, mode?: string} $stored What the order carries.
     * @param string                    $amount  The order total now, as {@see Amount::format()} writes it.
     * @param string                    $mode    The key mode in effect now.
     * @param callable(string): ?array  $fetch   Reads an invoice by id; returns null when it could not be read.
     * @param int|null                  $now     Unix time; defaults to `time()`.
     * @param array{increment_id: string, order_check: string}|null $owner The order asking. When
     *        given, an invoice whose metadata names another order is FOREIGN.
     */
    public static function assess(
        array $stored,
        string $amount,
        string $mode,
        callable $fetch,
        ?int $now = null,
        ?array $owner = null
    ): array {
        $invoiceId = (string) ($stored['invoice_id'] ?? '');
        if ($invoiceId === '') {
            return ['state' => self::NONE, 'invoice' => null];
        }

        // Neither of these needs an API call to rule out.
        if (!Amount::equals((string) ($stored['amount'] ?? ''), $amount)) {
            return ['state' => self::STALE, 'invoice' => null];
        }
        if ((string) ($stored['mode'] ?? '') !== $mode) {
            return ['state' => self::STALE, 'invoice' => null];
        }

        $invoice = $fetch($invoiceId);
        if (!is_array($invoice)) {
            return ['state' => self::UNKNOWN, 'invoice' => null];
        }

        if ($owner !== null && !self::belongsTo($invoice, $owner['increment_id'], $owner['order_check'])) {
            return ['state' => self::FOREIGN, 'invoice' => null];
        }

        $status = is_string($invoice['status'] ?? null) ? $invoice['status'] : '';
        $url = is_string($invoice['url'] ?? null) ? $invoice['url'] : '';
        $expiresAt = is_string($invoice['expiresAt'] ?? null) ? strtotime($invoice['expiresAt']) : false;
        $expired = $expiresAt !== false && $expiresAt < ($now ?? time());
        $sameAmount = is_string($invoice['amount'] ?? null) && Amount::equals($invoice['amount'], $amount);

        if (!in_array($status, self::PAYABLE, true) || $url === '' || $expired || !$sameAmount) {
            return ['state' => self::STALE, 'invoice' => null];
        }

        return ['state' => self::REUSE, 'invoice' => $invoice];
    }

    /**
     * The store key: eight hex characters of SHA-256 over the store's base URL, without its scheme
     * or trailing slash, so moving a store from http to https keeps its key.
     */
    public static function storeKey(string $baseUrl): string
    {
        $url = strtolower(trim($baseUrl));
        $url = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $url);
        return substr(hash('sha256', rtrim($url, '/')), 0, 8);
    }

    /** The n-th reference of an order: `000000042-1a2b3c4d`, then `000000042-1a2b3c4d-r2`, … */
    public static function reference(string $incrementId, string $storeKey, int $sequence): string
    {
        $base = $incrementId . '-' . $storeKey;
        return $sequence > 1 ? $base . '-r' . $sequence : $base;
    }

    /**
     * The increment id a reference was made from, or null if it is not one of ours. Magento makes
     * `000000042-1` on an admin edit, so the store key is what marks the end of the increment id.
     */
    public static function incrementIdFromReference(string $reference): ?string
    {
        if (preg_match('/^([A-Za-z0-9_\-]{1,50})-[0-9a-f]{8}(?:-r([2-9]|[1-9][0-9]+))?$/', $reference, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /**
     * Is this invoice the one this module made for this order? Its metadata must name the same
     * increment id and carry the same order check (derived from the order's protect_code).
     *
     * @param array<string, mixed> $invoice
     */
    public static function belongsTo(array $invoice, string $incrementId, string $orderCheck): bool
    {
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $id = is_string($metadata['magento_increment_id'] ?? null) ? $metadata['magento_increment_id'] : '';
        $check = is_string($metadata['magento_order_check'] ?? null) ? $metadata['magento_order_check'] : '';

        return $incrementId !== '' && $orderCheck !== '' && $check !== ''
            && hash_equals($incrementId, $id)
            && hash_equals($orderCheck, $check);
    }

    /**
     * Create an invoice this order owns. If the idempotent create hands back an invoice made for
     * another order (a reference collision), it is never used: the next reference is tried, up to
     * {@see MAX_CREATE_ATTEMPTS} times.
     *
     * @param callable(string $reference): array $create Creates (or replays) the invoice for a reference.
     * @return array{invoice: array<string, mixed>, reference: string, sequence: int}
     * @throws \RuntimeException When every reference tried belonged to another order.
     */
    public static function createOwned(
        callable $create,
        string $incrementId,
        string $storeKey,
        int $sequence,
        string $orderCheck
    ): array {
        for ($attempt = 0; $attempt < self::MAX_CREATE_ATTEMPTS; $attempt++) {
            $reference = self::reference($incrementId, $storeKey, $sequence);
            $invoice = $create($reference);
            if (self::belongsTo($invoice, $incrementId, $orderCheck)) {
                return ['invoice' => $invoice, 'reference' => $reference, 'sequence' => $sequence];
            }
            $sequence++;
        }
        throw new \RuntimeException(sprintf(
            'every reference tried for order %s returned an invoice made for another order',
            $incrementId
        ));
    }
}
