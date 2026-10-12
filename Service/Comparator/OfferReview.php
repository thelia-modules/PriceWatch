<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwPriceQuoteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * What a human decides on an offer. A human decision is final for the
 * machine: matched_manual is never judged again, and goes back to a human
 * only if the competitor's product changes (see ShoppingCollector).
 */
final readonly class OfferReview
{
    public const HUMAN_MATCH = 'matched_manual';

    /**
     * Confirming the product does not make every price comparable: a price
     * in another currency is never converted, and a price Jev read as a
     * promotion, a bundle, a starting price or a second-hand item stays out of
     * the gap. Only "could not tell" exclusions are lifted.
     */
    private const EXCLUSIONS_A_HUMAN_LIFTS = [null, 'NOT_JUDGED', 'PRICE_UNCLEAR', 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT', 'unclear'];

    public function confirm(PwCompetitorOffer $offer, string $reviewer): void
    {
        $comparable = \in_array($offer->getExclusionReason(), self::EXCLUSIONS_A_HUMAN_LIFTS, true);

        $this->decide($offer, self::HUMAN_MATCH, 'CONFIRMED_BY_HUMAN', $comparable, $comparable ? null : $offer->getExclusionReason(), $reviewer);
    }

    public function reject(PwCompetitorOffer $offer, string $reviewer): void
    {
        $this->decide($offer, 'rejected', 'REJECTED_BY_HUMAN', false, $offer->getExclusionReason(), $reviewer);
    }

    /**
     * Undoes a decision, human or machine: the offer waits for a human again.
     */
    public function reopen(PwCompetitorOffer $offer, string $reviewer): void
    {
        $this->decide($offer, 'needs_review', 'REOPENED', false, $offer->getExclusionReason(), $reviewer);
    }

    private function decide(PwCompetitorOffer $offer, string $state, string $reason, bool $comparable, ?string $exclusion, string $reviewer): void
    {
        $offer
            ->setMatchState($state)
            ->setMatchMethod('human')
            ->setMatchReason($reason)
            // What was decided on: a later change of the remote product
            // shows as drift against this.
            ->setJudgedFingerprint($offer->getIdentityFingerprint())
            ->setLastComparable($comparable ? 1 : 0)
            ->setExclusionReason($exclusion)
            ->setReviewedBy(mb_substr($reviewer, 0, 100))
            ->setReviewedAt(new \DateTime())
            ->save();

        // The latest reading follows, so the history says what the screen says.
        PwPriceQuoteQuery::create()
            ->filterByOfferId($offer->getId())
            ->orderByCollectedAt(Criteria::DESC)
            ->findOne()
            ?->setComparable($comparable ? 1 : 0)
            ->setExclusionReason($exclusion)
            ->save();
    }
}
