<?php

declare(strict_types=1);

namespace PriceWatch\Service\Matching;

use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\OfferQuestions;

/**
 * Where code decides. Three stages:
 *
 * 1. prefilter(): what code can see for itself, before paying for anything.
 *    A second-hand item, a foreign currency. Jev is never asked about those.
 * 2. decide(): Jev's typed answers turned into a state, only in the decisive
 *    bands. Anything in between goes to a human; it is never a pass.
 * 3. withGap(): a price far from ours is a doubt, checked, never decided by
 *    the gap alone (asked 2026-10-12). Beyond 70 % it used to be rejected
 *    blind; from 25 % a Jev "yes" alone let it into the gap.
 */
final readonly class MatchDecision
{
    /** Beyond this gap, even a proven match is a data problem for a human. */
    public const IMPLAUSIBLE_GAP = 0.70;

    /** From this gap on, Jev's word alone does not let an offer into the gap. */
    public const SUSPECT_GAP = 0.25;

    private const USED_CONDITIONS = ['used', 'refurbished', 'pre-owned', 'occasion', 'reconditionne'];

    /** Price answers that leave the price in the gap, even when Jev is sure of them. */
    private const PLAIN_PRICE = ['comparable', 'unclear'];

    public function prefilter(string $ourPriceInclTax, ?string $theirPrice, ?string $currency, ?string $condition): ?MatchVerdict
    {
        if ($currency !== null && strtoupper($currency) !== 'EUR') {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'CURRENCY_MISMATCH', false, 'CURRENCY_MISMATCH', false);
        }

        if ($condition !== null && \in_array(strtolower(trim($condition)), self::USED_CONDITIONS, true)) {
            return new MatchVerdict(MatchVerdict::REJECTED, 'USED_OR_REFURBISHED', false, 'used_or_refurbished', false);
        }

        return null;
    }

    /**
     * The gap weighed after the identity verdict:
     *
     * - beyond IMPLAUSIBLE_GAP, whatever Jev said short of "not this article"
     *   or "not this size" goes to a human;
     * - from SUSPECT_GAP on, a Jev match whose price would count in the gap
     *   goes to a human; a price already kept out (a promotion) cannot
     *   mislead the gap.
     *
     * A rejection keeps its own reason: it is the proof the gap only hinted.
     */
    public function withGap(MatchVerdict $verdict, ?string $ourPriceInclTax, ?string $theirPrice): MatchVerdict
    {
        $gap = self::gap($ourPriceInclTax, $theirPrice);

        if ($gap === null || $verdict->state === MatchVerdict::REJECTED) {
            return $verdict;
        }

        if ($gap > self::IMPLAUSIBLE_GAP) {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_IMPLAUSIBLE', false, 'PRICE_GAP_IMPLAUSIBLE', $verdict->judgedByJev);
        }

        if ($gap >= self::SUSPECT_GAP && $verdict->state === MatchVerdict::MATCHED && $verdict->comparable) {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_SUSPECT', false, 'PRICE_GAP_SUSPECT', $verdict->judgedByJev);
        }

        return $verdict;
    }

    /**
     * For a match proven by a GTIN or a human: the article is certain, only a
     * gap no shop would show makes the price itself doubtful.
     */
    public static function implausibleGap(?string $ourPriceInclTax, ?string $theirPrice): bool
    {
        $gap = self::gap($ourPriceInclTax, $theirPrice);

        return $gap !== null && $gap > self::IMPLAUSIBLE_GAP;
    }

    /**
     * Relative to our price; rounded to the basis point, so 75.00 against
     * 100.00 is 25 % and not a float a hair below it.
     */
    private static function gap(?string $ourPriceInclTax, ?string $theirPrice): ?float
    {
        $ours = (float) $ourPriceInclTax;
        $theirs = (float) $theirPrice;

        return $ours > 0.0 && $theirs > 0.0 ? round(abs($theirs - $ours) / $ours, 4) : null;
    }

    /**
     * @param array<string, JevAnswer> $answers keyed by OfferQuestions ids
     */
    public function decide(array $answers): MatchVerdict
    {
        $product = $answers[OfferQuestions::SAME_PRODUCT] ?? null;
        $variant = $answers[OfferQuestions::SAME_VARIANT] ?? null;
        $price = $answers[OfferQuestions::PRICE_COMPARABILITY] ?? null;

        if ($product === null || $variant === null || $price === null) {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'NOT_JUDGED', false, 'NOT_JUDGED', true);
        }

        // The product verdict comes first: a "same variant" about a different
        // article means nothing.
        if ($product->band === JevAnswer::NO) {
            return new MatchVerdict(MatchVerdict::REJECTED, 'PRODUCT_MISMATCH', false, null, true);
        }

        if ($product->band !== JevAnswer::YES) {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'PRODUCT_UNCLEAR', false, null, true);
        }

        if ($variant->band === JevAnswer::NO) {
            return new MatchVerdict(MatchVerdict::REJECTED, 'VARIANT_MISMATCH', false, null, true);
        }

        if ($variant->band !== JevAnswer::YES) {
            return new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'VARIANT_UNCLEAR', false, null, true);
        }

        // The price is what the seller asks today. Jev rarely has the evidence
        // to be sure of it (measured 2026-10-12: 19 answers out of 20 in the
        // middle band), so only a confident "not a plain price" excludes it.
        if ($price->band !== JevAnswer::ACT || \in_array($price->value, self::PLAIN_PRICE, true)) {
            return new MatchVerdict(MatchVerdict::MATCHED, 'JEV', true, null, true);
        }

        return new MatchVerdict(MatchVerdict::MATCHED, 'JEV', false, (string) $price->value, true);
    }
}
