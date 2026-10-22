<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Suggestion;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Suggestion\SuggestionScore;

final class SuggestionScoreTest extends TestCase
{
    public function testEverySignalAtItsFullAddsUpTo100(): void
    {
        self::assertEqualsWithDelta(100.0, SuggestionScore::of(1.0, 40.0, true, 1.0)->total(), 0.0001);
        self::assertEqualsWithDelta(0.0, SuggestionScore::of(0.0, null, false, 0.0)->total(), 0.0001);
    }

    public function testEachPartIsCappedAtItsWeight(): void
    {
        $score = SuggestionScore::of(3.0, 95.0, false, 7.0);

        self::assertSame(['sales' => 54.0, 'promo' => 30.0, 'new' => 0.0, 'stock' => 8.0], $score->parts());
    }

    public function testSalesAndStockFollowTheSquareRootOfTheirShare(): void
    {
        $score = SuggestionScore::of(0.25, null, false, 0.25);

        self::assertEqualsWithDelta(27.0, $score->sales, 0.0001, 'a quarter of the best revenue: half the weight');
        self::assertEqualsWithDelta(4.0, $score->stock, 0.0001);
    }

    public function testAShallowPromotionCountsForNothing(): void
    {
        self::assertSame(0.0, SuggestionScore::of(0.0, 9.9, false, 0.0)->promo);
        self::assertSame(0.0, SuggestionScore::of(0.0, -50.0, false, 0.0)->promo, 'a promotional price above the price is a catalogue error, not a promotion');
        self::assertEqualsWithDelta(15.0, SuggestionScore::of(0.0, 25.0, false, 0.0)->promo, 0.0001, 'half way between 10 and 40 %');
    }
}
