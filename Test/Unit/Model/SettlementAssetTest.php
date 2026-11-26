<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\SettlementAsset;
use PHPUnit\Framework\TestCase;

final class SettlementAssetTest extends TestCase
{
    private const USDC_BASE = 'eip155:8453/erc20:0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913';
    private const USDT_BSC = 'eip155:56/erc20:0x55d398326f99059fF775485246999027B3197955';
    private const BTC = 'bip122:000000000019d6689c085ae165831e93/slip44:0';

    public function testStablecoinsReadAsMoney(): void
    {
        self::assertSame('9.96 USDC on Base', SettlementAsset::format('9960000', self::USDC_BASE));
        self::assertSame('10.00 USDC on Base', SettlementAsset::format('10000000', self::USDC_BASE));
        self::assertSame('0.00 USDC on Base', SettlementAsset::format('0', self::USDC_BASE));
        self::assertSame('0.000001 USDC on Base', SettlementAsset::format('1', self::USDC_BASE));
        self::assertSame('1234567.89 USDC on Base', SettlementAsset::format('1234567890000', self::USDC_BASE));
    }

    public function testEighteenDecimalUsdtOnBnbChainIsNotMisScaled(): void
    {
        self::assertSame('1.50 USDT on BNB Chain', SettlementAsset::format('1500000000000000000', self::USDT_BSC));
        self::assertSame('49.00 USDT on BNB Chain', SettlementAsset::format('49000000000000000000', self::USDT_BSC));
    }

    public function testBitcoinDropsTrailingZeros(): void
    {
        self::assertSame('0.00012345 BTC on Bitcoin', SettlementAsset::format('12345', self::BTC));
        self::assertSame('1 BTC on Bitcoin', SettlementAsset::format('100000000', self::BTC));
    }

    public function testUnknownAssetsAndMalformedAmountsStayInBaseUnits(): void
    {
        self::assertSame('500 base units of eip155:10/erc20:0xabc', SettlementAsset::format('500', 'eip155:10/erc20:0xabc'));
        self::assertSame('1.5 base units of ' . self::USDC_BASE, SettlementAsset::format('1.5', self::USDC_BASE));
        self::assertSame('-1 base units of ' . self::USDC_BASE, SettlementAsset::format('-1', self::USDC_BASE));
    }

    /** The table matches the API's own (src/checkout/settlement-assets.ts), when the repo is here. */
    public function testTheTableMatchesTheApi(): void
    {
        $ts = __DIR__ . '/../../../../../src/checkout/settlement-assets.ts';
        if (!is_file($ts)) {
            self::markTestSkipped('Not in the intents.fi repository.');
        }
        preg_match_all('/id: "([^"]+)",\s*symbol: "([A-Z]+)",\s*decimals: (\d+),/', (string) file_get_contents($ts), $m, PREG_SET_ORDER);
        self::assertNotEmpty($m);
        foreach ($m as [, $id, $symbol, $decimals]) {
            $one = '1' . str_repeat('0', (int) $decimals);
            self::assertStringStartsWith('1', SettlementAsset::format($one, $id), $id);
            self::assertMatchesRegularExpression('/^1(\.00)? ' . $symbol . ' on /', SettlementAsset::format($one, $id), $id);
        }
    }
}
