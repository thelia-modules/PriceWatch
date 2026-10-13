<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Source\ShownSize;

/**
 * Details are those of live Google Shopping sellers (2026-10-12): a card lists
 * its sellers for one size, named somewhere in each offer's details.
 */
final class ShownSizeTest extends TestCase
{
    private const HELMET = ['1' => 'XS', '2' => 'S', '3' => 'M', '4' => 'L', '5' => 'XL'];

    /**
     * @return iterable<string, array{?string, array<array-key, ?string>, list<string>}>
     */
    public static function details(): iterable
    {
        yield 'size after the colour' => ['Casque SZ-R Vas Evo Arai Noir Mat S - casque jet moto Arai Homme ou Femme', self::HELMET, ['2']];
        yield 'size after the gender' => ['Arai Casque jet SZ-R VAS EVO - SOLID unisexe M', self::HELMET, ['3']];
        yield 'model letters are no size' => ['Arai Casque SZ-R VAS EVO', self::HELMET, ['1', '2', '3', '4', '5']];
        yield 'two of our sizes named: undecided' => ['Taille S ou M', self::HELMET, ['1', '2', '3', '4', '5']];
        yield 'a size we do not sell: undecided' => ['Arai Casque jet SZ-R VAS EVO XXL', self::HELMET, ['1', '2', '3', '4', '5']];
        yield 'no details' => [null, self::HELMET, ['1', '2', '3', '4', '5']];
        yield 'written apart' => ['Blouson cuir 2 XL', ['1' => 'XL', '2' => '2XL'], ['2']];
        yield 'one notation each side' => ['Blouson cuir XXL', ['1' => 'XL', '2' => '2XL'], ['2']];
        yield 'two colours in that size' => ['Casque M', ['1' => 'M', '2' => 'M', '3' => 'L'], ['1', '2']];
        yield 'a variant without size is never dropped' => ['Casque M', ['1' => 'M', '2' => null], ['1', '2']];
        yield 'head circumference' => ['Casque jet 57 cm', ['1' => '55', '2' => '57', '3' => '59'], ['2']];
    }

    /**
     * @param array<array-key, ?string> $sizeByTag
     * @param list<string>              $expected
     */
    #[DataProvider('details')]
    public function testOnlyTheVariantsOfTheSizeShownAreKept(?string $details, array $sizeByTag, array $expected): void
    {
        self::assertSame($expected, (new ShownSize(new SizeNormalizer(new TextNormalizer())))->variantsShown($details, $sizeByTag));
    }
}
