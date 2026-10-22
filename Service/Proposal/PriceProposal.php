<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

use PriceWatch\Service\Comparator\PricePosition;

/**
 * What one price could become, from our price, the purchase price and the
 * cheapest competitor reading. Prices including tax are the ones shoppers
 * compare; the margin is reckoned excluding tax, on the selling price:
 * (price excl. tax - purchase) / price excl. tax.
 *
 * Asked 2026-10-20: align on the cheapest competitor, rounded down to the
 * euro, never below the floor where the margin falls under the minimum set
 * in the settings. Nothing is written to the catalogue: a proposal is read.
 */
final readonly class PriceProposal
{
    private function __construct(
        public ProposalKind $kind,
        public ?float $proposedPrice,
        public ?float $floorPrice,
        public ?float $marginNowPct,
        public ?float $marginProposedPct,
    ) {
    }

    /**
     * @param float      $ourPrice         including tax, promotion applied
     * @param float      $ourPriceExclTax  the same price excluding tax
     * @param float|null $purchasePrice    excluding tax, null when unknown
     * @param float|null $competitorPrice  the cheapest matched competitor, including tax
     * @param float      $minimumMarginPct the floor, in percent of the selling price excluding tax
     */
    public static function of(
        float $ourPrice,
        float $ourPriceExclTax,
        ?float $purchasePrice,
        ?float $competitorPrice,
        bool $salesDeclining,
        float $minimumMarginPct,
    ): ?self {
        if ($ourPrice <= 0.0 || $ourPriceExclTax <= 0.0) {
            return null;
        }

        $taxRatio = $ourPrice / $ourPriceExclTax;
        $floor = $purchasePrice === null ? null : self::floor($purchasePrice, $taxRatio, $minimumMarginPct);
        $margin = static fn (?float $price): ?float => $price === null || $purchasePrice === null ? null : self::marginPct($price / $taxRatio, $purchasePrice);
        $make = static fn (ProposalKind $kind, ?float $proposed): self => new self($kind, $proposed, $floor, $margin($ourPrice), $margin($proposed));

        if ($floor !== null && self::cents($ourPrice) < self::cents($floor)) {
            return $make(ProposalKind::MarginAlert, $floor);
        }

        if ($competitorPrice === null || $competitorPrice <= 0.0 || !self::dearer($ourPrice, $competitorPrice)) {
            return null;
        }

        $aligned = floor($competitorPrice);

        if ($floor !== null && self::cents($aligned) < self::cents($floor)) {
            return $make(ProposalKind::FloorBlocked, self::cents($floor) < self::cents($ourPrice) ? $floor : null);
        }

        return $make($salesDeclining ? ProposalKind::LowerDeclining : ProposalKind::Lower, $aligned);
    }

    /**
     * The lowest price including tax that keeps the minimum margin, rounded
     * up to the cent so that it does keep it.
     */
    public static function floor(float $purchasePrice, float $taxRatio, float $minimumMarginPct): float
    {
        return ceil(round($purchasePrice / (1 - $minimumMarginPct / 100) * $taxRatio * 100, 6)) / 100;
    }

    public static function marginPct(float $priceExclTax, float $purchasePrice): ?float
    {
        return $priceExclTax > 0.0 ? ($priceExclTax - $purchasePrice) / $priceExclTax * 100 : null;
    }

    /**
     * Dearer as the comparator colours it (PricePosition): a gap that shows
     * as 0.0 % is no gap.
     */
    private static function dearer(float $ourPrice, float $competitorPrice): bool
    {
        return PricePosition::fromGapPct(($competitorPrice - $ourPrice) / $ourPrice * 100) === PricePosition::WeAreDearer;
    }

    private static function cents(float $price): int
    {
        return (int) round($price * 100);
    }
}
