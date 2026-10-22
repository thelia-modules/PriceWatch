<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Service\Watched\LastWatch;

/**
 * Which watched products a scheduled pass reads, and in what order.
 *
 * Due when never read, or read at least the tier's period ago, in calendar
 * days: a flagship read yesterday at 02:10 is due again tonight at 02:00.
 * Flagships first, then the oldest reading.
 */
final readonly class DueProducts
{
    /**
     * Each product is one argument of the background shell, and its pages
     * are read one by one by the direct pass: a pass stays under an hour.
     */
    public const MAX_PRODUCTS = 50;

    /**
     * @param array<string, int> $daysByTier tier value => days
     */
    public function __construct(
        private array $daysByTier,
    ) {
    }

    public function isDue(ScheduledProduct $product, \DateTimeInterface $now): bool
    {
        return $product->lastRead === null || LastWatch::daysAgo($product->lastRead, $now) >= $this->days($product->tier);
    }

    /**
     * The day the product is due again; null when it is due now.
     */
    public function nextDue(ScheduledProduct $product, \DateTimeInterface $now): ?\DateTimeImmutable
    {
        if ($this->isDue($product, $now) || $product->lastRead === null) {
            return null;
        }

        return $product->lastRead->setTime(0, 0)->modify('+'.$this->days($product->tier).' days');
    }

    /**
     * @param list<ScheduledProduct> $products
     *
     * @return list<ScheduledProduct> every due product, in reading order
     */
    public function due(array $products, \DateTimeInterface $now): array
    {
        $due = array_values(array_filter($products, fn (ScheduledProduct $product): bool => $this->isDue($product, $now)));

        usort($due, static fn (ScheduledProduct $one, ScheduledProduct $other): int => [$one->tier->priority(), $one->lastRead !== null, $one->lastRead, $one->productId]
            <=> [$other->tier->priority(), $other->lastRead !== null, $other->lastRead, $other->productId]);

        return $due;
    }

    /**
     * The due products one pass takes: in order, as long as the variants to
     * search stay within the cap. A product alone above the cap still goes,
     * or it would block the list forever; one that does not fit waits for
     * the next pass, and the products after it are not tried in its place,
     * so the order holds.
     *
     * @param list<ScheduledProduct> $products
     *
     * @return list<ScheduledProduct>
     */
    public function batch(array $products, \DateTimeInterface $now, int $maxVariants): array
    {
        $batch = [];
        $variants = 0;

        foreach ($this->due($products, $now) as $product) {
            if (\count($batch) >= self::MAX_PRODUCTS || ($batch !== [] && $variants + $product->collectable > $maxVariants)) {
                break;
            }

            $batch[] = $product;
            $variants += $product->collectable;
        }

        return $batch;
    }

    private function days(WatchTier $tier): int
    {
        return $this->daysByTier[$tier->value] ?? $tier->defaultDays();
    }
}
