<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\GtinNormalizer;

final class GtinNormalizerTest extends TestCase
{
    private GtinNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GtinNormalizer();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validGtins(): iterable
    {
        yield 'EAN-13' => ['4006381333931', '04006381333931'];
        yield 'EAN-13 whose check digit is zero' => ['4006381333900', '04006381333900'];
        yield 'GTIN-8' => ['96385074', '00000096385074'];
        yield 'UPC-A 12 digits' => ['036000291452', '00036000291452'];
        yield 'already 14 digits' => ['04006381333931', '04006381333931'];
        yield 'spaces and dashes' => [' 4006-381 333931 ', '04006381333931'];
        yield 'EAN-13 with a leading zero' => ['0123456789012', '00123456789012'];
    }

    #[DataProvider('validGtins')]
    public function testValidGtinNormalisesToFourteenDigitsAndPassesTheCheckDigit(string $raw, string $expected): void
    {
        $gtin = $this->normalizer->normalize($raw);

        self::assertSame($expected, $gtin->value);
        self::assertTrue($gtin->checkDigitValid, $raw.' should have a valid check digit');
        self::assertTrue($gtin->isUsable());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableLengths(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'letters only' => ['ABCDEFGH'];
        yield 'ten digits' => ['1234567890'];
        yield 'eleven digits' => ['12345678901'];
        yield 'fifteen digits' => ['123456789012345'];
        yield 'single digit' => ['7'];
    }

    #[DataProvider('unusableLengths')]
    public function testLengthOutsideTheAcceptedSetIsUnusable(?string $raw): void
    {
        $gtin = $this->normalizer->normalize($raw);

        self::assertNull($gtin->value);
        self::assertFalse($gtin->checkDigitValid);
        self::assertFalse($gtin->isUsable());
    }

    public function testRightLengthButWrongCheckDigitIsNormalisedAndRejected(): void
    {
        // 3660600123454 is the valid form; 7 is a typo in the last digit.
        $gtin = $this->normalizer->normalize('3660600123457');

        self::assertSame('03660600123457', $gtin->value, 'the value is still normalised, so it can be shown to a human');
        self::assertFalse($gtin->checkDigitValid);
        self::assertFalse($gtin->isUsable(), 'an invalid GTIN must never reach the deterministic rail');
    }

    public function testCheckDigitIsComputedOnTheThirteenDigitBody(): void
    {
        self::assertSame(1, $this->normalizer->checkDigit('0400638133393'));
        self::assertSame(4, $this->normalizer->checkDigit('0366060012345'));
        self::assertSame(0, $this->normalizer->checkDigit('0400638133390'));
    }

    public function testLeftPaddingDoesNotChangeTheCheckDigit(): void
    {
        // A digit's weight depends on its distance to the check digit, so
        // padding GTIN-8 up to 14 must keep the same verdict.
        self::assertTrue($this->normalizer->hasValidCheckDigit('00000096385074'));
        self::assertSame(
            $this->normalizer->checkDigit('9638507'),
            $this->normalizer->checkDigit('0000009638507'),
        );
    }

    public function testNonDigitPayloadIsNeverTreatedAsAGtin(): void
    {
        self::assertFalse($this->normalizer->hasValidCheckDigit('0400638133393X'));
        self::assertFalse($this->normalizer->hasValidCheckDigit('123'));
    }
}
