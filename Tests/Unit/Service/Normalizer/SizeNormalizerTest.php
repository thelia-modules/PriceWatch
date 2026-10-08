<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\SizeFamily;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

final class SizeNormalizerTest extends TestCase
{
    private SizeNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new SizeNormalizer(new TextNormalizer());
    }

    /**
     * @return iterable<string, array{?string, SizeFamily, ?string, ?string}>
     */
    public static function sizes(): iterable
    {
        yield 'alphabetical' => ['L', SizeFamily::Alpha, 'L', null];
        yield 'alphabetical lowercase' => ['xl', SizeFamily::Alpha, 'XL', null];
        yield 'doubled letter notation' => ['XXL', SizeFamily::Alpha, 'XXL', null];
        yield 'digit letter notation' => ['2XL', SizeFamily::Alpha, 'XXL', null];
        yield 'three X notation' => ['3XL', SizeFamily::Alpha, 'XXXL', null];
        yield 'garment number' => ['52', SizeFamily::Numeric, '52', null];
        yield 'helmet head circumference' => ['57', SizeFamily::Numeric, '57', null];
        yield 'helmet with a letter hint' => ['57 (M)', SizeFamily::Numeric, '57', 'M'];
        yield 'boot size' => ['43', SizeFamily::Numeric, '43', null];
        yield 'glove size' => ['10', SizeFamily::Numeric, '10', null];
        yield 'leading zero number' => ['09', SizeFamily::Numeric, '9', null];
        yield 'one size' => ['Taille unique', SizeFamily::Alpha, 'TU', null];
        yield 'one size english' => ['One Size', SizeFamily::Alpha, 'TU', null];
        yield 'colour is not a size' => ['Noir', SizeFamily::Unknown, null, null];
        yield 'empty' => ['', SizeFamily::Unknown, null, null];
        yield 'null' => [null, SizeFamily::Unknown, null, null];
        yield 'implausible number' => ['1023', SizeFamily::Unknown, null, null];
    }

    #[DataProvider('sizes')]
    public function testNormalisation(?string $raw, SizeFamily $family, ?string $canonical, ?string $alphaHint): void
    {
        $size = $this->normalizer->normalize($raw);

        self::assertSame($family, $size->family);
        self::assertSame($canonical, $size->canonical);
        self::assertSame($alphaHint, $size->alphaHint);
    }

    public function testSameFamilyAndSameValueMatch(): void
    {
        self::assertTrue(
            $this->normalizer->normalize('XL')->matches($this->normalizer->normalize('xl')),
        );
        self::assertTrue(
            $this->normalizer->normalize('57')->matches($this->normalizer->normalize('57 cm')),
        );
    }

    public function testNumberAndLetterNeverMatchAcrossFamilies(): void
    {
        // The whole point: a 52 garment number is not declared equal to an M.
        // That pair must fall through to a Jev variant judgment.
        self::assertFalse(
            $this->normalizer->normalize('52')->matches($this->normalizer->normalize('M')),
        );
        self::assertFalse(
            $this->normalizer->normalize('M')->matches($this->normalizer->normalize('52')),
        );
    }

    public function testTwoNumericSizesOfDifferentMeaningDoNotMatch(): void
    {
        self::assertFalse(
            $this->normalizer->normalize('52')->matches($this->normalizer->normalize('57')),
        );
    }

    public function testUnknownNeverMatchesAnything(): void
    {
        $unknown = $this->normalizer->normalize('Noir');

        self::assertFalse($unknown->matches($unknown));
        self::assertFalse($unknown->matches($this->normalizer->normalize('L')));
    }

    public function testLetterHintRescuesTwoNumericNotationsOfTheSameVariant(): void
    {
        self::assertTrue(
            $this->normalizer->normalize('57 (M)')->matches($this->normalizer->normalize('58 M')),
        );
    }
}
