<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\Amount;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    /** @return iterable<string, array{string|float|int, string}> */
    public static function totals(): iterable
    {
        yield 'Magento DECIMAL(20,4)' => ['49.0000', '49.00'];
        yield 'integer string' => ['5', '5.00'];
        yield 'int' => [5, '5.00'];
        yield 'one place' => ['5.5', '5.50'];
        yield 'rounds half up' => ['10.005', '10.01'];
        yield 'rounds down' => ['10.0049', '10.00'];
        yield 'carries' => ['9.995', '10.00'];
        yield 'carries through nines' => ['999.9999', '1000.00'];
        yield 'zero' => ['0.0000', '0.00'];
        yield 'leading zeros' => ['007.10', '7.10'];
        yield 'sub-dollar' => ['0.05', '0.05'];
        yield 'float as Magento returns it' => [49.99, '49.99'];
        yield 'float that is not exact in binary' => [0.1 + 0.2, '0.30'];
        yield 'large' => ['123456789012.3456', '123456789012.35'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('totals')]
    public function testFormatsWithoutFloats(string|float|int $total, string $expected): void
    {
        self::assertSame($expected, Amount::format($total));
    }

    public function testRefusesNegativeOrMalformedAmounts(): void
    {
        foreach (['-1.00', '1,00', 'abc', '', '1e3', '.5'] as $bad) {
            try {
                Amount::format($bad);
                self::fail("accepted {$bad}");
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testComparesByCents(): void
    {
        self::assertTrue(Amount::equals('49.00', '49.0000'));
        self::assertTrue(Amount::equals('49', '49.00'));
        self::assertFalse(Amount::equals('49.00', '49.01'));
        self::assertFalse(Amount::equals('49.00', 'forty-nine'));
        self::assertFalse(Amount::equals('', ''));
    }
}
