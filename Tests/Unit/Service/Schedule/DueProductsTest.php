<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Schedule;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Schedule\DueProducts;
use PriceWatch\Service\Schedule\ScheduledProduct;
use PriceWatch\Service\Schedule\WatchTier;

final class DueProductsTest extends TestCase
{
    private const NOW = '2026-10-22 02:05:00';

    public function testAProductIsDueOnceItsPeriodHasPassedInCalendarDays(): void
    {
        $due = new DueProducts(['flagship' => 1, 'base' => 7, 'simple' => 15]);
        $now = new \DateTimeImmutable(self::NOW);

        self::assertTrue($due->isDue($this->product(1, WatchTier::Flagship, '2026-10-21 02:10:00'), $now), 'read yesterday after 02:00: due again tonight');
        self::assertFalse($due->isDue($this->product(2, WatchTier::Flagship, '2026-10-22 00:30:00'), $now), 'read today');
        self::assertFalse($due->isDue($this->product(3, WatchTier::Base, '2026-10-16 23:00:00'), $now), 'six days for a weekly one');
        self::assertTrue($due->isDue($this->product(4, WatchTier::Base, '2026-10-15 23:00:00'), $now));
        self::assertFalse($due->isDue($this->product(5, WatchTier::Simple, '2026-10-08 10:00:00'), $now), 'fourteen days for a fortnightly one');
        self::assertTrue($due->isDue($this->product(6, WatchTier::Simple, '2026-10-07 10:00:00'), $now));
        self::assertTrue($due->isDue($this->product(7, WatchTier::Simple, null), $now), 'never read');
    }

    public function testThePeriodsComeFromTheSettings(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $product = $this->product(1, WatchTier::Base, '2026-10-20 10:00:00');

        self::assertFalse((new DueProducts(['flagship' => 1, 'base' => 7, 'simple' => 15]))->isDue($product, $now));
        self::assertTrue((new DueProducts(['flagship' => 1, 'base' => 2, 'simple' => 15]))->isDue($product, $now));
        self::assertSame('2026-10-23', (new DueProducts(['base' => 3]))->nextDue($this->product(1, WatchTier::Base, '2026-10-20 10:00:00'), new \DateTimeImmutable('2026-10-21 08:00:00'))?->format('Y-m-d'));
        self::assertNull((new DueProducts(['base' => 1]))->nextDue($product, $now), 'due now: no later day');
    }

    public function testFlagshipsComeFirstThenNeverReadThenTheOldestReading(): void
    {
        $due = new DueProducts(['flagship' => 1, 'base' => 7, 'simple' => 15]);
        $products = [
            $this->product(1, WatchTier::Simple, null),
            $this->product(2, WatchTier::Base, '2026-10-01 10:00:00'),
            $this->product(3, WatchTier::Base, null),
            $this->product(4, WatchTier::Flagship, '2026-10-20 10:00:00'),
            $this->product(5, WatchTier::Base, '2026-09-20 10:00:00'),
            $this->product(6, WatchTier::Flagship, '2026-10-22 01:00:00'),
        ];

        self::assertSame([4, 3, 5, 2, 1], array_map(static fn (ScheduledProduct $product): int => $product->productId, $due->due($products, new \DateTimeImmutable(self::NOW))));
    }

    public function testABatchStopsAtTheVariantsCapWithoutSkippingAhead(): void
    {
        $due = new DueProducts(['flagship' => 1, 'base' => 7, 'simple' => 15]);
        $now = new \DateTimeImmutable(self::NOW);
        $products = [
            $this->product(1, WatchTier::Flagship, null, collectable: 6),
            $this->product(2, WatchTier::Base, null, collectable: 5),
            $this->product(3, WatchTier::Base, '2026-10-01 10:00:00', collectable: 1),
        ];

        self::assertSame([1], $this->ids($due->batch($products, $now, 10)), 'the second would overflow; the small third waits too, so the order holds');
        self::assertSame([1, 2], $this->ids($due->batch($products, $now, 11)));
        self::assertSame([1], $this->ids($due->batch($products, $now, 3)), 'alone above the cap, the first still goes');
        self::assertSame([], $due->batch([$this->product(9, WatchTier::Base, '2026-10-21 10:00:00')], $now, 100), 'nothing due, nothing taken');
    }

    public function testABatchTakesAtMostFiftyProducts(): void
    {
        $products = array_map(fn (int $id): ScheduledProduct => $this->product($id, WatchTier::Base, null, collectable: 0), range(1, 60));

        self::assertCount(DueProducts::MAX_PRODUCTS, (new DueProducts([]))->batch($products, new \DateTimeImmutable(self::NOW), 500));
    }

    /**
     * @param list<ScheduledProduct> $products
     *
     * @return list<int>
     */
    private function ids(array $products): array
    {
        return array_map(static fn (ScheduledProduct $product): int => $product->productId, $products);
    }

    private function product(int $id, WatchTier $tier, ?string $lastRead, int $collectable = 1): ScheduledProduct
    {
        return new ScheduledProduct($id, 'REF'.$id, $tier, $collectable, $lastRead === null ? null : new \DateTimeImmutable($lastRead));
    }
}
