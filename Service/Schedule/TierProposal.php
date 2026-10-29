<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

/**
 * The tier the back office proposes for each watched product, from its
 * revenue over the last 90 days among the watched ones: the top fifth
 * flagship, the next two fifths base, the rest simple. A product that sold
 * nothing is simple whatever its rank. Only a proposal: the merchant
 * validates it (asked 2026-10-20).
 */
final readonly class TierProposal
{
    public const SALES_DAYS = 90;

    public const FLAGSHIP_SHARE = 0.2;
    public const BASE_SHARE = 0.6;

    /**
     * @param list<int>         $productIds the watched products
     * @param array<int, float> $revenues   product id => revenue, for those that sold
     *
     * @return array<int, WatchTier> product id => proposed tier
     */
    public static function propose(array $productIds, array $revenues): array
    {
        $count = \count($productIds);
        $flagships = (int) ceil($count * self::FLAGSHIP_SHARE);
        $bases = (int) ceil($count * self::BASE_SHARE);
        $sellers = array_values(array_filter($productIds, static fn (int $productId): bool => ($revenues[$productId] ?? 0.0) > 0.0));

        usort($sellers, static fn (int $one, int $other): int => [$revenues[$other], $one] <=> [$revenues[$one], $other]);

        $proposals = array_fill_keys($productIds, WatchTier::Simple);

        foreach ($sellers as $rank => $productId) {
            $proposals[$productId] = match (true) {
                $rank < $flagships => WatchTier::Flagship,
                $rank < $bases => WatchTier::Base,
                default => WatchTier::Simple,
            };
        }

        return $proposals;
    }
}
