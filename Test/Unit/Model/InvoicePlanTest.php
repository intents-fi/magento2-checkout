<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\InvoicePlan;
use PHPUnit\Framework\TestCase;

final class InvoicePlanTest extends TestCase
{
    private const NOW = 1794666272;

    private static function stored(array $overrides = []): array
    {
        return $overrides + ['invoice_id' => 'inv_3QkR7mVt9zLpXdB2hJn6Yw', 'amount' => '49.00', 'mode' => 'test'];
    }

    private static function invoice(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'inv_3QkR7mVt9zLpXdB2hJn6Yw',
            'status' => 'open',
            'amount' => '49.00',
            'currency' => 'USD',
            'url' => 'https://pay.intents.fi/c/inv_3QkR7mVt9zLpXdB2hJn6Yw',
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW + 900),
        ];
    }

    private static function fetch(?array $invoice): callable
    {
        return static fn (string $id): ?array => $invoice;
    }

    public function testNoStoredInvoiceMeansCreateTheFirst(): void
    {
        $called = false;
        $plan = InvoicePlan::assess([], '49.00', 'test', function () use (&$called) {
            $called = true;
            return null;
        }, self::NOW);
        self::assertSame(InvoicePlan::NONE, $plan['state']);
        self::assertFalse($called, 'no API call for an order with no invoice');
    }

    public function testAPayableInvoiceForTheSameTotalIsReused(): void
    {
        $plan = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::invoice()), self::NOW);
        self::assertSame(InvoicePlan::REUSE, $plan['state']);
        self::assertSame('inv_3QkR7mVt9zLpXdB2hJn6Yw', $plan['invoice']['id']);

        foreach (['processing', 'underpaid'] as $status) {
            $plan = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::invoice(['status' => $status])), self::NOW);
            self::assertSame(InvoicePlan::REUSE, $plan['state'], $status);
        }
    }

    public function testAChangedTotalSupersedesWithoutAnApiCall(): void
    {
        $plan = InvoicePlan::assess(self::stored(), '54.00', 'test', function () {
            throw new \LogicException('should not fetch');
        }, self::NOW);
        self::assertSame(InvoicePlan::STALE, $plan['state']);
    }

    public function testAChangedModeSupersedes(): void
    {
        $plan = InvoicePlan::assess(self::stored(), '49.00', 'live', self::fetch(self::invoice()), self::NOW);
        self::assertSame(InvoicePlan::STALE, $plan['state']);
    }

    public function testAnInvoiceThatCanNoLongerBePaidSupersedes(): void
    {
        foreach (['expired', 'canceled', 'paid'] as $status) {
            $plan = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::invoice(['status' => $status])), self::NOW);
            self::assertSame(InvoicePlan::STALE, $plan['state'], $status);
        }
        $past = self::invoice(['expiresAt' => gmdate('Y-m-d\TH:i:s\Z', self::NOW - 1)]);
        self::assertSame(InvoicePlan::STALE, InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch($past), self::NOW)['state']);
        self::assertSame(InvoicePlan::STALE, InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::invoice(['url' => ''])), self::NOW)['state']);
        self::assertSame(InvoicePlan::STALE, InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::invoice(['amount' => '48.00'])), self::NOW)['state']);
    }

    public function testAFailedReadSupersedesNothing(): void
    {
        $plan = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(null), self::NOW);
        self::assertSame(InvoicePlan::UNKNOWN, $plan['state']);
    }

    private const CHECK = 'b6f0c5e1d2a3b4c5d6e7f8091a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d';
    private const OTHER_CHECK = '0000000000000000000000000000000000000000000000000000000000000000';

    private static function owned(array $overrides = []): array
    {
        return self::invoice($overrides + ['metadata' => ['magento_increment_id' => '000000042', 'magento_order_check' => self::CHECK]]);
    }

    private static function owner(): array
    {
        return ['increment_id' => '000000042', 'order_check' => self::CHECK];
    }

    public function testReferencesCarryAStoreKeyAndFollowSection1130(): void
    {
        $key = InvoicePlan::storeKey('https://shop.example/');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $key);
        self::assertSame('000000042-' . $key, InvoicePlan::reference('000000042', $key, 1));
        self::assertSame('000000042-' . $key . '-r2', InvoicePlan::reference('000000042', $key, 2));
        self::assertSame('000000042-' . $key . '-r3', InvoicePlan::reference('000000042', $key, 3));
    }

    public function testTwoStoresWithTheSameOrderNumberGetDifferentReferences(): void
    {
        $a = InvoicePlan::reference('000000001', InvoicePlan::storeKey('https://shop-a.example/'), 1);
        $b = InvoicePlan::reference('000000001', InvoicePlan::storeKey('https://shop-b.example/'), 1);
        self::assertNotSame($a, $b);
    }

    public function testTheStoreKeyIsStableAcrossSchemeCaseAndTrailingSlash(): void
    {
        $key = InvoicePlan::storeKey('https://Shop.Example/');
        self::assertSame($key, InvoicePlan::storeKey('http://shop.example'));
        self::assertSame($key, InvoicePlan::storeKey(' https://shop.example/ '));
        self::assertNotSame($key, InvoicePlan::storeKey('https://shop.example/eu/'));
    }

    public function testReadsTheIncrementIdBackFromAReference(): void
    {
        self::assertSame('000000042', InvoicePlan::incrementIdFromReference('000000042-1a2b3c4d'));
        self::assertSame('000000042', InvoicePlan::incrementIdFromReference('000000042-1a2b3c4d-r2'));
        self::assertSame('000000042', InvoicePlan::incrementIdFromReference('000000042-1a2b3c4d-r12'));
        // An admin "edit order" increment id keeps its own suffix.
        self::assertSame('000000042-1', InvoicePlan::incrementIdFromReference('000000042-1-1a2b3c4d'));
        self::assertSame('000000042-1', InvoicePlan::incrementIdFromReference('000000042-1-1a2b3c4d-r2'));
        self::assertNull(InvoicePlan::incrementIdFromReference('000000042'), 'no store key: not ours');
        self::assertNull(InvoicePlan::incrementIdFromReference('order_42'));
        self::assertNull(InvoicePlan::incrementIdFromReference('order 1; drop'));
        self::assertNull(InvoicePlan::incrementIdFromReference(''));
    }

    public function testBelongsToNeedsTheSameOrderAndCheck(): void
    {
        self::assertTrue(InvoicePlan::belongsTo(self::owned(), '000000042', self::CHECK));
        self::assertFalse(InvoicePlan::belongsTo(self::owned(), '000000043', self::CHECK));
        self::assertFalse(InvoicePlan::belongsTo(self::owned(), '000000042', self::OTHER_CHECK));
        self::assertFalse(InvoicePlan::belongsTo(self::invoice(), '000000042', self::CHECK), 'no metadata');
        self::assertFalse(InvoicePlan::belongsTo(self::owned(), '000000042', ''));
    }

    public function testAStoredInvoiceForAnotherOrderIsForeignNotReused(): void
    {
        $theirs = self::invoice(['metadata' => ['magento_increment_id' => '000000042', 'magento_order_check' => self::OTHER_CHECK]]);
        $plan = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch($theirs), self::NOW, self::owner());
        self::assertSame(InvoicePlan::FOREIGN, $plan['state']);
        self::assertNull($plan['invoice']);

        $ours = InvoicePlan::assess(self::stored(), '49.00', 'test', self::fetch(self::owned()), self::NOW, self::owner());
        self::assertSame(InvoicePlan::REUSE, $ours['state']);
    }

    public function testCreateOwnedNeverReturnsAnotherOrdersInvoice(): void
    {
        $key = InvoicePlan::storeKey('https://shop.example/');
        $tried = [];
        // The first reference collides: the API replays another order's invoice. The second is ours.
        $create = function (string $reference) use (&$tried): array {
            $tried[] = $reference;
            return count($tried) === 1
                ? self::invoice(['id' => 'inv_theirs', 'metadata' => ['magento_increment_id' => '000000042', 'magento_order_check' => self::OTHER_CHECK]])
                : self::owned(['id' => 'inv_ours']);
        };
        $created = InvoicePlan::createOwned($create, '000000042', $key, 1, self::CHECK);
        self::assertSame('inv_ours', $created['invoice']['id']);
        self::assertSame('000000042-' . $key . '-r2', $created['reference']);
        self::assertSame(2, $created['sequence']);
        self::assertSame(['000000042-' . $key, '000000042-' . $key . '-r2'], $tried);
    }

    public function testCreateOwnedGivesUpRatherThanUseAForeignInvoice(): void
    {
        $calls = 0;
        $this->expectException(\RuntimeException::class);
        try {
            InvoicePlan::createOwned(function () use (&$calls): array {
                $calls++;
                return self::invoice(['metadata' => []]);
            }, '000000042', 'deadbeef', 1, self::CHECK);
        } finally {
            self::assertSame(InvoicePlan::MAX_CREATE_ATTEMPTS, $calls);
        }
    }

    public function testCreateOwnedAcceptsOurOwnInvoiceFirstTime(): void
    {
        $created = InvoicePlan::createOwned(fn () => self::owned(), '000000042', 'deadbeef', 3, self::CHECK);
        self::assertSame('000000042-deadbeef-r3', $created['reference']);
        self::assertSame(3, $created['sequence']);
    }
}
