<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

/**
 * The small price curve of a proposal line: our price and the cheapest
 * matched competitor, one point per reading day, drawn on one shared scale
 * so the gap between the two lines reads as it is.
 *
 * Only reading days carry a point: nothing is drawn between two readings
 * that was not read. The horizontal axis follows the calendar, so a gap of
 * days without a reading shows as a longer segment.
 */
final readonly class PriceSparkline
{
    public const WIDTH = 120;
    public const HEIGHT = 32;
    private const PADDING = 3;

    /**
     * The height stands for at least 10 % of the highest price, so a small
     * move draws a small slope.
     */
    private const MINIMUM_SPAN = 0.10;

    /**
     * @param list<array{x: float, y: float}> $ours
     * @param list<array{x: float, y: float}> $cheapest
     */
    private function __construct(
        public array $ours,
        public array $cheapest,
        public string $from,
        public string $to,
        public int $readings,
        public ?float $oursFirst,
        public ?float $oursLast,
        public ?float $cheapestFirst,
        public ?float $cheapestLast,
        public float $low,
        public float $high,
    ) {
    }

    /**
     * @param array<string, array{ours: ?float, cheapest: ?float}> $days by Y-m-d, any order
     */
    public static function of(array $days): ?self
    {
        $days = array_filter($days, static fn (array $day): bool => $day['ours'] !== null || $day['cheapest'] !== null);
        ksort($days);
        $oursPrices = self::known(array_column($days, 'ours'));
        $cheapestPrices = self::known(array_column($days, 'cheapest'));
        $allPrices = [...$oursPrices, ...$cheapestPrices];

        if ($allPrices === []) {
            return null;
        }

        $low = min($allPrices);
        $high = max($allPrices);
        // A 2 € move on 150 € would otherwise fill the whole height.
        $widening = max(0.0, $high * self::MINIMUM_SPAN - ($high - $low)) / 2;
        $bottom = $low - $widening;
        $top = $high + $widening;

        $first = new \DateTimeImmutable((string) array_key_first($days));
        $last = new \DateTimeImmutable((string) array_key_last($days));
        $span = (int) $first->diff($last)->days;

        $ours = [];
        $cheapest = [];

        foreach ($days as $day => $prices) {
            $x = self::x((int) $first->diff(new \DateTimeImmutable((string) $day))->days, $span);

            if ($prices['ours'] !== null) {
                $ours[] = ['x' => $x, 'y' => self::y($prices['ours'], $bottom, $top)];
            }

            if ($prices['cheapest'] !== null) {
                $cheapest[] = ['x' => $x, 'y' => self::y($prices['cheapest'], $bottom, $top)];
            }
        }

        return new self(
            $ours,
            $cheapest,
            $first->format('d/m/Y'),
            $last->format('d/m/Y'),
            \count($days),
            $oursPrices[0] ?? null,
            $oursPrices === [] ? null : $oursPrices[\count($oursPrices) - 1],
            $cheapestPrices[0] ?? null,
            $cheapestPrices === [] ? null : $cheapestPrices[\count($cheapestPrices) - 1],
            $low,
            $high,
        );
    }

    /**
     * The SVG points attribute of our price.
     */
    public function oursPolyline(): string
    {
        return self::polyline($this->ours);
    }

    public function cheapestPolyline(): string
    {
        return self::polyline($this->cheapest);
    }

    /**
     * @param list<array{x: float, y: float}> $points
     */
    private static function polyline(array $points): string
    {
        return implode(' ', array_map(static fn (array $point): string => \sprintf('%.1f,%.1f', $point['x'], $point['y']), $points));
    }

    /**
     * @param list<?float> $prices
     *
     * @return list<float>
     */
    private static function known(array $prices): array
    {
        return array_values(array_filter($prices, static fn (?float $price): bool => $price !== null));
    }

    /**
     * A single day sits in the middle.
     */
    private static function x(int $day, int $span): float
    {
        if ($span === 0) {
            return self::WIDTH / 2;
        }

        return self::PADDING + $day / $span * (self::WIDTH - 2 * self::PADDING);
    }

    /**
     * Higher price, higher on the curve; a flat price sits in the middle.
     */
    private static function y(float $price, float $bottom, float $top): float
    {
        if ($top - $bottom < 0.005) {
            return self::HEIGHT / 2;
        }

        return self::HEIGHT - self::PADDING - ($price - $bottom) / ($top - $bottom) * (self::HEIGHT - 2 * self::PADDING);
    }
}
