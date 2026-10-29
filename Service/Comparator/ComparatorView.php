<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

/**
 * The tabs of the comparator screen. Every active offer of a whitelisted
 * competitor is in exactly one of them, so nothing collected can go unseen.
 */
enum ComparatorView: string
{
    /**
     * Same product, same size, comparable price: what the gap is made of.
     * With them, tagged to check, the offers only doubted on their price or
     * their size (GAP_STATES).
     */
    case Gaps = 'gaps';

    /** Not sure it is the same article: a human must decide. */
    case Review = 'review';

    /** Collected, not judged yet: pricewatch:judge has not run since. */
    case Pending = 'pending';

    /** Not the same item, or the same item at a price that does not compare. */
    case Excluded = 'excluded';

    public const MATCHED_STATES = ['matched_exact', 'matched_judged', 'matched_manual'];

    /**
     * An offer a human has not decided on, doubted only on its price or its
     * size (PRICE_GAP_SUSPECT, PRICE_GAP_IMPLAUSIBLE, VARIANT_UNCLEAR,
     * GTIN_UNVERIFIED, REOPENED), keeps last_comparable = 1: listed and counted with the gaps,
     * tagged to check (asked 2026-10-13: a doubtful price seen beats a price
     * hidden in the review tab). A doubt on the article sets it to 0.
     */
    public const GAP_STATES = [...self::MATCHED_STATES, 'needs_review'];

    public static function toCheck(?string $state, int|string|null $comparable): bool
    {
        return $state === 'needs_review' && (int) $comparable === 1;
    }

    /**
     * Whether an offer counts in the gap: what the gaps tab lists, and what
     * every other screen counts as compared.
     */
    public static function inGap(?string $state, int|string|null $comparable): bool
    {
        return \in_array($state, self::GAP_STATES, true) && (int) $comparable === 1;
    }

    public static function inReview(?string $state, int|string|null $comparable): bool
    {
        return $state === 'needs_review' && (int) $comparable !== 1;
    }

    /**
     * The same rules in SQL, on a pw_competitor_offer row.
     *
     * @param string $prefix the table or alias with its dot, or nothing
     */
    public static function inGapSql(string $prefix = ''): string
    {
        return "({$prefix}last_comparable = 1 AND {$prefix}match_state IN ('".implode("','", self::GAP_STATES)."'))";
    }

    public static function inReviewSql(string $prefix = ''): string
    {
        return "({$prefix}match_state = 'needs_review' AND {$prefix}last_comparable = 0)";
    }

    public static function fromQuery(mixed $value): self
    {
        return \is_string($value) ? self::tryFrom($value) ?? self::Gaps : self::Gaps;
    }
}
