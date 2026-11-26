<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model\Webhook;

use IntentsFi\Checkout\Model\Signature;
use IntentsFi\Checkout\Model\Webhook\Processor;
use PHPUnit\Framework\TestCase;

final class ProcessorTest extends TestCase
{
    private const SECRET = 'whsec_3kQ9vTnYb2LmR7xPzW4cH8dJ';
    private const OTHER_STORE_SECRET = 'whsec_Vb7Nq2XcL9pT4zRm6Hd8KsWy';
    private const NOW = 1794666272;
    private const INVOICE = 'inv_3QkR7mVt9zLpXdB2hJn6Yw';

    private InMemoryOrders $store;
    private Processor $processor;

    protected function setUp(): void
    {
        $this->store = new InMemoryOrders();
        $this->store->add('100000042', self::INVOICE, self::SECRET);
        $this->store->add('200000007', 'inv_Ab4Cd6Ef8Gh2Jk4Lm6Np8Qr', self::OTHER_STORE_SECRET);
        $this->processor = new Processor([self::SECRET, self::OTHER_STORE_SECRET], $this->store, $this->store);
    }

    private static function event(string $id, string $type, array $data = []): string
    {
        return (string) json_encode([
            'id' => $id,
            'type' => $type,
            'createdAt' => '2026-11-21T10:03:12.000Z',
            'data' => $data + [
                'id' => self::INVOICE,
                'status' => 'paid',
                'amount' => '49.00',
                'currency' => 'USD',
                'reference' => '100000042',
                'metadata' => ['magento_increment_id' => '100000042'],
            ],
        ]);
    }

    private function deliver(string $body, ?string $secret = self::SECRET, int $at = self::NOW): \IntentsFi\Checkout\Model\Webhook\Result
    {
        $header = $secret === null ? '' : Signature::sign($secret, $body, $at);
        return $this->processor->handle($body, $header, self::NOW);
    }

    private function order(): array
    {
        return $this->store->orders['100000042'];
    }

    public function testInvoicePaidMovesTheOrderToProcessing(): void
    {
        $result = $this->deliver(self::event('evt_1', 'invoice.paid'));
        self::assertSame(200, $result->status);
        self::assertSame(['received' => true, 'handled' => true], $result->body);
        self::assertSame('processing', $this->order()['state']);
        self::assertSame(3, $this->order()['rank']);
    }

    public function testADuplicateDeliveryIsANoOp(): void
    {
        $body = self::event('evt_1', 'invoice.paid');
        $this->deliver($body);
        $again = $this->deliver($body);
        self::assertSame(200, $again->status);
        self::assertSame(['received' => true, 'duplicate' => true], $again->body);
        self::assertSame(['evt_1:paid'], $this->store->applied, 'applied exactly once');
        self::assertCount(1, $this->order()['comments']);
    }

    public function testADuplicateOfAnEarlierEventAfterALaterOneChangesNothing(): void
    {
        $processing = self::event('evt_1', 'invoice.processing', ['status' => 'processing']);
        $this->deliver($processing);
        $this->deliver(self::event('evt_2', 'invoice.paid'));
        $this->deliver($processing);
        self::assertSame(['evt_1:note', 'evt_2:paid'], $this->store->applied);
        self::assertSame('processing', $this->order()['state']);
    }

    public function testALateExpiredNeverUndoesPaid(): void
    {
        $this->deliver(self::event('evt_2', 'invoice.paid'));
        $late = $this->deliver(self::event('evt_3', 'invoice.expired', ['status' => 'expired']));
        self::assertSame(200, $late->status);
        self::assertSame('processing', $this->order()['state']);
        self::assertSame(['evt_2:paid', 'evt_3:ignore'], $this->store->applied);
        $this->deliver(self::event('evt_4', 'invoice.canceled', ['status' => 'canceled']));
        self::assertSame('processing', $this->order()['state']);
        self::assertSame(3, $this->order()['rank']);
    }

    public function testPaidBeforeProcessingStillEndsPaid(): void
    {
        $this->deliver(self::event('evt_2', 'invoice.paid'));
        $this->deliver(self::event('evt_1', 'invoice.processing', ['status' => 'processing']));
        self::assertSame('processing', $this->order()['state']);
        self::assertSame(['evt_2:paid', 'evt_1:ignore'], $this->store->applied);
    }

    public function testUnderpaidHoldsThenATopUpInvoices(): void
    {
        $this->deliver(self::event('evt_1', 'invoice.underpaid', ['status' => 'underpaid']));
        self::assertSame('holded', $this->order()['state']);
        $this->deliver(self::event('evt_2', 'invoice.paid'));
        self::assertSame('processing', $this->order()['state']);
    }

    public function testAnUnsignedOrBadlySignedBodyIsRefusedBeforeItIsRead(): void
    {
        $body = self::event('evt_1', 'invoice.paid');
        self::assertSame(401, $this->deliver($body, null)->status);
        self::assertSame(401, $this->deliver($body, 'whsec_attacker000000000000')->status);
        self::assertSame(401, $this->deliver($body, self::SECRET, self::NOW - 3600)->status);
        self::assertSame(401, $this->processor->handle('not json', Signature::sign('whsec_x00000000000000000', 'not json', self::NOW), self::NOW)->status);
        self::assertSame('pending_payment', $this->order()['state']);
        self::assertSame([], $this->store->claimed, 'a refused delivery claims no event id');
    }

    public function testAnEventSignedForAnotherStoreCannotMoveThisOrder(): void
    {
        $result = $this->deliver(self::event('evt_1', 'invoice.paid'), self::OTHER_STORE_SECRET);
        self::assertSame(200, $result->status);
        self::assertSame(['received' => true, 'handled' => false], $result->body);
        self::assertSame('pending_payment', $this->order()['state']);
    }

    public function testUnknownTypesAndUnknownOrdersAreAcknowledged(): void
    {
        $test = $this->deliver((string) json_encode(['id' => 'evt_t', 'type' => 'webhook.test', 'data' => []]));
        self::assertSame(200, $test->status);
        self::assertFalse($test->body['handled']);

        $other = self::event('evt_x', 'invoice.paid', ['metadata' => ['magento_increment_id' => '999']]);
        $result = $this->deliver($other);
        self::assertSame(200, $result->status);
        self::assertFalse($result->body['handled']);
    }

    public function testMalformedSignedPayloadsAre400(): void
    {
        foreach (['[]', '"x"', '{"type":"invoice.paid","data":{"id":"inv"}}', '{"id":"evt_1","type":"invoice.paid"}'] as $body) {
            self::assertSame(400, $this->deliver($body)->status, $body);
        }
    }

    public function testAFailureRollsTheClaimBackSoTheRetryIsHandled(): void
    {
        $body = self::event('evt_1', 'invoice.paid');
        $this->store->failNextApply = new \RuntimeException('database went away');
        try {
            $this->deliver($body);
            self::fail('the failure should reach the controller, which answers 500');
        } catch (\RuntimeException $e) {
            self::assertSame('database went away', $e->getMessage());
        }
        self::assertSame([], $this->store->claimed);
        self::assertSame('pending_payment', $this->order()['state']);

        $retry = $this->deliver($body);
        self::assertTrue($retry->body['handled']);
        self::assertSame('processing', $this->order()['state']);
    }

    public function testASupersededInvoicePaymentHoldsAndDoesNotMoveTheRank(): void
    {
        $body = self::event('evt_1', 'invoice.paid', ['id' => 'inv_9TzW2pLx6QmRv8JdK3nYcB']);
        $this->deliver($body);
        self::assertSame('holded', $this->order()['state']);
        self::assertSame(0, $this->order()['rank']);
        // The order's own invoice paying later does not override the hold a human must clear.
        $this->deliver(self::event('evt_2', 'invoice.paid'));
        self::assertSame('holded', $this->order()['state']);
        self::assertSame(['evt_1:hold', 'evt_2:review'], $this->store->applied);
    }
}
