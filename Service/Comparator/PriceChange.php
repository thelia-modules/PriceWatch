<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwPriceQuoteQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * How a competitor's price moved since the reading of an earlier day
 * (asked 2026-10-13): kept on the offer when it is read, so the screens,
 * their filter and the admin home read it without walking the history.
 *
 * The earlier reading is the pair's, whatever the source: a pair read
 * through Google then straight on the competitor's page keeps one history.
 * Only the readings of an earlier day count: a second pass the same day
 * compares with yesterday, not with this morning.
 */
final class PriceChange
{
    /** Below this, a move is noise (rounding, a few cents): not shown. */
    public const SHOWN_FROM_PCT = 1.0;

    /**
     * Sets the previous price, its day and the change on the offer, from the
     * history; the caller saves. The offer's last price and last seen date
     * must already be today's.
     */
    public static function apply(PwCompetitorOffer $offer): void
    {
        $today = $offer->getLastSeenAt() ?? new \DateTime();
        $previous = PwPriceQuoteQuery::create()
            ->usePwCompetitorOfferQuery()
                ->filterByCompetitorId($offer->getCompetitorId())
                ->filterByProductSaleElementsId($offer->getProductSaleElementsId())
            ->endUse()
            ->filterByPrice(null, Criteria::ISNOTNULL)
            ->filterByCollectedDay($today->format('Y-m-d'), Criteria::LESS_THAN)
            ->orderByCollectedDay(Criteria::DESC)
            ->orderByCollectedAt(Criteria::DESC)
            ->findOne();

        if ($previous === null) {
            $offer->setPreviousPrice(null)->setPreviousPriceDay(null)->setPriceChangePct(null);

            return;
        }

        $offer
            ->setPreviousPrice($previous->getPrice())
            ->setPreviousPriceDay($previous->getCollectedDay())
            ->setPriceChangePct(self::pct($previous->getPrice(), $offer->getLastPrice()));
    }

    public static function pct(?string $previous, ?string $current): ?string
    {
        if ($previous === null || $current === null || (float) $previous <= 0.0) {
            return null;
        }

        return number_format(((float) $current - (float) $previous) / (float) $previous * 100, 3, '.', '');
    }

    public static function shown(int|float|string|null $pct): bool
    {
        return $pct !== null && abs((float) $pct) >= self::SHOWN_FROM_PCT;
    }
}
