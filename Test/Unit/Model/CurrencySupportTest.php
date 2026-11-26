<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\CurrencySupport;
use PHPUnit\Framework\TestCase;

final class CurrencySupportTest extends TestCase
{
    public function testUsdThroughoutIsSupported(): void
    {
        self::assertNull(CurrencySupport::problem('USD', 'USD'));
        self::assertNull(CurrencySupport::problem('usd', ' USD '));
    }

    public function testAnotherBaseCurrencyIsRefusedEvenWhenCustomersSeeUsd(): void
    {
        // A USD display price over a EUR base is a converted figure: never invoiced silently.
        $problem = CurrencySupport::problem('EUR', 'USD');
        self::assertNotNull($problem);
        self::assertStringContainsString('EUR', $problem);
        self::assertStringContainsString('never converts', $problem);
    }

    public function testAnotherChargeCurrencyIsRefused(): void
    {
        $problem = CurrencySupport::problem('USD', 'GBP');
        self::assertNotNull($problem);
        self::assertStringContainsString('GBP', $problem);
    }

    public function testMissingCurrenciesAreRefused(): void
    {
        self::assertNotNull(CurrencySupport::problem('', 'USD'));
        self::assertNotNull(CurrencySupport::problem('USD', ''));
    }
}
