<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\SalesTrend;

final class SalesTrendTest extends TestCase
{
    public function testBothChangesAreGiven(): void
    {
        $trend = new SalesTrend(6, 10, 4);

        self::assertEqualsWithDelta(-40.0, $trend->yearOnYearPct(), 1e-9);
        self::assertEqualsWithDelta(50.0, $trend->periodOnPeriodPct(), 1e-9);
    }

    public function testTheYearBeforeDecidesWhenItSoldEnough(): void
    {
        self::assertTrue((new SalesTrend(6, 10, 4))->declining(), 'down on last year, up on the summer: seasonal, still a decline');
        self::assertFalse((new SalesTrend(4, 4, 10))->declining(), 'steady on last year, down on the summer: the season, not a decline');
    }

    public function testWithoutAYearBeforeThePeriodBeforeDecides(): void
    {
        self::assertTrue((new SalesTrend(3, 0, 5))->declining());
        self::assertFalse((new SalesTrend(5, 1, 5))->declining(), 'one unit last year is too thin to compare with');
    }

    public function testADeclineStartsAt20Percent(): void
    {
        self::assertTrue((new SalesTrend(8, 10, 0))->declining());
        self::assertFalse((new SalesTrend(9, 10, 0))->declining());
    }

    public function testNoHistoryIsNoDecline(): void
    {
        self::assertFalse(SalesTrend::none()->declining());
        self::assertNull(SalesTrend::none()->yearOnYearPct());
        self::assertNull((new SalesTrend(5, 1, 1))->periodOnPeriodPct());
    }
}
