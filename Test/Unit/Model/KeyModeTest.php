<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\KeyMode;
use PHPUnit\Framework\TestCase;

final class KeyModeTest extends TestCase
{
    public function testDetectsTheModeFromThePrefix(): void
    {
        self::assertSame(KeyMode::TEST, KeyMode::detect('ik_test_7Hc2LqVw9RzT4mXb'));
        self::assertSame(KeyMode::LIVE, KeyMode::detect('ik_live_Qp3Vn8KdW2sYt6Rz'));
        self::assertSame(KeyMode::TEST, KeyMode::detect("  ik_test_7Hc2LqVw9RzT4mXb\n"));
    }

    public function testRefusesAnythingThatIsNotASecretKey(): void
    {
        foreach (['', 'pk_test_7Hc2LqVw9RzT4mXb', 'pk_live_7Hc2LqVw9RzT4mXb', 'ik_prod_7Hc2LqVw9RzT4mXb', 'ik_test_', 'IK_TEST_7Hc2LqVw9RzT4mXb', 'whsec_7Hc2LqVw9RzT4mXb'] as $key) {
            self::assertNull(KeyMode::detect($key), $key);
            self::assertNotNull(KeyMode::problem($key), $key);
        }
    }

    public function testExplainsThePublishableKeyMistake(): void
    {
        self::assertStringContainsString('publishable', (string) KeyMode::problem('pk_live_7Hc2LqVw9RzT4mXb'));
        self::assertNull(KeyMode::problem('ik_live_Qp3Vn8KdW2sYt6Rz'));
    }
}
