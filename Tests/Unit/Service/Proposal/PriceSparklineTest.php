<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\PriceSparkline;

/**
 * The drawing area runs from 3 to 117 across and from 29 (lowest price) up
 * to 3 (highest price).
 */
final class PriceSparklineTest extends TestCase
{
    public function testNoReadingDrawsNothing(): void
    {
        self::assertNull(PriceSparkline::of([]));
        self::assertNull(PriceSparkline::of(['2026-10-12' => ['ours' => null, 'cheapest' => null]]));
    }

    public function testBothSeriesShareOneScaleAndTheCalendarSpacesTheDays(): void
    {
        $chart = PriceSparkline::of([
            '2026-10-22' => ['ours' => 110.0, 'cheapest' => 100.0],
            '2026-10-12' => ['ours' => 120.0, 'cheapest' => 105.0],
            '2026-10-14' => ['ours' => 120.0, 'cheapest' => null],
        ]);

        self::assertNotNull($chart);
        self::assertSame('3.0,3.0 25.8,3.0 117.0,16.0', $chart->oursPolyline(), 'days sorted, 2 days out of 10 is a fifth of the width');
        self::assertSame('3.0,22.5 117.0,29.0', $chart->cheapestPolyline(), 'a day without a comparable reading carries no point');
        self::assertSame([100.0, 120.0], [$chart->low, $chart->high]);
        self::assertSame(['12/10/2026', '22/10/2026', 3], [$chart->from, $chart->to, $chart->readings]);
        self::assertSame([120.0, 110.0, 105.0, 100.0], [$chart->oursFirst, $chart->oursLast, $chart->cheapestFirst, $chart->cheapestLast]);
    }

    public function testASingleReadingSitsInTheMiddle(): void
    {
        $chart = PriceSparkline::of(['2026-10-22' => ['ours' => 151.99, 'cheapest' => 151.99]]);

        self::assertNotNull($chart);
        self::assertSame('60.0,16.0', $chart->oursPolyline());
        self::assertSame('60.0,16.0', $chart->cheapestPolyline());
    }

    /**
     * Seen 2026-10-22: 151.99 € down to 150.09 € filled the whole height.
     */
    public function testASmallMoveDrawsASmallSlope(): void
    {
        $chart = PriceSparkline::of([
            '2026-10-13' => ['ours' => null, 'cheapest' => 151.99],
            '2026-10-22' => ['ours' => null, 'cheapest' => 150.09],
        ]);

        self::assertNotNull($chart);
        self::assertSame('3.0,14.4 117.0,17.6', $chart->cheapestPolyline(), 'the height stands for 10 % of the highest price');
        self::assertSame([150.09, 151.99], [$chart->low, $chart->high], 'the prices read stay as read');
    }

    public function testWithoutAnyCompetitorReadingOurPriceIsDrawnAlone(): void
    {
        $chart = PriceSparkline::of([
            '2026-10-12' => ['ours' => 90.0, 'cheapest' => null],
            '2026-10-13' => ['ours' => 100.0, 'cheapest' => null],
        ]);

        self::assertNotNull($chart);
        self::assertSame('3.0,29.0 117.0,3.0', $chart->oursPolyline());
        self::assertSame('', $chart->cheapestPolyline());
        self::assertNull($chart->cheapestFirst);
    }
}
