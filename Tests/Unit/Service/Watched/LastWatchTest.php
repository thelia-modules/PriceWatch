<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Watched;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Watched\LastWatch;

final class LastWatchTest extends TestCase
{
    public function testAReadingIsStaleBeyondFourteenDays(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 12:00:00');

        self::assertFalse(LastWatch::isStale(new \DateTimeImmutable('2026-09-28 12:00:00'), $now), 'exactly 14 days: still fresh');
        self::assertTrue(LastWatch::isStale(new \DateTimeImmutable('2026-09-28 11:59:59'), $now));
        self::assertFalse(LastWatch::isStale(null, $now), 'never watched is a state of its own');
    }

    public function testDaysAgoCountsWholeDays(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 12:00:00');

        self::assertSame(0, LastWatch::daysAgo(new \DateTimeImmutable('2026-10-12 08:00:00'), $now));
        self::assertSame(20, LastWatch::daysAgo(new \DateTimeImmutable('2026-09-22 11:00:00'), $now));
    }
}
