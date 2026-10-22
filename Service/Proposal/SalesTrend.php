<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

/**
 * Units of one product sold over the last 90 days, against the same 90 days
 * a year earlier and against the 90 days before (asked 2026-10-20: both,
 * side by side). The year before comes first: the shop sells twice as much
 * from April to July as in winter, so against the previous 90 days nearly
 * everything looks down in the autumn.
 */
final readonly class SalesTrend
{
    public const DAYS = 90;

    /** Units to fall by, in percent, to count as a decline. */
    public const DECLINE_FROM_PCT = 20.0;

    /** Fewer units than this in the period compared with is too thin to say anything. */
    public const MIN_REFERENCE_UNITS = 2;

    public function __construct(
        public int $units,
        public int $unitsYearBefore,
        public int $unitsPeriodBefore,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    public function yearOnYearPct(): ?float
    {
        return self::change($this->units, $this->unitsYearBefore);
    }

    public function periodOnPeriodPct(): ?float
    {
        return self::change($this->units, $this->unitsPeriodBefore);
    }

    /**
     * Against the year before when that year sold enough to compare with,
     * otherwise against the 90 days before.
     */
    public function declining(): bool
    {
        $change = $this->yearOnYearPct() ?? $this->periodOnPeriodPct();

        return $change !== null && $change <= -self::DECLINE_FROM_PCT;
    }

    private static function change(int $units, int $reference): ?float
    {
        if ($reference < self::MIN_REFERENCE_UNITS) {
            return null;
        }

        return ($units - $reference) / $reference * 100;
    }
}
