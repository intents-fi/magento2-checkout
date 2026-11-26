<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model\Connect;

use IntentsFi\Checkout\Model\Api\Client;
use IntentsFi\Checkout\Model\Connect\ConnectFlow;
use IntentsFi\Checkout\Model\Connect\Protocol;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

final class ConnectFlowTest extends TestCase
{
    private const NOW = 1_795_000_000;
    private const USER = 7;
    private const SITE = 'https://shop.fernhill.example';
    private const RETURN = 'https://shop.fernhill.example/intentsfi/connect/back';
    private const CALLBACK = 'https://admin.fernhill.example/admin/intentsfi_checkout/connect/callback/';
    private const WEBHOOK = 'https://shop.fernhill.example/intentsfi/webhook';
    private const TEST_KEY = 'ik_test_Hq7Vb2Lm9Xc4Rt6Yw8Zp3Nk5';
    private const LIVE_KEY = 'ik_live_Qd4Wn8Ks2Fj6Gv1Pt7Xm9Rb3';
    private const CODE = 'c0de_Ab12Cd34Ef56Gh78Ij90';

    private MemoryPending $pending;
    private ScriptedTransport $http;
    private MemorySettings $settings;
    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->pending = new MemoryPending();
        $this->http = new ScriptedTransport();
        $this->settings = new MemorySettings();
        $this->logged = [];
    }

    private function flow(string $dashboard = ''): ConnectFlow
    {
        return new ConnectFlow(
            $this->pending,
            fn (string $key) => new Client($this->http, $key, 'https://api.intents.fi'),
            $dashboard,
            function (string $line): void {
                $this->logged[] = $line;
            }
        );
    }

    /** @return array{ok: bool, url?: string, result?: string} */
    private function start(string $mode = 'test', ?int $website = null): array
    {
        return $this->flow()->start($mode, self::USER, self::SITE, self::RETURN, self::CALLBACK, self::WEBHOOK, $website, self::NOW);
    }

    private function state(): string
    {
        return (string) $this->pending->byUser[self::USER]['state'];
    }

    private function token(string $mode, string $key): array
    {
        return [
            'secret_key' => $key,
            'publishable_key' => 'pk_' . $mode . '_Lr5Tc8Vn2Mh6Jd9Qw3Xs7Kf',
            'mode' => $mode,
            'account' => ['id' => 'acc_1', 'name' => 'Fernhill Goods'],
            'merchant' => ['id' => 'mer_1', 'name' => 'Fernhill Goods', 'status' => 'active'],
            'connection' => ['id' => 'con_' . $mode, 'platform' => 'magento', 'site' => 'shop.fernhill.example', 'mode' => $mode],
        ];
    }

    /** @return array{result: string, website: int|null} */
    private function finish(array $query, int $user = self::USER): array
    {
        return $this->flow()->finish($query, $user, fn (?int $w) => $this->settings, self::NOW + 60);
    }

    public function testTheModuleCopyOfTheSharedProtocolIsByteIdenticalAndLoaded(): void
    {
        Protocol::load();
        $file = (new \ReflectionClass(\IntentsFi\Connect\Connect_Protocol::class))->getFileName();
        self::assertSame(realpath(Protocol::FILE), realpath((string) $file));
        if (!is_file(Protocol::SHARED)) {
            self::markTestSkipped('Not in the intents.fi repository.');
        }
        self::assertFileEquals(Protocol::SHARED, Protocol::FILE, 'Copy plugins/_shared/connect/class-connect-protocol.php into plugins/magento/lib/connect/.');
    }

    public function testStartSendsTheAdminToTheDashboardWithAPkceChallengeAndNoSecret(): void
    {
        $started = $this->start();
        self::assertTrue($started['ok']);
        $url = (string) $started['url'];
        self::assertStringStartsWith('https://dashboard.intents.fi/connect?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $pending = $this->pending->byUser[self::USER];

        self::assertSame('magento', $q['platform']);
        self::assertSame(self::SITE, $q['site_url']);
        self::assertSame(self::RETURN, $q['return_url']);
        self::assertSame('test', $q['mode']);
        self::assertSame('S256', $q['code_challenge_method']);
        self::assertSame($pending['state'], $q['state']);
        self::assertSame(\IntentsFi\Connect\Connect_Protocol::challenge($pending['verifier']), $q['code_challenge']);
        self::assertStringNotContainsString($pending['verifier'], $url, 'the verifier never leaves the server');
        self::assertSame(self::CALLBACK, $this->pending->returns[$pending['state']]);
    }

    public function testGoLiveAsksForLiveModeAndADevDashboardCanBeSet(): void
    {
        $started = $this->flow('http://localhost:3001')->start('live', self::USER, self::SITE, self::RETURN, self::CALLBACK, self::WEBHOOK, 2, self::NOW);
        self::assertStringStartsWith('http://localhost:3001/connect?', (string) $started['url']);
        self::assertStringContainsString('mode=live', (string) $started['url']);
        self::assertSame(2, $this->pending->byUser[self::USER]['website']);
    }

    public function testStartRefusesAReturnOffTheStoreOriginAndPlainHttp(): void
    {
        $flow = $this->flow();
        self::assertSame(['ok' => false, 'result' => 'origin'], $flow->start('test', self::USER, self::SITE, 'https://evil.example/back', self::CALLBACK, self::WEBHOOK, null, self::NOW));
        self::assertSame(['ok' => false, 'result' => 'https'], $flow->start('test', self::USER, 'http://shop.fernhill.example', 'http://shop.fernhill.example/intentsfi/connect/back', self::CALLBACK, self::WEBHOOK, null, self::NOW));
        self::assertTrue($flow->start('test', self::USER, 'http://magento.test', 'http://magento.test/intentsfi/connect/back', self::CALLBACK, self::WEBHOOK, null, self::NOW)['ok']);
        self::assertTrue($flow->start('test', self::USER, 'http://127.0.0.1:8080', 'http://127.0.0.1:8080/intentsfi/connect/back', self::CALLBACK, self::WEBHOOK, null, self::NOW)['ok']);
        self::assertCount(1, $this->pending->byUser, 'only the accepted requests were kept');
    }

    public function testTheStorefrontHopForwardsOnlyToTheSavedAdminUrl(): void
    {
        $this->start();
        $state = $this->state();
        $url = $this->flow()->forwardUrl(['state' => $state, 'code' => self::CODE, 'return_to' => 'https://evil.example']);
        self::assertSame(self::CALLBACK . '?state=' . rawurlencode($state) . '&code=' . self::CODE, $url);

        self::assertNull($this->flow()->forwardUrl(['state' => 'never-issued-state-value', 'code' => self::CODE]));
        self::assertNull($this->flow()->forwardUrl(['code' => self::CODE]));
        self::assertStringContainsString('error=access_denied', (string) $this->flow()->forwardUrl(['state' => $state, 'error' => 'access_denied']));
    }

    public function testFinishExchangesTheCodeRegistersTheWebhookAndSavesConnectedInTestMode(): void
    {
        $this->start();
        $state = $this->state();
        $verifier = $this->pending->byUser[self::USER]['verifier'];
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('test', self::TEST_KEY));
        $this->http->on('POST', '/v0/webhooks', 201, ['id' => 'whk_1', 'secret' => 'whsec_Tz8Mc3Vb6Nq1Lx4Pk7Rd2Hs9']);

        self::assertSame(['result' => 'connected', 'website' => null], $this->finish(['state' => $state, 'code' => self::CODE]));

        [$exchange, $webhook] = $this->http->sent;
        self::assertSame(['code' => self::CODE, 'code_verifier' => $verifier, 'site_url' => self::SITE], $exchange['body']);
        self::assertArrayNotHasKey('Authorization', $exchange['headers'], 'the exchange is unauthenticated');
        self::assertSame('Bearer ' . self::TEST_KEY, $webhook['headers']['Authorization']);
        self::assertSame(self::WEBHOOK, $webhook['body']['url']);
        self::assertSame(ConnectFlow::WEBHOOK_EVENTS, $webhook['body']['events']);

        self::assertSame(self::TEST_KEY, $this->settings->key);
        self::assertSame('whsec_Tz8Mc3Vb6Nq1Lx4Pk7Rd2Hs9', $this->settings->webhookSecret);
        $c = $this->settings->connection();
        self::assertNotNull($c);
        self::assertSame(['test', 'Fernhill Goods', 'con_test', 'shop.fernhill.example', true], [$c['mode'], $c['business'], $c['connection_id'], $c['site'], $c['webhook']]);
        self::assertSame([], $this->pending->byUser, 'the request is spent');
    }

    public function testAReturnIsAnsweredOnce(): void
    {
        $this->start();
        $state = $this->state();
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('test', self::TEST_KEY));
        $this->http->on('POST', '/v0/webhooks', 201, ['secret' => 'whsec_Tz8Mc3Vb6Nq1Lx4Pk7Rd2Hs9']);
        $this->finish(['state' => $state, 'code' => self::CODE]);

        self::assertSame('no_request', $this->finish(['state' => $state, 'code' => self::CODE])['result']);
        self::assertSame(['POST /v0/connect/token', 'POST /v0/webhooks'], $this->http->calls());
    }

    public function testAForgedOrForeignReturnChangesNothing(): void
    {
        $this->start();
        $state = $this->state();

        self::assertSame('state', $this->finish(['state' => 'someone-elses-state-xyz', 'code' => self::CODE])['result']);
        self::assertSame(['Connect: a return arrived with a state this admin did not start; ignored.'], $this->logged);

        $this->start();
        self::assertSame('no_request', $this->finish(['state' => $this->state(), 'code' => self::CODE], self::USER + 1)['result'], 'another admin user');
        self::assertSame([], $this->http->sent);
        self::assertSame('', $this->settings->key);
        unset($state);
    }

    public function testCancelExpiryAndABadCodeChangeNothing(): void
    {
        $this->start();
        self::assertSame('cancelled', $this->finish(['state' => $this->state(), 'error' => 'access_denied'])['result']);

        $this->start();
        self::assertSame('bad_code', $this->finish(['state' => $this->state(), 'code' => 'x y'])['result']);

        $this->start();
        $late = $this->flow()->finish(['state' => $this->state(), 'code' => self::CODE], self::USER, fn () => $this->settings, self::NOW + 1801);
        self::assertSame('expired', $late['result']);

        self::assertSame([], $this->http->sent);
        self::assertNull($this->settings->connection());
    }

    public function testATokenInTheWrongModeIsNeverSaved(): void
    {
        $this->start('test');
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('live', self::LIVE_KEY));
        self::assertSame('exchange_failed', $this->finish(['state' => $this->state(), 'code' => self::CODE])['result']);
        self::assertSame('', $this->settings->key);
        self::assertSame(['POST /v0/connect/token'], $this->http->calls(), 'no webhook for keys it did not keep');
    }

    public function testGoLiveBeforeTheChecklistSaysSoAndKeepsTheTestConnection(): void
    {
        $this->settings->save(['secret_key' => self::TEST_KEY, 'mode' => 'test', 'business' => 'Fernhill Goods', 'merchant_status' => 'active', 'connection_id' => 'con_test', 'site' => 'shop.fernhill.example'], 'whsec_old', self::NOW);
        $this->start('live');
        $this->http->on('POST', '/v0/connect/token', 403, ['error' => ['code' => 'LIVE_MODE_LOCKED', 'message' => 'Live mode is locked until go-live']]);

        self::assertSame('live_locked', $this->finish(['state' => $this->state(), 'code' => self::CODE])['result']);
        self::assertSame(self::TEST_KEY, $this->settings->key);
        self::assertSame('test', $this->settings->connection()['mode'] ?? null);
    }

    public function testGoLiveSavesTheLiveKeyThenRevokesTheTestConnectionItReplaced(): void
    {
        $this->settings->save(['secret_key' => self::TEST_KEY, 'mode' => 'test', 'business' => 'Fernhill Goods', 'merchant_status' => 'active', 'connection_id' => 'con_test', 'site' => 'shop.fernhill.example'], 'whsec_old', self::NOW);
        $this->start('live', 3);
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('live', self::LIVE_KEY));
        $this->http->on('POST', '/v0/webhooks', 201, ['secret' => 'whsec_Lv2Bn5Cx8Dz1Fq4Gw7Hj3Kp6']);
        $this->http->on('POST', '/v0/connect/revoke', 200, ['revoked' => true, 'connectionId' => 'con_test']);

        self::assertSame(['result' => 'connected', 'website' => 3], $this->finish(['state' => $this->state(), 'code' => self::CODE]));
        self::assertSame(['POST /v0/connect/token', 'POST /v0/webhooks', 'POST /v0/connect/revoke'], $this->http->calls());
        self::assertSame('Bearer ' . self::TEST_KEY, $this->http->sent[2]['headers']['Authorization'], 'the replaced key is the one revoked');
        self::assertSame(self::LIVE_KEY, $this->settings->key);
        self::assertSame('live', $this->settings->connection()['mode'] ?? null);
    }

    public function testAManualKeyIsNeverRevokedWhenConnectReplacesIt(): void
    {
        $this->settings->key = 'ik_test_ManualKeyPasted1234567';
        $this->start();
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('test', self::TEST_KEY));
        $this->http->on('POST', '/v0/webhooks', 201, ['secret' => 'whsec_Tz8Mc3Vb6Nq1Lx4Pk7Rd2Hs9']);
        $this->finish(['state' => $this->state(), 'code' => self::CODE]);
        self::assertSame(['POST /v0/connect/token', 'POST /v0/webhooks'], $this->http->calls());
    }

    public function testConnectedWithoutPaymentNotificationsWhenTheWebhookIsRefused(): void
    {
        $this->start();
        $this->http->on('POST', '/v0/connect/token', 200, $this->token('test', self::TEST_KEY));
        $this->http->on('POST', '/v0/webhooks', 400, ['error' => ['code' => 'INVALID_REQUEST', 'message' => 'url must use https']]);

        self::assertSame('connected_no_webhook', $this->finish(['state' => $this->state(), 'code' => self::CODE])['result']);
        self::assertSame(self::TEST_KEY, $this->settings->key);
        self::assertFalse($this->settings->connection()['webhook'] ?? true);
    }

    public function testDisconnectRevokesThenForgetsAndLeavesAManualKeyAlone(): void
    {
        $this->settings->save(['secret_key' => self::TEST_KEY, 'mode' => 'test', 'business' => 'Fernhill Goods', 'merchant_status' => 'active', 'connection_id' => 'con_test', 'site' => 'shop.fernhill.example'], 'whsec_old', self::NOW);
        $this->http->on('POST', '/v0/connect/revoke', 200, ['revoked' => true, 'connectionId' => 'con_test']);
        self::assertSame('disconnected', $this->flow()->disconnect($this->settings));
        self::assertSame('Bearer ' . self::TEST_KEY, $this->http->sent[0]['headers']['Authorization']);
        self::assertSame(['', '', null], [$this->settings->key, $this->settings->webhookSecret, $this->settings->connection()]);

        // A key pasted over a connection is not a connection: nothing to revoke or forget.
        $this->settings->save(['secret_key' => self::TEST_KEY, 'mode' => 'test', 'business' => '', 'merchant_status' => '', 'connection_id' => '', 'site' => ''], '', self::NOW);
        $this->settings->key = 'ik_test_ManualKeyPasted1234567';
        self::assertSame('not_connected', $this->flow()->disconnect($this->settings));
        self::assertSame('ik_test_ManualKeyPasted1234567', $this->settings->key);
        self::assertCount(1, $this->http->sent);
    }

    public function testDisconnectStillForgetsWhenTheRevokeFails(): void
    {
        $this->settings->save(['secret_key' => self::TEST_KEY, 'mode' => 'test', 'business' => '', 'merchant_status' => '', 'connection_id' => '', 'site' => ''], 'whsec_old', self::NOW);
        $this->http->on('POST', '/v0/connect/revoke', 500, ['error' => ['code' => 'INTERNAL', 'message' => 'oops']]);
        self::assertSame('disconnected', $this->flow()->disconnect($this->settings));
        self::assertSame('', $this->settings->key);
        self::assertSame(['Connect: revoke failed: INTERNAL'], $this->logged);
    }

    public function testNoKeyIsEverLogged(): void
    {
        $this->start();
        $this->http->on('POST', '/v0/connect/token', 400, ['error' => ['code' => 'CONNECT_CODE_INVALID', 'message' => 'code is spent; key ' . self::TEST_KEY]]);
        $logs = [];
        $flow = new ConnectFlow(
            $this->pending,
            function (string $key) use (&$logs): Client {
                return new Client($this->http, $key, 'https://api.intents.fi', 'ua', function (string $l) use (&$logs): void {
                    $logs[] = $l;
                });
            }
        );
        self::assertSame('exchange_failed', $flow->finish(['state' => $this->state(), 'code' => self::CODE], self::USER, fn () => $this->settings, self::NOW)['result']);
        self::assertNotEmpty($logs);
        foreach ($logs as $line) {
            self::assertStringNotContainsString(self::TEST_KEY, $line);
        }
    }
}
