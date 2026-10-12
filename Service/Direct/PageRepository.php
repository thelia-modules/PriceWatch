<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorPage;
use PriceWatch\Model\PwCompetitorPageQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class PageRepository
{
    public const CANDIDATE = 'candidate';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';

    /**
     * Idempotent: the same page of the same competitor is one row.
     */
    public function add(int $competitorId, int $productId, string $url, string $discoveredBy): PwCompetitorPage
    {
        $url = PageValues::cleanUrl($url);
        $existing = PwCompetitorPageQuery::create()->filterByCompetitorId($competitorId)->filterByUrlKey(sha1($url))->findOne();

        if ($existing !== null) {
            return $existing;
        }

        $page = (new PwCompetitorPage())
            ->setCompetitorId($competitorId)
            ->setProductId($productId)
            ->setUrl($url)
            ->setUrlKey(sha1($url))
            ->setStatus(self::CANDIDATE)
            ->setDiscoveredBy($discoveredBy);
        $page->save();

        return $page;
    }

    public function setStatus(PwCompetitorPage $page, string $status, ?string $by): void
    {
        $page->setStatus($status)->setConfirmedBy($status === self::CONFIRMED ? $by : null)->save();
    }

    /**
     * Pages worth a visit: confirmed ones for their prices, candidates for the
     * proof that would confirm them. Only whitelisted competitors.
     *
     * @param list<string> $productRefs
     *
     * @return list<PwCompetitorPage>
     */
    public function toRead(array $productRefs = [], ?string $competitorDomain = null): array
    {
        $watched = PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value);

        if ($competitorDomain !== null) {
            $watched->filterByDomain($competitorDomain);
        }

        $competitorIds = $watched->select(['Id'])->find()->getData();

        if ($competitorIds === []) {
            return [];
        }

        $query = PwCompetitorPageQuery::create()
            ->filterByCompetitorId($competitorIds, Criteria::IN)
            ->filterByStatus([self::CANDIDATE, self::CONFIRMED], Criteria::IN)
            ->orderByLastFetchedAt(Criteria::ASC)
            ->orderById();

        if ($productRefs !== []) {
            $query->useProductQuery()->filterByRef($productRefs, Criteria::IN)->endUse();
        }

        return $query->find()->getData();
    }

    /**
     * The merchant pages Google Shopping already led to, kept on the offers:
     * candidates to prove, for free, before any other search.
     */
    public function seedFromShoppingOffers(): int
    {
        $watched = PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value)->select(['Id'])->find()->getData();

        if ($watched === []) {
            return 0;
        }

        $added = 0;

        foreach (PwCompetitorOfferQuery::create()->filterByCompetitorId($watched, Criteria::IN)->filterByUrl(null, Criteria::ISNOTNULL)->find() as $offer) {
            $url = (string) $offer->getUrl();
            $saleElement = ProductSaleElementsQuery::create()->findPk($offer->getProductSaleElementsId());

            if ($saleElement === null || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }

            $before = PwCompetitorPageQuery::create()->filterByCompetitorId($offer->getCompetitorId())->filterByUrlKey(sha1(PageValues::cleanUrl($url)))->count();
            $this->add((int) $offer->getCompetitorId(), (int) $saleElement->getProductId(), $url, 'shopping');
            $added += $before === 0 ? 1 : 0;
        }

        return $added;
    }
}
