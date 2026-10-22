<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\SalesHistogram;

/**
 * On 2026-10-15 the chart runs from 11/2024 (slot 0, x 0) to 10/2026
 * (slot 23, x 115); bars stand on y 32 and the peak is 30 high.
 */
final class SalesHistogramTest extends TestCase
{
    private const NOW = '2026-10-15 12:00:00';

    public function testNoSaleInTheWindowDrawsNothing(): void
    {
        self::assertNull(SalesHistogram::of([], new \DateTimeImmutable(self::NOW)));
        self::assertNull(SalesHistogram::of(['2026-09' => 0, '2024-10' => 12], new \DateTimeImmutable(self::NOW)), 'the month before the window is not drawn');
    }

    public function testEveryMonthHasItsSlotAndThePeakSetsTheScale(): void
    {
        $chart = SalesHistogram::of(['2026-10' => 10, '2025-10' => 5, '2025-11' => 2], new \DateTimeImmutable(self::NOW));

        self::assertNotNull($chart);
        self::assertCount(24, $chart->bars);
        self::assertSame(['11/2024', 0, 0.0], [$chart->bars[0]['month'], $chart->bars[0]['units'], $chart->bars[0]['height']]);
        self::assertSame(['x' => 115, 'y' => 2.0, 'height' => 30.0, 'month' => '10/2026', 'units' => 10, 'lastTwelve' => true], $chart->bars[23]);
        self::assertSame(['x' => 55, 'y' => 17.0, 'height' => 15.0, 'month' => '10/2025', 'units' => 5, 'lastTwelve' => false], $chart->bars[11]);
        self::assertTrue($chart->bars[12]['lastTwelve'], '11/2025 opens the last twelve months');
        self::assertSame([12, 5], [$chart->lastTwelveUnits, $chart->twelveBeforeUnits]);
    }

    public function testASingleUnitStillShowsNextToABigPeak(): void
    {
        $chart = SalesHistogram::of(['2026-10' => 100, '2026-09' => 1], new \DateTimeImmutable(self::NOW));

        self::assertNotNull($chart);
        self::assertSame(1.0, $chart->bars[22]['height']);
    }

    public function testTheWindowStartsOnAFirstOfMonthWhateverTheDay(): void
    {
        self::assertSame('2024-04-01 00:00:00', SalesHistogram::since(new \DateTimeImmutable('2026-03-31 18:00:00'))->format('Y-m-d H:i:s'));
    }
}
