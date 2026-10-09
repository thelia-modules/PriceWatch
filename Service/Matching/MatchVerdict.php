<?php

declare(strict_types=1);

namespace PriceWatch\Service\Matching;

/**
 * Identity and price are two separate verdicts. An offer can be the right
 * variant while its price is not comparable (a clearance price, say): the
 * match stands, the price is kept out of the gap.
 */
final readonly class MatchVerdict
{
    public const MATCHED = 'matched_judged';
    public const REJECTED = 'rejected';
    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        public string $state,
        public string $reason,
        public bool $comparable,
        public ?string $exclusionReason,
        public bool $judgedByJev,
    ) {
    }
}
