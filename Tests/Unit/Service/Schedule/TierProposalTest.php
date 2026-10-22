<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Schedule;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Schedule\TierProposal;
use PriceWatch\Service\Schedule\WatchTier;

final class TierProposalTest extends TestCase
{
    public function testTheTopFifthIsFlagshipTheNextTwoFifthsBaseTheRestSimple(): void
    {
        $productIds = range(1, 10);
        $revenues = [1 => 100.0, 2 => 900.0, 3 => 500.0, 4 => 50.0, 5 => 700.0, 6 => 300.0, 7 => 10.0, 8 => 20.0, 9 => 30.0, 10 => 40.0];

        $proposals = TierProposal::propose($productIds, $revenues);
        $byTier = static fn (WatchTier $wanted): array => array_keys(array_filter($proposals, static fn (WatchTier $tier): bool => $tier === $wanted));

        self::assertSame([2, 5], $byTier(WatchTier::Flagship), 'the two best of ten');
        self::assertSame([1, 3, 4, 6], $byTier(WatchTier::Base), 'ranks three to six');
        self::assertSame([7, 8, 9, 10], $byTier(WatchTier::Simple), 'seventh of ten and after: beyond three fifths');
    }

    public function testAProductThatSoldNothingIsSimpleWhateverItsRank(): void
    {
        $proposals = TierProposal::propose([1, 2, 3], [1 => 80.0]);

        self::assertSame([1 => WatchTier::Flagship, 2 => WatchTier::Simple, 3 => WatchTier::Simple], $proposals);
    }

    public function testNoWatchedProductNoProposal(): void
    {
        self::assertSame([], TierProposal::propose([], [4 => 10.0]));
    }
}
