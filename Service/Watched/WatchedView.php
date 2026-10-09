<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

/**
 * The tabs of the watched products list. "All" holds every product of the
 * referential, collected or not; the others are slices of it.
 */
enum WatchedView: string
{
    case All = 'all';

    /** Watched, never searched yet. */
    case Never = 'never';

    /** Watched, but no variant has a valid GTIN of its own: never collected. */
    case NotCollectable = 'not_collectable';

    /** At least one competitor offer compared. */
    case Compared = 'compared';

    /** Every variant paused. */
    case Paused = 'paused';

    public static function fromQuery(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::All : self::All;
    }
}
