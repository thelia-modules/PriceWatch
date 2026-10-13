<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

/**
 * When a product was last watched: the latest of its last Google Shopping
 * search and its last competitor page read straight. Beyond STALE_DAYS, what
 * the competitors showed may no longer hold: the back office says so.
 */
final readonly class LastWatch
{
    public const STALE_DAYS = 14;

    /** Never watched is not stale: it is a state of its own. */
    public static function isStale(?\DateTimeInterface $lastWatched, \DateTimeInterface $now): bool
    {
        return $lastWatched !== null && $lastWatched < self::staleBefore($now);
    }

    public static function staleBefore(\DateTimeInterface $now): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($now)->modify('-'.self::STALE_DAYS.' days');
    }

    /**
     * Calendar days, as a reader counts them: yesterday at 14:57 is one day
     * ago the next morning, though less than 24 hours old.
     */
    public static function daysAgo(\DateTimeInterface $lastWatched, \DateTimeInterface $now): int
    {
        return (int) \DateTimeImmutable::createFromInterface($lastWatched)->setTime(0, 0)->diff(\DateTimeImmutable::createFromInterface($now)->setTime(0, 0))->days;
    }
}
