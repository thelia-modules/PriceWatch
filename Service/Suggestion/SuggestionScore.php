<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

/**
 * How worth collecting a product is, out of 100: what it sells, how much
 * dearer we were, how deep its promotion is, how old the reading is, whether
 * it is new, how much stock waits. Each part is capped at its weight.
 */
final readonly class SuggestionScore
{
    public const SALES = 35;
    public const DEARER = 25;
    public const PROMO = 20;
    public const STALE = 10;
    public const NEW = 5;
    public const STOCK = 5;

    /** Dearer by this much or more: the full weight. */
    public const DEARER_FULL_PCT = 20.0;

    /** Below this discount a promotion counts for nothing: most of the catalogue sits at 5 to 25 %. */
    public const PROMO_FROM_PCT = 10.0;
    public const PROMO_FULL_PCT = 40.0;

    public const STALE_FULL_DAYS = 60;

    public function __construct(
        public float $sales,
        public float $dearer,
        public float $promo,
        public float $stale,
        public float $new,
        public float $stock,
    ) {
    }

    /**
     * @param float      $revenueShare its revenue over the best seller's, 0 to 1
     * @param float|null $gapPct       the widest gap against us, negative: the competitor is cheaper
     * @param float|null $discountPct  its deepest promotion, 0 to 100
     * @param int|null   $staleDays    days since a competitor was last read, when that is a reason
     * @param float      $stockShare   its stock value over the largest one, 0 to 1
     */
    public static function of(float $revenueShare, ?float $gapPct, ?float $discountPct, ?int $staleDays, bool $isNew, float $stockShare): self
    {
        return new self(
            // Square root: a few products make most of the revenue, the
            // next ones should still be told apart.
            self::SALES * sqrt(self::share($revenueShare)),
            $gapPct === null ? 0.0 : self::DEARER * self::share(abs($gapPct) / self::DEARER_FULL_PCT),
            $discountPct === null ? 0.0 : self::PROMO * self::share(($discountPct - self::PROMO_FROM_PCT) / (self::PROMO_FULL_PCT - self::PROMO_FROM_PCT)),
            $staleDays === null ? 0.0 : self::STALE * self::share($staleDays / self::STALE_FULL_DAYS),
            $isNew ? (float) self::NEW : 0.0,
            self::STOCK * sqrt(self::share($stockShare)),
        );
    }

    public function total(): float
    {
        return array_sum($this->parts());
    }

    /**
     * @return array{sales: float, dearer: float, promo: float, stale: float, new: float, stock: float}
     */
    public function parts(): array
    {
        return [
            'sales' => $this->sales,
            'dearer' => $this->dearer,
            'promo' => $this->promo,
            'stale' => $this->stale,
            'new' => $this->new,
            'stock' => $this->stock,
        ];
    }

    private static function share(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
