<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\Affiliate;
use IntentsFi\Checkout\Model\Api\Client;
use IntentsFi\Checkout\Model\Connect\ConnectFlow;
use IntentsFi\Checkout\Test\Unit\Model\Connect\MemoryPending;
use IntentsFi\Checkout\Test\Unit\Model\Connect\ScriptedTransport;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Connect/Fakes.php';

/** The optional affiliate ID: the field, resolve, Connect's `ref` and the invoice header. */
final class AffiliateTest extends TestCase
{
    private const API = 'https://api.intents.fi';
    private const KEY = 'ik_test_Hq7Vb2Lm9Xc4Rt6Yw8Zp3Nk5';

    public function testTheFieldTrimsAndLowercasesAValidId(): void
    {
        self::assertSame('agency-co', Affiliate::normalize(' Agency-CO '));
        self::assertSame('oakstudio16chars', Affiliate::normalize('OAKSTUDIO16CHARS'));
    }

    public function testTheFieldRefusesAnInvalidIdAndEmptyClearsIt(): void
    {
        foreach (['ab', 'bad id!', 'abcde', 'seventeen-chars-x', 'agency_co', ['agency-co'], 42] as $bad) {
            self::assertNull(Affiliate::normalize($bad), var_export($bad, true));
        }
        self::assertSame('', Affiliate::normalize(''));
        self::assertSame('', Affiliate::normalize('   '));
        self::assertSame('', Affiliate::normalize(null));
        self::assertSame('', Affiliate::usable('bad id!'), 'an invalid stored value is never sent');
    }

    public function testResolveNamesTheAffiliateAndSendsOnlyTheId(): void
    {
        $http = new ScriptedTransport();
        $http->on('GET', '/v0/ref/resolve', 200, ['valid' => true, 'displayName' => 'Oak Studio']);

        $result = Affiliate::resolve($http, self::API, ' Agency-CO ', 'intentsfi-checkout-magento/1.1.0');

        self::assertSame(['status' => Affiliate::LINKED, 'name' => 'Oak Studio'], $result);
        self::assertSame(['success', 'Linked to %1.', ['Oak Studio']], Affiliate::notice($result));
        self::assertCount(1, $http->sent);
        self::assertSame('GET', $http->sent[0]['method']);
        self::assertNull($http->sent[0]['body']);
        self::assertArrayNotHasKey('Authorization', $http->sent[0]['headers']);
        self::assertSame(self::API . '/v0/ref/resolve?code=agency-co', $http->urls[0]);
        self::assertSame(5, $http->timeouts[0]);
    }

    public function testAnUnknownIdIsAWarning(): void
    {
        $http = new ScriptedTransport();
        $http->on('GET', '/v0/ref/resolve', 200, ['valid' => false, 'displayName' => null]);

        $result = Affiliate::resolve($http, self::API, 'nobody-here', 'ua');

        self::assertSame(['status' => Affiliate::UNKNOWN, 'name' => ''], $result);
        [$type, $text] = Affiliate::notice($result);
        self::assertSame('warning', $type);
        self::assertStringStartsWith('Unknown affiliate ID', $text);
    }

    public function testATransportErrorOrAnErrorStatusIsAWarningNeverAnError(): void
    {
        $http = new ScriptedTransport(); // nothing scripted: send() throws
        $result = Affiliate::resolve($http, self::API, 'agency-co', 'ua');
        self::assertSame(Affiliate::UNAVAILABLE, $result['status']);
        [$type, $text] = Affiliate::notice($result);
        self::assertSame('warning', $type);
        self::assertStringContainsString('It was saved', $text);

        $http = new ScriptedTransport();
        $http->on('GET', '/v0/ref/resolve', 503, ['error' => ['code' => 'UNAVAILABLE']]);
        self::assertSame(Affiliate::UNAVAILABLE, Affiliate::resolve($http, self::API, 'agency-co', 'ua')['status']);

        $http = new ScriptedTransport();
        $http->on('GET', '/v0/ref/resolve', 200, ['unexpected' => true]);
        self::assertSame(Affiliate::UNKNOWN, Affiliate::resolve($http, self::API, 'agency-co', 'ua')['status']);
    }

    public function testAnInvalidIdIsNeverSentToResolve(): void
    {
        $http = new ScriptedTransport();
        self::assertSame(Affiliate::UNKNOWN, Affiliate::resolve($http, self::API, 'bad id!', 'ua')['status']);
        self::assertSame([], $http->sent);
    }

    public function testInvoiceCreateSendsTheAffiliateHeaderOnlyWhenSet(): void
    {
        $http = new ScriptedTransport();
        $http->on('POST', '/v0/invoices', 201, ['id' => 'inv_1', 'url' => 'https://pay.intents.fi/i/inv_1']);
        $http->on('POST', '/v0/invoices', 201, ['id' => 'inv_2', 'url' => 'https://pay.intents.fi/i/inv_2']);
        $http->on('POST', '/v0/invoices', 201, ['id' => 'inv_3', 'url' => 'https://pay.intents.fi/i/inv_3']);
        $http->on('GET', '/v0/merchant', 200, ['name' => 'Fernhill Goods']);
        $client = new Client($http, self::KEY, self::API);

        $client->createInvoice(['reference' => '000000001-ab12cd34'], 'Agency-CO');
        $client->createInvoice(['reference' => '000000002-ab12cd34'], '');
        $client->createInvoice(['reference' => '000000003-ab12cd34'], 'bad id!');
        $client->getMerchant();

        self::assertSame('agency-co', $http->sent[0]['headers']['X-Intents-Affiliate'] ?? null);
        self::assertSame('Bearer ' . self::KEY, $http->sent[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('X-Intents-Affiliate', $http->sent[1]['headers']);
        self::assertArrayNotHasKey('X-Intents-Affiliate', $http->sent[2]['headers']);
        self::assertArrayNotHasKey('X-Intents-Affiliate', $http->sent[3]['headers'], 'only invoice create carries it');
    }

    public function testTheConnectUrlCarriesRefOnlyWhenAnIdIsSet(): void
    {
        $url = fn (string $id): string => (string) (new ConnectFlow(new MemoryPending(), fn () => null))->start(
            'test',
            7,
            'https://shop.fernhill.example',
            'https://shop.fernhill.example/intentsfi/connect/back',
            'https://admin.fernhill.example/admin/intentsfi_checkout/connect/callback/',
            'https://shop.fernhill.example/intentsfi/webhook',
            null,
            1_795_000_000,
            $id
        )['url'];

        parse_str((string) parse_url($url('Agency-CO'), PHP_URL_QUERY), $with);
        self::assertSame('agency-co', $with['ref'] ?? null);
        self::assertSame('magento', $with['platform']);

        foreach (['', 'bad id!'] as $none) {
            parse_str((string) parse_url($url($none), PHP_URL_QUERY), $without);
            self::assertArrayNotHasKey('ref', $without);
            self::assertArrayNotHasKey('partner', $without);
        }
    }

    public function testReferredByOnlyWhenTheMerchantNamesAnAffiliate(): void
    {
        self::assertSame('Oak Studio', Affiliate::referredBy(['affiliate' => ['displayName' => 'Oak Studio']]));
        self::assertNull(Affiliate::referredBy(['name' => 'Fernhill Goods']));
        self::assertNull(Affiliate::referredBy(['affiliate' => null]));
        self::assertNull(Affiliate::referredBy(['affiliate' => ['displayName' => null]]));
        self::assertNull(Affiliate::referredBy(['affiliate' => ['displayName' => '  ']]));
    }
}
