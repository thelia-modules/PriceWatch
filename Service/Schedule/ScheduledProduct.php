<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

/**
 * A watched product as the scheduler sees it. lastRead is the latest of its
 * last Google Shopping search, its last competitor page read and the last
 * scheduled pass that took it.
 */
final readonly class ScheduledProduct
{
    public function __construct(
        public int $productId,
        public string $ref,
        public WatchTier $tier,
        public int $collectable,
        public ?\DateTimeImmutable $lastRead,
    ) {
    }
}
