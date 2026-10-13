<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Suggestion;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Suggestion\SuggestionScore;

final class SuggestionScoreTest extends TestCase
{
    public function testEverySignalAtItsFullAddsUpTo100(): void
    {
        self::assertEqualsWithDelta(100.0, SuggestionScore::of(1.0, -20.0, 40.0, 60, true, 1.0)->total(), 0.0001);
        self::assertEqualsWithDelta(0.0, SuggestionScore::of(0.0, null, null, null, false, 0.0)->total(), 0.0001);
    }

    public function testEachPartIsCappedAtItsWeight(): void
    {
        $score = SuggestionScore::of(3.0, -80.0, 95.0, 400, false, 7.0);

        self::assertSame(['sales' => 35.0, 'dearer' => 25.0, 'promo' => 20.0, 'stale' => 10.0, 'new' => 0.0, 'stock' => 5.0], $score->parts());
    }

    public function testSalesAndStockFollowTheSquareRootOfTheirShare(): void
    {
        $score = SuggestionScore::of(0.25, null, null, null, false, 0.25);

        self::assertEqualsWithDelta(17.5, $score->sales, 0.0001, 'a quarter of the best revenue: half the weight');
        self::assertEqualsWithDelta(2.5, $score->stock, 0.0001);
    }

    public function testAShallowPromotionCountsForNothing(): void
    {
        self::assertSame(0.0, SuggestionScore::of(0.0, null, 9.9, null, false, 0.0)->promo);
        self::assertSame(0.0, SuggestionScore::of(0.0, null, -50.0, null, false, 0.0)->promo, 'a promotional price above the price is a catalogue error, not a promotion');
        self::assertEqualsWithDelta(10.0, SuggestionScore::of(0.0, null, 25.0, null, false, 0.0)->promo, 0.0001, 'half way between 10 and 40 %');
    }

    public function testTheGapAndTheAgeGrowLinearly(): void
    {
        $score = SuggestionScore::of(0.0, -10.0, null, 30, false, 0.0);

        self::assertEqualsWithDelta(12.5, $score->dearer, 0.0001);
        self::assertEqualsWithDelta(5.0, $score->stale, 0.0001);
    }
}
