<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

/**
 * The tabs of the comparator screen. Every active offer of a whitelisted
 * competitor is in exactly one of them, so nothing collected can go unseen.
 */
enum ComparatorView: string
{
    /** Same product, same size, comparable price: what the gap is made of. */
    case Gaps = 'gaps';

    /** Neither the code nor Jev could decide: a human must. */
    case Review = 'review';

    /** Collected, not judged yet: pricewatch:judge has not run since. */
    case Pending = 'pending';

    /** Not the same item, or the same item at a price that does not compare. */
    case Excluded = 'excluded';

    public const MATCHED_STATES = ['matched_exact', 'matched_judged', 'matched_manual'];

    public static function fromQuery(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Gaps : self::Gaps;
    }
}
