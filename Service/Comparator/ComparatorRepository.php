<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\Map\PwCompetitorOfferTableMap;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The comparator screen never reads the price history to list offers: the
 * last_* columns of the offer carry the latest reading, and the gaps tab is
 * served by idx_pw_offer_screen (active, last_comparable, last_delta_pct),
 * filter and sort alike.
 */
final readonly class ComparatorRepository
{
    /** A search matching more variants than this is too vague to be useful. */
    private const MAX_SEARCHED_VARIANTS = 2000;

    /**
     * @return list<PwCompetitorOffer>
     */
    public function page(ComparatorFilters $filters): array
    {
        return $this->pageQuery($filters)?->find()->getData() ?? [];
    }

    /**
     * Public so the query plan of the screen can be checked by a test
     * (EXPLAIN): what is checked is the query that runs.
     */
    public function pageQuery(ComparatorFilters $filters): ?PwCompetitorOfferQuery
    {
        $query = $this->query($filters, $filters->view);

        if ($query === null) {
            return null;
        }

        // The gaps order matches idx_pw_offer_screen: no sort pass. The id
        // tie-break is the primary key, implicit in an InnoDB secondary index.
        match ($filters->view) {
            ComparatorView::Gaps => $query->orderByLastDeltaPct(Criteria::ASC)->orderById(),
            ComparatorView::Review, ComparatorView::Pending => $query->orderById(),
            ComparatorView::Excluded => $query->orderByLastSeenAt(Criteria::DESC)->orderById(),
        };

        return $query->offset($filters->offset())->limit(ComparatorFilters::PAGE_SIZE);
    }

    /**
     * @return array<string, int> view value => number of offers, same filters
     */
    public function counts(ComparatorFilters $filters): array
    {
        $counts = [];

        foreach (ComparatorView::cases() as $view) {
            $counts[$view->value] = $this->query($filters, $view)?->count() ?? 0;
        }

        return $counts;
    }

    /**
     * @return array{cheaper: int, dearer: int, averagePct: ?float}
     */
    public function gapSummary(ComparatorFilters $filters): array
    {
        $query = $this->query($filters, ComparatorView::Gaps);

        if ($query === null) {
            return ['cheaper' => 0, 'dearer' => 0, 'averagePct' => null];
        }

        $row = $query
            ->withColumn('SUM('.PwCompetitorOfferTableMap::COL_LAST_DELTA_PCT.' < 0)', 'cheaper')
            ->withColumn('SUM('.PwCompetitorOfferTableMap::COL_LAST_DELTA_PCT.' > 0)', 'dearer')
            ->withColumn('AVG('.PwCompetitorOfferTableMap::COL_LAST_DELTA_PCT.')', 'averagePct')
            ->select(['cheaper', 'dearer', 'averagePct'])
            ->findOne();

        return [
            'cheaper' => (int) ($row['cheaper'] ?? 0),
            'dearer' => (int) ($row['dearer'] ?? 0),
            'averagePct' => isset($row['averagePct']) ? (float) $row['averagePct'] : null,
        ];
    }

    /**
     * @return array<int, string> competitor id => domain, whitelisted or paused
     */
    public function competitors(): array
    {
        $competitors = [];

        foreach (PwCompetitorQuery::create()
            ->filterByStatus([CompetitorStatus::Watched->value, CompetitorStatus::Paused->value], Criteria::IN)
            ->orderByDomain()
            ->find() as $competitor) {
            $competitors[(int) $competitor->getId()] = (string) $competitor->getDomain();
        }

        return $competitors;
    }

    /**
     * @param list<int> $offerIds
     *
     * @return array<int, PwPriceQuote> offer id => its latest reading
     */
    public function latestQuotes(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        $latest = [];

        foreach (PwPriceQuoteQuery::create()->filterByOfferId($offerIds, Criteria::IN)->orderByCollectedAt(Criteria::ASC)->find() as $quote) {
            $latest[(int) $quote->getOfferId()] = $quote;
        }

        return $latest;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, array{title: string, ref: string}>
     */
    public function products(array $productIds, string $locale): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = [];

        foreach (ProductQuery::create()->filterById($productIds, Criteria::IN)->find() as $product) {
            $products[(int) $product->getId()] = ['title' => '', 'ref' => (string) $product->getRef()];
        }

        // The interface locale first, French as the catalogue's own language.
        foreach ([$locale, 'fr_FR'] as $candidate) {
            foreach (ProductI18nQuery::create()->filterById($productIds, Criteria::IN)->filterByLocale($candidate)->find() as $i18n) {
                $id = (int) $i18n->getId();

                if (isset($products[$id]) && $products[$id]['title'] === '' && (string) $i18n->getTitle() !== '') {
                    $products[$id]['title'] = (string) $i18n->getTitle();
                }
            }
        }

        return $products;
    }

    /**
     * @param list<int> $saleElementIds
     *
     * @return array<int, array{productId: int, size: ?string, pseRef: ?string}> sale element id => identity
     */
    public function identities(array $saleElementIds): array
    {
        if ($saleElementIds === []) {
            return [];
        }

        $identities = [];

        foreach (PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId($saleElementIds, Criteria::IN)
            ->joinWithProductSaleElements()
            ->find() as $identity) {
            $identities[(int) $identity->getProductSaleElementsId()] = [
                'productId' => (int) $identity->getProductId(),
                'size' => $identity->getSizeLabel(),
                'pseRef' => $identity->getProductSaleElements()?->getRef(),
            ];
        }

        return $identities;
    }

    /**
     * @return list<PwProductIdentity> the product's variants in the referential
     */
    public function identitiesOfProduct(int $productId): array
    {
        return PwProductIdentityQuery::create()
            ->filterByProductId($productId)
            ->joinWithProductSaleElements()
            ->orderByProductSaleElementsId()
            ->find()
            ->getData();
    }

    /**
     * @param list<int> $saleElementIds
     *
     * @return list<PwCompetitorOffer> active offers of whitelisted competitors
     */
    public function offersOfVariants(array $saleElementIds): array
    {
        $competitors = array_keys($this->competitors());

        if ($saleElementIds === [] || $competitors === []) {
            return [];
        }

        return PwCompetitorOfferQuery::create()
            ->filterByProductSaleElementsId($saleElementIds, Criteria::IN)
            ->filterByCompetitorId($competitors, Criteria::IN)
            ->filterByActive(1)
            ->orderByLastDeltaPct()
            ->find()
            ->getData();
    }

    public function productRef(int $productId): ?string
    {
        return ProductQuery::create()->findPk($productId)?->getRef();
    }

    public function find(int $offerId): ?PwCompetitorOffer
    {
        return PwCompetitorOfferQuery::create()->findPk($offerId);
    }

    /**
     * Null when the filters cannot match anything: no whitelisted competitor,
     * or a search that finds no product.
     */
    private function query(ComparatorFilters $filters, ComparatorView $view): ?PwCompetitorOfferQuery
    {
        $competitors = array_keys($this->competitors());

        if ($filters->competitorId !== null) {
            $competitors = \in_array($filters->competitorId, $competitors, true) ? [$filters->competitorId] : [];
        }

        if ($competitors === []) {
            return null;
        }

        $query = PwCompetitorOfferQuery::create()->filterByActive(1);

        match ($view) {
            ComparatorView::Gaps => $query->filterByLastComparable(1)->filterByMatchState(ComparatorView::MATCHED_STATES, Criteria::IN),
            ComparatorView::Review => $query->filterByMatchState('needs_review'),
            ComparatorView::Pending => $query->filterByMatchState('candidate'),
            ComparatorView::Excluded => $query
                ->condition('rejected', PwCompetitorOfferTableMap::COL_MATCH_STATE.' = ?', 'rejected')
                ->condition('matched', PwCompetitorOfferTableMap::COL_MATCH_STATE." IN ('matched_exact', 'matched_judged', 'matched_manual')")
                ->condition('notComparable', PwCompetitorOfferTableMap::COL_LAST_COMPARABLE.' = ?', 0)
                ->combine(['matched', 'notComparable'], Criteria::LOGICAL_AND, 'matchedNotComparable')
                ->where(['rejected', 'matchedNotComparable'], Criteria::LOGICAL_OR),
        };

        $query->filterByCompetitorId($competitors, Criteria::IN);

        if ($filters->cheaperOnly) {
            $query->filterByLastDeltaPct(0, Criteria::LESS_THAN);
        }

        if ($filters->search !== '') {
            $variants = $this->searchedVariants($filters->search);

            if ($variants === []) {
                return null;
            }

            $query->filterByProductSaleElementsId($variants, Criteria::IN);
        }

        return $query;
    }

    /**
     * @return list<int> sale element ids of products whose ref or title
     *                   contains the search
     */
    private function searchedVariants(string $search): array
    {
        $like = '%'.addcslashes($search, '%_\\').'%';

        $productIds = array_values(array_unique(array_map('intval', [
            ...ProductQuery::create()->filterByRef($like, Criteria::LIKE)->select(['Id'])->limit(500)->find()->getData(),
            ...ProductI18nQuery::create()->filterByTitle($like, Criteria::LIKE)->select(['Id'])->limit(500)->find()->getData(),
            ...ProductSaleElementsQuery::create()->filterByRef($like, Criteria::LIKE)->select(['ProductId'])->limit(500)->find()->getData(),
        ])));

        if ($productIds === []) {
            return [];
        }

        return array_map('intval', ProductSaleElementsQuery::create()
            ->filterByProductId($productIds, Criteria::IN)
            ->select(['Id'])
            ->limit(self::MAX_SEARCHED_VARIANTS)
            ->find()
            ->getData());
    }
}
