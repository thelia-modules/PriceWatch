<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\MpnNormalizer;

final class MpnNormalizerTest extends TestCase
{
    private MpnNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MpnNormalizer();
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function partNumbers(): iterable
    {
        yield 'dashes removed' => ['IXON-100101045-1023-L', 'IXON1001010451023L'];
        yield 'slashes and spaces removed' => ['IXON 100101045/1023 L', 'IXON1001010451023L'];
        yield 'lowercase upcased' => ['ixon100101045_1023l', 'IXON1001010451023L'];
        yield 'digits only' => ['7138563', '7138563'];
        yield 'too short becomes null' => ['L', null];
        yield 'three characters becomes null' => ['A-B', null];
        yield 'empty becomes null' => ['', null];
        yield 'null stays null' => [null, null];
    }

    #[DataProvider('partNumbers')]
    public function testNormalisation(?string $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($raw));
    }

    public function testEveryWrittenFormOfOnePartNumberCollapsesToOneKey(): void
    {
        $forms = ['IXON-100101045-1023-L', 'ixon 100101045 1023 l', 'IXON.100101045/1023_L'];
        $keys = array_map(fn (string $form): ?string => $this->normalizer->normalize($form), $forms);

        self::assertCount(1, array_unique($keys));
    }

    public function testVariantIsEncodedWhenThePartNumberEndsWithTheSize(): void
    {
        $mpn = $this->normalizer->normalize('IXON-100101045-1023-L');

        self::assertTrue($this->normalizer->encodesVariant($mpn, 'L'));
    }

    public function testVariantIsNotEncodedWhenTheSuffixIsADifferentSize(): void
    {
        $mpn = $this->normalizer->normalize('IXON-100101045-1023-L');

        self::assertFalse($this->normalizer->encodesVariant($mpn, 'XL'));
    }

    public function testGenericPartNumberDoesNotEncodeTheVariant(): void
    {
        // Identifies the article but not the size: this is exactly the case
        // that must not go down the deterministic rail.
        $mpn = $this->normalizer->normalize('7138563');

        self::assertFalse($this->normalizer->encodesVariant($mpn, 'L'));
    }

    public function testShortPartNumberEndingWithTheSizeStillDoesNotEncodeTheVariant(): void
    {
        // A four-character part number that happens to end in "L" is a
        // coincidence, not a variant encoding. This is the case the length
        // guard exists for, and the suffix check alone would let it through.
        $mpn = $this->normalizer->normalize('A12L');

        self::assertSame('A12L', $mpn);
        self::assertFalse($this->normalizer->encodesVariant($mpn, 'L'));
    }

    public function testMissingInputNeverEncodesTheVariant(): void
    {
        self::assertFalse($this->normalizer->encodesVariant(null, 'L'));
        self::assertFalse($this->normalizer->encodesVariant('IXON1001010451023L', null));
    }
}
