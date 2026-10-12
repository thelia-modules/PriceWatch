<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Matching;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Matching\MatchDecision;
use PriceWatch\Service\Matching\MatchVerdict;

final class MatchDecisionTest extends TestCase
{
    /**
     * One line per outcome. Product, variant: noul probabilities. Price:
     * [choice, confidence].
     *
     * @return iterable<string, array{float, float, array{string, float}, string, string, bool, ?string}>
     */
    public static function verdicts(): iterable
    {
        yield 'confirmed and comparable' => [0.94, 0.96, ['comparable', 0.90], MatchVerdict::MATCHED, 'JEV', true, null];
        yield 'confirmed, clearance price' => [0.95, 0.97, ['promotional', 0.98], MatchVerdict::MATCHED, 'JEV', false, 'promotional'];
        // Measured 2026-10-12: 19 price answers out of 20 fell in the middle
        // band, and excluding them left one comparable price out of four
        // matches. Unsure counts; only a confident "not a plain price" excludes.
        yield 'confirmed, Jev unsure about the price' => [0.94, 0.96, ['comparable', 0.54], MatchVerdict::MATCHED, 'JEV', true, null];
        yield 'confirmed, Jev unsure it is a promotion' => [0.94, 0.96, ['promotional', 0.50], MatchVerdict::MATCHED, 'JEV', true, null];
        yield 'confirmed, Jev sure the evidence is unclear' => [0.94, 0.96, ['unclear', 0.90], MatchVerdict::MATCHED, 'JEV', true, null];
        yield 'confirmed, a bundle' => [0.94, 0.96, ['bundle', 0.85], MatchVerdict::MATCHED, 'JEV', false, 'bundle'];
        yield 'confirmed, a starting price' => [0.94, 0.96, ['from_price', 0.85], MatchVerdict::MATCHED, 'JEV', false, 'from_price'];
        yield 'other size' => [0.93, 0.03, ['comparable', 0.90], MatchVerdict::REJECTED, 'VARIANT_MISMATCH', false, null];
        yield 'other article' => [0.02, 0.91, ['comparable', 0.90], MatchVerdict::REJECTED, 'PRODUCT_MISMATCH', false, null];
        yield 'product in the middle band' => [0.60, 0.96, ['comparable', 0.90], MatchVerdict::NEEDS_REVIEW, 'PRODUCT_UNCLEAR', false, null];
        yield 'variant in the middle band' => [0.92, 0.29, ['comparable', 0.90], MatchVerdict::NEEDS_REVIEW, 'VARIANT_UNCLEAR', false, null];
        yield 'product threshold is inclusive' => [0.80, 0.80, ['comparable', 0.80], MatchVerdict::MATCHED, 'JEV', true, null];
        yield 'reject threshold is inclusive' => [0.20, 0.96, ['comparable', 0.90], MatchVerdict::REJECTED, 'PRODUCT_MISMATCH', false, null];
    }

    /**
     * @param array{string, float} $price
     */
    #[DataProvider('verdicts')]
    public function testDecision(float $product, float $variant, array $price, string $state, string $reason, bool $comparable, ?string $exclusion): void
    {
        $verdict = (new MatchDecision())->decide([
            OfferQuestions::SAME_PRODUCT => $this->noul($product),
            OfferQuestions::SAME_VARIANT => $this->noul($variant),
            OfferQuestions::PRICE_COMPARABILITY => new JevAnswer('choice', $price[0], $price[1] >= 0.80 ? JevAnswer::ACT : JevAnswer::REVIEW, $price[1], []),
        ]);

        self::assertSame($state, $verdict->state);
        self::assertSame($reason, $verdict->reason);
        self::assertSame($comparable, $verdict->comparable);
        self::assertSame($exclusion, $verdict->exclusionReason);
    }

    public function testMissingAnswerIsNeverAPass(): void
    {
        $verdict = (new MatchDecision())->decide([OfferQuestions::SAME_PRODUCT => $this->noul(0.99)]);

        self::assertSame(MatchVerdict::NEEDS_REVIEW, $verdict->state);
        self::assertFalse($verdict->comparable);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, ?string, ?string}>
     */
    public static function prefilters(): iterable
    {
        // A far price is checked, never rejected blind (asked 2026-10-12):
        // see testAPriceFarFromOursIsCheckedNeverTrustedAlone.
        yield 'helmet at 419 seen at 89 goes to Jev' => ['419.00', '89.00', 'EUR', 'New', null];
        yield 'seen at triple the price goes to Jev' => ['100.00', '350.00', 'EUR', null, null];
        yield 'second-hand' => ['100.00', '95.00', 'EUR', 'Used', 'USED_OR_REFURBISHED'];
        yield 'refurbished' => ['100.00', '95.00', 'EUR', 'Refurbished', 'USED_OR_REFURBISHED'];
        yield 'foreign currency' => ['100.00', '95.00', 'GBP', 'New', 'CURRENCY_MISMATCH'];
        yield 'plausible offer goes to Jev' => ['100.00', '75.00', 'EUR', 'New', null];
        yield 'exactly at the limit goes to Jev' => ['100.00', '30.00', 'EUR', null, null];
    }

    #[DataProvider('prefilters')]
    public function testPrefilter(string $ours, ?string $theirs, ?string $currency, ?string $condition, ?string $expected): void
    {
        $verdict = (new MatchDecision())->prefilter($ours, $theirs, $currency, $condition);

        self::assertSame($expected, $verdict?->reason);
        if ($verdict !== null) {
            self::assertFalse($verdict->judgedByJev, 'code decided, nothing was paid for');
            self::assertFalse($verdict->comparable);
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string, bool}>
     */
    public static function gaps(): iterable
    {
        // Seen 2026-10-12: a Bell Moto 3 matched by Jev at +33 %, black
        // against matt black; the five offers beyond 70 % were other models.
        yield 'a Jev match at +33 % waits for a human' => [MatchVerdict::MATCHED, 'JEV', '224.99', '299.99', MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_SUSPECT', false];
        yield 'from 25 % on' => [MatchVerdict::MATCHED, 'JEV', '100.00', '75.00', MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_SUSPECT', false];
        yield 'below 25 % a Jev match stands' => [MatchVerdict::MATCHED, 'JEV', '100.00', '76.00', MatchVerdict::MATCHED, 'JEV', true];
        yield 'beyond 70 % a Jev match waits for a human' => [MatchVerdict::MATCHED, 'JEV', '69.90', '211.38', MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_IMPLAUSIBLE', false];
        yield 'beyond 70 % a doubt waits for a human' => [MatchVerdict::NEEDS_REVIEW, 'PRODUCT_UNCLEAR', '69.90', '211.38', MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_IMPLAUSIBLE', false];
        yield 'another product stays rejected, with its reason' => [MatchVerdict::REJECTED, 'PRODUCT_MISMATCH', '161.30', '329.99', MatchVerdict::REJECTED, 'PRODUCT_MISMATCH', false];
        yield 'a doubt under 70 % keeps its reason' => [MatchVerdict::NEEDS_REVIEW, 'PRODUCT_UNCLEAR', '129.99', '76.49', MatchVerdict::NEEDS_REVIEW, 'PRODUCT_UNCLEAR', false];
    }

    #[DataProvider('gaps')]
    public function testAPriceFarFromOursIsCheckedNeverTrustedAlone(string $state, string $reason, string $ours, string $theirs, string $expectedState, string $expectedReason, bool $comparable): void
    {
        $verdict = (new MatchDecision())->withGap(new MatchVerdict($state, $reason, $state === MatchVerdict::MATCHED, null, true), $ours, $theirs);

        self::assertSame($expectedState, $verdict->state);
        self::assertSame($expectedReason, $verdict->reason);
        self::assertSame($comparable, $verdict->comparable);
    }

    public function testAPromotionExplainsItsGapAndStaysOutOfIt(): void
    {
        $promotion = new MatchVerdict(MatchVerdict::MATCHED, 'JEV', false, 'promotional', true);

        self::assertSame($promotion, (new MatchDecision())->withGap($promotion, '22.00', '9.90'), 'kept out of the gap already: nothing to mislead');
    }

    public function testOnlyAnImplausibleGapIsFlaggedForAProvenMatch(): void
    {
        self::assertTrue(MatchDecision::implausibleGap('100.00', '171.00'));
        self::assertFalse(MatchDecision::implausibleGap('100.00', '170.00'));
        self::assertFalse(MatchDecision::implausibleGap('100.00', '140.00'), 'a GTIN match at +40 % is a price, not a doubt');
    }

    private function noul(float $value): JevAnswer
    {
        $band = $value >= 0.80 ? JevAnswer::YES : ($value <= 0.20 ? JevAnswer::NO : JevAnswer::REVIEW);

        return new JevAnswer('noul', $value, $band, null, []);
    }
}
