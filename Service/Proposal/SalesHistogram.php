<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

/**
 * The small sales chart of a proposal line: units sold per calendar month
 * over the last 24 months, the current one included while it runs, so each
 * month stands next to the same month a year earlier.
 *
 * Every month gets its slot, a month without a sale stays empty: a gap in
 * sales shows as a gap.
 */
final readonly class SalesHistogram
{
    public const MONTHS = 24;
    public const SLOT = 5;
    public const BAR = 4;
    public const WIDTH = self::MONTHS * self::SLOT;
    public const HEIGHT = 32;

    /**
     * @param list<array{x: int, y: float, height: float, month: string, units: int, lastTwelve: bool}> $bars oldest first
     */
    private function __construct(
        public array $bars,
        public int $lastTwelveUnits,
        public int $twelveBeforeUnits,
    ) {
    }

    /**
     * First day of the oldest month drawn.
     */
    public static function since(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('first day of this month')->setTime(0, 0)->modify('-'.(self::MONTHS - 1).' months');
    }

    /**
     * @param array<string, int> $unitsByMonth by Y-m, months out of the window ignored
     */
    public static function of(array $unitsByMonth, \DateTimeImmutable $now): ?self
    {
        $month = self::since($now);
        $units = [];

        for ($slot = 0; $slot < self::MONTHS; ++$slot) {
            $units[$month->format('Y-m')] = max(0, $unitsByMonth[$month->format('Y-m')] ?? 0);
            $month = $month->modify('+1 month');
        }

        $peak = max($units);

        if ($peak === 0) {
            return null;
        }

        $bars = [];
        $slot = 0;

        foreach ($units as $key => $count) {
            // A single unit still shows next to a peak of a hundred.
            $height = $count === 0 ? 0.0 : max(1.0, (float) ($count / $peak * (self::HEIGHT - 2)));
            $bars[] = [
                'x' => $slot * self::SLOT,
                'y' => self::HEIGHT - $height,
                'height' => $height,
                'month' => (new \DateTimeImmutable($key.'-01'))->format('m/Y'),
                'units' => $count,
                'lastTwelve' => $slot >= self::MONTHS - 12,
            ];
            ++$slot;
        }

        $counts = array_values($units);

        return new self(
            $bars,
            array_sum(\array_slice($counts, -12)),
            array_sum(\array_slice($counts, 0, self::MONTHS - 12)),
        );
    }
}
