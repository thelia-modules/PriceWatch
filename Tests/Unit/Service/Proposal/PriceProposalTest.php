<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\PriceProposal;
use PriceWatch\Service\Proposal\ProposalKind;

/**
 * Prices with 20 % VAT: 120 including tax is 100 excluding tax.
 */
final class PriceProposalTest extends TestCase
{
    public function testDearerThanACompetitorAlignsOnItRoundedDownToTheEuro(): void
    {
        $proposal = PriceProposal::of(120.0, 100.0, 50.0, 110.49, false, 20.0);

        self::assertNotNull($proposal);
        self::assertSame(ProposalKind::Lower, $proposal->kind);
        self::assertSame(110.0, $proposal->proposedPrice);
        self::assertEqualsWithDelta(50.0, $proposal->marginNowPct, 1e-9, '(100 - 50) / 100');
        self::assertEqualsWithDelta((110 / 1.2 - 50) / (110 / 1.2) * 100, $proposal->marginProposedPct, 1e-9);
    }

    public function testSalesGoingDownMakeItTheUrgentKind(): void
    {
        self::assertSame(ProposalKind::LowerDeclining, PriceProposal::of(120.0, 100.0, 50.0, 110.0, true, 20.0)?->kind);
    }

    public function testCheaperOrEqualAsTheComparatorShowsItProposesNothing(): void
    {
        self::assertNull(PriceProposal::of(120.0, 100.0, 50.0, 125.0, true, 20.0), 'we are cheaper');
        self::assertNull(PriceProposal::of(120.0, 100.0, 50.0, 119.95, true, 20.0), 'a gap that shows as 0.0 %');
        self::assertNull(PriceProposal::of(120.0, 100.0, 50.0, null, true, 20.0), 'no competitor read');
    }

    public function testNeverBelowTheMarginFloor(): void
    {
        // Floor: 80 / (1 - 0.20) = 100 excl. tax, 120 incl. tax.
        $proposal = PriceProposal::of(130.0, 130 / 1.2, 80.0, 115.0, true, 20.0);

        self::assertNotNull($proposal);
        self::assertSame(ProposalKind::FloorBlocked, $proposal->kind);
        self::assertSame(120.0, $proposal->floorPrice);
        self::assertSame(120.0, $proposal->proposedPrice, 'as close as the floor lets us come');
        self::assertEqualsWithDelta(20.0, $proposal->marginProposedPct, 1e-9);
    }

    public function testAtTheFloorAlreadyThereIsNothingToLower(): void
    {
        $proposal = PriceProposal::of(120.0, 100.0, 80.0, 110.0, true, 20.0);

        self::assertSame(ProposalKind::FloorBlocked, $proposal?->kind);
        self::assertNull($proposal->proposedPrice);
    }

    public function testAPriceBelowTheFloorIsAnAlertWhateverTheCompetitorsDo(): void
    {
        $proposal = PriceProposal::of(110.0, 110 / 1.2, 80.0, null, false, 20.0);

        self::assertNotNull($proposal);
        self::assertSame(ProposalKind::MarginAlert, $proposal->kind);
        self::assertSame(120.0, $proposal->proposedPrice, 'back to the floor');
        self::assertEqualsWithDelta((110 / 1.2 - 80) / (110 / 1.2) * 100, $proposal->marginNowPct, 1e-9);
    }

    public function testWithoutPurchasePriceTheAlignmentHasNoFloorAndNoMargin(): void
    {
        $proposal = PriceProposal::of(120.0, 100.0, null, 60.0, false, 20.0);

        self::assertNotNull($proposal);
        self::assertSame(ProposalKind::Lower, $proposal->kind);
        self::assertSame(60.0, $proposal->proposedPrice);
        self::assertNull($proposal->floorPrice);
        self::assertNull($proposal->marginNowPct);
        self::assertNull($proposal->marginProposedPct);
    }

    public function testTheFloorIsRoundedUpToTheCentSoThatItKeepsTheMargin(): void
    {
        // 33.33 / 0.8 * 1.2 = 49.995
        self::assertSame(50.0, PriceProposal::floor(33.33, 1.2, 20.0));
        self::assertSame(120.0, PriceProposal::floor(80.0, 1.2, 20.0), 'an exact floor is not pushed up a cent by float noise');
    }
}
