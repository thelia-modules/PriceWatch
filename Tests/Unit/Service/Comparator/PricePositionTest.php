<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Comparator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Comparator\PricePosition;

/**
 * Gaps are (their price - our price) / our price, in percent. The colour is
 * read from our side: green when we are cheaper, red when we are dearer.
 */
final class PricePositionTest extends TestCase
{
    /**
     * @return iterable<string, array{string|float|null, PricePosition}>
     */
    public static function gaps(): iterable
    {
        yield 'competitor 30 % cheaper: we are dearer' => ['-30.063', PricePosition::WeAreDearer];
        yield 'competitor 5 % dearer: we are cheaper' => ['5.000', PricePosition::WeAreCheaper];
        yield 'exactly the same price' => ['0.000', PricePosition::Equal];
        yield 'shown as -0.0 %: neutral' => [-0.049, PricePosition::Equal];
        yield 'shown as +0.0 %: neutral' => [0.049, PricePosition::Equal];
        yield 'shown as -0.1 %: coloured' => [-0.05, PricePosition::WeAreDearer];
        yield 'shown as +0.1 %: coloured' => [0.05, PricePosition::WeAreCheaper];
        yield 'no gap known' => [null, PricePosition::Unknown];
    }

    #[DataProvider('gaps')]
    public function testThePositionIsReadFromOurSide(string|float|null $gapPct, PricePosition $expected): void
    {
        self::assertSame($expected, PricePosition::fromGapPct($gapPct));
    }
}
