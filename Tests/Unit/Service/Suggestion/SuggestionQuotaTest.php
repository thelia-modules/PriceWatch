<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Suggestion;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Suggestion\SuggestionQuota;

final class SuggestionQuotaTest extends TestCase
{
    /** Revenue of each top-level family over 90 days, measured 2026-10-12. */
    private const REVENUE = [111 => 198045.0, 71 => 33567.0, 296 => 31421.0, 112 => 9626.0, 113 => 4572.0, 76 => 801.0];

    /**
     * Asked 2026-10-12: helmets took 47 lines out of 50. Each family now gets
     * its share of the revenue, and at least three lines.
     */
    public function testEachFamilyGetsItsShareOfRevenueAndAtLeastThreeLines(): void
    {
        $quota = SuggestionQuota::allocate(self::REVENUE, array_fill_keys(array_keys(self::REVENUE), 50), 50, 3);

        self::assertSame([111 => 31, 71 => 5, 296 => 5, 112 => 3, 113 => 3, 76 => 3], $quota);
    }

    public function testAFamilyShortOfCandidatesLeavesItsLinesToTheOthers(): void
    {
        $quota = SuggestionQuota::allocate(self::REVENUE, [111 => 50, 71 => 50, 296 => 2, 112 => 50, 113 => 1, 76 => 0], 50, 3);

        self::assertSame(2, $quota[296]);
        self::assertSame(1, $quota[113]);
        self::assertArrayNotHasKey(76, $quota, 'no candidate, no line');
        self::assertSame(50, array_sum($quota));
    }

    public function testAFamilyWithoutSalesStillShowsItsMinimum(): void
    {
        $quota = SuggestionQuota::allocate([111 => 1000.0], [111 => 50, 71 => 10], 50, 3);

        self::assertSame([111 => 47, 71 => 3], $quota, 'a deep promotion with no sale yet is worth a look');
    }

    public function testMoreFamiliesThanRoomKeepTheBestSelling(): void
    {
        $quota = SuggestionQuota::allocate([1 => 50.0, 2 => 40.0, 3 => 30.0, 4 => 20.0], [1 => 9, 2 => 9, 3 => 9, 4 => 9], 7, 3);

        self::assertSame([1 => 4, 2 => 3], $quota, 'the minimum is kept, the smallest sellers wait');
    }

    public function testTheLinesAreTheBestOfEachFamilyInTheirOrder(): void
    {
        // Ranked by score: three helmets, then a glove, then a helmet, then a jacket.
        $ranked = [11, 12, 13, 21, 14, 31];
        $familyOf = [11 => 1, 12 => 1, 13 => 1, 14 => 1, 21 => 2, 31 => 3];

        self::assertSame([11, 12, 21, 31], \PriceWatch\Service\Suggestion\SuggestionPresenter::lines($ranked, $familyOf, [1 => 2, 2 => 1, 3 => 1]));
    }

    public function testFewerCandidatesThanRoomShowsThemAll(): void
    {
        self::assertSame([2 => 2, 1 => 4], SuggestionQuota::allocate([1 => 10.0, 2 => 90.0], [1 => 4, 2 => 2], 50, 3), 'the best selling family first');
    }
}
