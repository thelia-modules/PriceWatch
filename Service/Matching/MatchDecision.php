<?php

declare(strict_types=1);

namespace PriceWatch\Service\Matching;

use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\OfferQuestions;

/**
 * Where code decides. Two stages:
 *
 * 1. prefilter(): what code can see for itself, before paying for anything.
 *    A price gap no same-article offer would show, a second-hand item, a
 *    foreign currency. Jev is never asked about those.
 * 2. decide(): Jev's typed answers turned into a state, only in the decisive
 *    bands. Anything in between goes to a human; it is never a pass.
 */
final readonly class MatchDecision
{
    /** Beyond this gap, whatever the titles say, it is not the same article. */
    public const IMPLAUSIBLE_GAP = 0.70;

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

        $ours = (float) $ourPriceInclTax;
        $theirs = (float) $theirPrice;

        if ($ours > 0.0 && $theirs > 0.0 && abs($theirs - $ours) / $ours > self::IMPLAUSIBLE_GAP) {
            return new MatchVerdict(MatchVerdict::REJECTED, 'PRICE_GAP_IMPLAUSIBLE', false, 'PRICE_GAP_IMPLAUSIBLE', false);
        }

        return null;
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
