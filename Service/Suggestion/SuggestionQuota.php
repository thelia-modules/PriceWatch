<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

/**
 * How many lines of the suggestions list each top-level family gets.
 *
 * Asked 2026-10-12: ranked across the whole shop, helmets took 47 lines out
 * of 50 (71 % of the revenue, and dear). Each family now gets its share of
 * the revenue, and at least a minimum, so the best of every family shows.
 * A family short of candidates leaves its lines to the others.
 */
final class SuggestionQuota
{
    /**
     * @param array<int, float> $revenueByFamily    family id => revenue over the period; a family without sales counts for 0
     * @param array<int, int>   $candidatesByFamily family id => products that could fill its lines
     *
     * @return array<int, int> family id => lines, the best selling family first
     */
    public static function allocate(array $revenueByFamily, array $candidatesByFamily, int $slots, int $minimum): array
    {
        $revenue = static fn (int $family): float => max(0.0, $revenueByFamily[$family] ?? 0.0);
        $families = array_keys(array_filter($candidatesByFamily, static fn (int $count): bool => $count > 0));
        usort($families, static fn (int $a, int $b): int => [$revenue($b), $a] <=> [$revenue($a), $b]);

        // Not room for every minimum: the best selling families keep theirs.
        $kept = [];
        $room = $slots;

        foreach ($families as $family) {
            $floor = min($minimum, $candidatesByFamily[$family]);

            if ($floor > $room) {
                break;
            }

            $kept[] = $family;
            $room -= $floor;
        }

        // Families whose share falls under their minimum, or over their
        // candidates, are settled first; the rest share what is left.
        $settled = [];
        $free = $kept;

        do {
            $changed = false;
            $left = $slots - array_sum($settled);
            $shares = self::shares($free, $revenue, $left);

            foreach ($free as $family) {
                $floor = min($minimum, $candidatesByFamily[$family]);

                if ($shares[$family] < $floor || $shares[$family] > $candidatesByFamily[$family]) {
                    $settled[$family] = $shares[$family] < $floor ? $floor : $candidatesByFamily[$family];
                    $changed = true;
                }
            }

            $free = array_values(array_filter($free, static fn (int $family): bool => !isset($settled[$family])));
        } while ($changed && $free !== []);

        $left = $slots - array_sum($settled);
        $shares = self::shares($free, $revenue, $left);
        $lines = $settled;

        foreach ($free as $family) {
            $lines[$family] = (int) floor($shares[$family]);
        }

        // Largest remainder: the lines rounding left over go to the families
        // closest to one more, the best selling on a tie.
        $byRemainder = $free;
        usort($byRemainder, static fn (int $a, int $b): int => [$shares[$b] - floor($shares[$b]), $revenue($b)] <=> [$shares[$a] - floor($shares[$a]), $revenue($a)]);

        for ($spare = $left - array_sum(array_intersect_key($lines, array_flip($free))), $index = 0; $spare > 0 && $byRemainder !== [] && $index < \count($byRemainder); ++$index) {
            $family = $byRemainder[$index];

            if ($lines[$family] < $candidatesByFamily[$family]) {
                ++$lines[$family];
                --$spare;
            }
        }

        $ordered = [];

        foreach ($kept as $family) {
            if (($lines[$family] ?? 0) > 0) {
                $ordered[$family] = $lines[$family];
            }
        }

        return $ordered;
    }

    /**
     * @param list<int>               $families
     * @param \Closure(int): float    $revenue
     *
     * @return array<int, float> family id => lines in proportion to its revenue; equal shares when none sold
     */
    private static function shares(array $families, \Closure $revenue, int $slots): array
    {
        $total = array_sum(array_map($revenue, $families));
        $shares = [];

        foreach ($families as $family) {
            $shares[$family] = $total > 0.0 ? $slots * $revenue($family) / $total : $slots / \count($families);
        }

        return $shares;
    }
}
