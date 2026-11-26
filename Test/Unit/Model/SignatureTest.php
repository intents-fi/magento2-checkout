<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Test\Unit\Model;

use IntentsFi\Checkout\Model\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'whsec_3kQ9vTnYb2LmR7xPzW4cH8dJ';
    private const BODY = '{"id":"evt_6LqB9vZtHd3nMwCx","type":"invoice.paid","data":{"id":"inv_3QkR7mVt9zLpXdB2hJn6Yw"}}';
    private const NOW = 1794666272;

    public function testAcceptsWhatIntentsFiSigns(): void
    {
        $header = Signature::sign(self::SECRET, self::BODY, self::NOW);
        self::assertTrue(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW));
    }

    public function testMatchesTheDocumentedSchemeByteForByte(): void
    {
        // HMAC-SHA256(secret, "<t>.<raw body>"), hex, as apps/docs/content/platform/webhooks.md says.
        $expected = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        $header = 't=' . self::NOW . ',v1=' . $expected;
        self::assertTrue(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW));
        self::assertSame($header, Signature::sign(self::SECRET, self::BODY, self::NOW));
    }

    public function testRejectsAWrongSecret(): void
    {
        $header = Signature::sign('whsec_someoneElse0000000000', self::BODY, self::NOW);
        self::assertFalse(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW));
    }

    public function testRejectsATamperedBody(): void
    {
        $header = Signature::sign(self::SECRET, self::BODY, self::NOW);
        $tampered = str_replace('invoice.paid', 'invoice.expired', self::BODY);
        self::assertFalse(Signature::verify(self::SECRET, $tampered, $header, 300, self::NOW));
        // Whitespace matters: a re-serialised body is a different body.
        self::assertFalse(Signature::verify(self::SECRET, self::BODY . "\n", $header, 300, self::NOW));
    }

    public function testRejectsAReplayedBodyUnderANewTimestamp(): void
    {
        $header = Signature::sign(self::SECRET, self::BODY, self::NOW);
        $parts = Signature::parse($header);
        self::assertNotNull($parts);
        $replayed = 't=' . (self::NOW + 10) . ',v1=' . $parts['v1'][0];
        self::assertFalse(Signature::verify(self::SECRET, self::BODY, $replayed, 300, self::NOW + 10));
    }

    public function testEnforcesTheFiveMinuteTolerance(): void
    {
        $header = Signature::sign(self::SECRET, self::BODY, self::NOW);
        self::assertTrue(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW + 300));
        self::assertFalse(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW + 301));
        self::assertFalse(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW - 301));
        self::assertTrue(Signature::verify(self::SECRET, self::BODY, $header, 0, self::NOW + 86400));
    }

    public function testAcceptsAnyOfSeveralV1ValuesAndIgnoresOtherVersions(): void
    {
        $good = hash_hmac('sha256', self::NOW . '.' . self::BODY, self::SECRET);
        $other = str_repeat('a', 64);
        $header = 't=' . self::NOW . ',v1=' . $other . ', v1=' . strtoupper($good) . ',v2=zzz';
        self::assertTrue(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHeaders(): iterable
    {
        $sig = str_repeat('0', 64);
        yield 'empty' => [''];
        yield 'no timestamp' => ['v1=' . $sig];
        yield 'no signature' => ['t=' . self::NOW];
        yield 'non-numeric timestamp' => ['t=abc,v1=' . $sig];
        yield 'short digest' => ['t=' . self::NOW . ',v1=abcd'];
        yield 'two timestamps' => ['t=' . self::NOW . ',t=1,v1=' . $sig];
        yield 'garbage' => ['not a header'];
        yield 'only v0' => ['t=' . self::NOW . ',v0=' . $sig];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function testRejectsMalformedHeaders(string $header): void
    {
        self::assertFalse(Signature::verify(self::SECRET, self::BODY, $header, 300, self::NOW));
    }

    public function testRejectsAnEmptySecret(): void
    {
        $header = Signature::sign('', self::BODY, self::NOW);
        self::assertFalse(Signature::verify('', self::BODY, $header, 300, self::NOW));
    }

    public function testComparesInConstantTime(): void
    {
        // The comparison must go through hash_equals, never == or strcmp.
        $source = (string) file_get_contents(__DIR__ . '/../../../Model/Signature.php');
        self::assertStringContainsString('hash_equals(', $source);
        self::assertDoesNotMatchRegularExpression('/\$expected\s*===?\s*\$|strcmp\(/', $source);
    }
}
