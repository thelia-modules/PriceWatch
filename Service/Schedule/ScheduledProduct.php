<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

/**
 * A watched product as the scheduler sees it. lastRead is the latest of its
 * last Google Shopping search (made and paid, found or not) and its last
 * competitor page read with a price; scheduledAt, when a scheduled pass last
 * took it, whatever came of it.
 */
final readonly class ScheduledProduct
{
    public function __construct(
        public int $productId,
        public string $ref,
        public WatchTier $tier,
        public int $collectable,
        public ?\DateTimeImmutable $lastRead,
        public ?\DateTimeImmutable $scheduledAt = null,
    ) {
    }
}
