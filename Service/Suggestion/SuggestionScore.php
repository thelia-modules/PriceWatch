<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

/**
 * How worth adding to the watch a product is, out of 100: what it sells, how
 * deep its promotion is, whether it is new, how much stock waits. Each part
 * is capped at its weight. The weights keep the proportions they had before
 * the watched products, and with them the dearer and old reading parts, left
 * the suggestions (2026-10-20).
 */
final readonly class SuggestionScore
{
    public const SALES = 54;
    public const PROMO = 30;
    public const NEW = 8;
    public const STOCK = 8;

    /** Below this discount a promotion counts for nothing: most of the catalogue sits at 5 to 25 %. */
    public const PROMO_FROM_PCT = 10.0;
    public const PROMO_FULL_PCT = 40.0;

    public function __construct(
        public float $sales,
        public float $promo,
        public float $new,
        public float $stock,
    ) {
    }

    /**
     * @param float      $revenueShare its revenue over the best seller's, 0 to 1
     * @param float|null $discountPct  its deepest promotion, 0 to 100
     * @param float      $stockShare   its stock value over the largest one, 0 to 1
     */
    public static function of(float $revenueShare, ?float $discountPct, bool $isNew, float $stockShare): self
    {
        return new self(
            // Square root: a few products make most of the revenue, the
            // next ones should still be told apart.
            self::SALES * sqrt(self::share($revenueShare)),
            $discountPct === null ? 0.0 : self::PROMO * self::share(($discountPct - self::PROMO_FROM_PCT) / (self::PROMO_FULL_PCT - self::PROMO_FROM_PCT)),
            $isNew ? (float) self::NEW : 0.0,
            self::STOCK * sqrt(self::share($stockShare)),
        );
    }

    public function total(): float
    {
        return array_sum($this->parts());
    }

    /**
     * @return array{sales: float, promo: float, new: float, stock: float}
     */
    public function parts(): array
    {
        return [
            'sales' => $this->sales,
            'promo' => $this->promo,
            'new' => $this->new,
            'stock' => $this->stock,
        ];
    }

    private static function share(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
