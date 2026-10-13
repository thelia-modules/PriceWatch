<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\Map\PwCompetitorOfferTableMap;
use PriceWatch\Model\Map\PwPriceQuoteTableMap;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;
use Thelia\Model\Map\ProductSaleElementsTableMap;
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

        // The gaps order matches idx_pw_offer_screen, read forwards or
        // backwards: no sort pass. The id tie-break is the primary key,
        // implicit in an InnoDB secondary index, and goes the same way.
        $direction = $filters->gapDirection();

        match (true) {
            $direction !== null => $query->orderByLastDeltaPct($direction)->orderById($direction),
            $filters->view === ComparatorView::Excluded => $query->orderByLastSeenAt(Criteria::DESC)->orderById(),
            default => $query->orderById(),
        };

        return $query->offset($filters->offset())->limit(ComparatorFilters::PAGE_SIZE);
    }

    /**
     * Every tab lists products, each with all its competitors (asked
     * 2026-10-12 for the gaps, 2026-10-13 for the others): paged by
     * product, so a product never spans two pages.
     * A product ranks by its most telling gap in the asked direction: the
     * cheapest competitor first, or the dearest when reversed.
     *
     * @return list<int> product ids of the page, in order
     */
    public function productPage(ComparatorFilters $filters): array
    {
        $query = $this->productQuery($filters);

        if ($query === null) {
            return [];
        }

        $direction = $filters->gapDirection() ?? 'ASC';
        $headline = ($direction === 'ASC' ? 'MIN(' : 'MAX(').PwCompetitorOfferTableMap::COL_LAST_DELTA_PCT.')';

        // Both computed columns come back, the sort one included.
        return array_map('intval', array_column($query
            ->withColumn(ProductSaleElementsTableMap::COL_PRODUCT_ID, 'productId')
            ->withColumn($headline, 'headlineGap')
            ->groupBy('productId')
            ->orderBy('headlineGap', $direction)
            ->orderBy('productId', $direction)
            ->select(['productId'])
            ->offset($filters->offset())
            ->limit(ComparatorFilters::PAGE_SIZE)
            ->find()
            ->getData(), 'productId'));
    }

    public function productCount(ComparatorFilters $filters): int
    {
        $count = $this->productQuery($filters)
            ?->withColumn('COUNT(DISTINCT '.ProductSaleElementsTableMap::COL_PRODUCT_ID.')', 'products')
            ->select(['products'])
            ->findOne();

        return (int) $count;
    }

    /**
     * @param list<int> $productIds
     *
     * @return list<PwCompetitorOffer> the tab's offers on these products, in
     *                                 the order of the gap sort
     */
    public function offersOfProducts(ComparatorFilters $filters, array $productIds): array
    {
        $query = $this->productQuery($filters);

        if ($query === null || $productIds === []) {
            return [];
        }

        $direction = $filters->gapDirection() ?? 'ASC';

        return $query
            ->where(ProductSaleElementsTableMap::COL_PRODUCT_ID.' IN ?', $productIds)
            ->orderByLastDeltaPct($direction)
            ->orderById($direction)
            ->find()
            ->getData();
    }

    /**
     * The tab's offers, joined to their variant for the product id.
     */
    private function productQuery(ComparatorFilters $filters): ?PwCompetitorOfferQuery
    {
        return $this->query($filters, $filters->view)
            ?->useProductSaleElementsQuery()
            ->endUse();
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
     * Counted with the screen's own notion of equal (PricePosition), so the
     * tiles agree with the colours of the rows.
     *
     * @return array{weAreCheaper: int, weAreDearer: int, averagePct: ?float}
     */
    public function gapSummary(ComparatorFilters $filters): array
    {
        $query = $this->query($filters, ComparatorView::Gaps);

        if ($query === null) {
            return ['weAreCheaper' => 0, 'weAreDearer' => 0, 'averagePct' => null];
        }

        $gap = PwCompetitorOfferTableMap::COL_LAST_DELTA_PCT;
        $row = $query
            ->withColumn('SUM('.$gap.' >= '.PricePosition::EQUAL_BELOW.')', 'weAreCheaper')
            ->withColumn('SUM('.$gap.' <= -'.PricePosition::EQUAL_BELOW.')', 'weAreDearer')
            ->withColumn('AVG('.$gap.')', 'averagePct')
            ->select(['weAreCheaper', 'weAreDearer', 'averagePct'])
            ->findOne();

        return [
            'weAreCheaper' => (int) ($row['weAreCheaper'] ?? 0),
            'weAreDearer' => (int) ($row['weAreDearer'] ?? 0),
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

        // One reading per offer and per day: the history grows every day, so
        // only the last reading of each offer is loaded, not all of them.
        $lastIds = PwPriceQuoteQuery::create()
            ->filterByOfferId($offerIds, Criteria::IN)
            ->groupByOfferId()
            ->withColumn('MAX('.PwPriceQuoteTableMap::COL_ID.')', 'lastId')
            ->select(['lastId'])
            ->find()
            ->getData();

        $latest = [];

        foreach (PwPriceQuoteQuery::create()->filterById(array_map('intval', $lastIds), Criteria::IN)->find() as $quote) {
            $latest[(int) $quote->getOfferId()] = $quote;
        }

        return $latest;
    }

    /**
     * A picture of each product, taken from the Google Shopping card of any
     * offer on it: the variants of one product share their card, and an
     * offer read before pictures were kept borrows its sibling's.
     *
     * @param list<int> $productIds
     *
     * @return array<int, string> product id => picture URL (http(s) only)
     */
    public function cardImages(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $productIds));
        $statement = Propel::getConnection('TheliaMain')->prepare(
            'SELECT pse.product_id, q.raw_payload_json FROM pw_price_quote q'
            .' JOIN (SELECT MAX(q2.id) AS id FROM pw_price_quote q2'
            .' JOIN pw_competitor_offer o2 ON o2.id = q2.offer_id'
            .' JOIN product_sale_elements pse2 ON pse2.id = o2.product_sale_elements_id'
            .' WHERE pse2.product_id IN ('.$ids.') AND q2.raw_payload_json LIKE :image'
            .' GROUP BY pse2.product_id) last ON last.id = q.id'
            .' JOIN pw_competitor_offer o ON o.id = q.offer_id'
            .' JOIN product_sale_elements pse ON pse.id = o.product_sale_elements_id',
        );
        $statement->execute(['image' => '%"image":"http%']);
        $images = [];

        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row['raw_payload_json'], true);
            $url = ComparatorPresenter::safeUrl(\is_string($payload['card']['image'] ?? null) ? $payload['card']['image'] : null);

            if ($url !== null) {
                $images[(int) $row['product_id']] = $url;
            }
        }

        return $images;
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
     * The other offers of a line of the screen, among the ids the form sent:
     * same competitor, same product, same prices, same state. An id that
     * does not share all of it is ignored, never decided on.
     *
     * @param list<int> $ids
     *
     * @return list<PwCompetitorOffer>
     */
    public function sameLine(PwCompetitorOffer $offer, array $ids): array
    {
        $productId = ProductSaleElementsQuery::create()->findPk($offer->getProductSaleElementsId())?->getProductId();
        $ids = array_values(array_diff(array_filter($ids, static fn (int $id): bool => $id > 0), [(int) $offer->getId()]));

        if ($productId === null || $ids === []) {
            return [];
        }

        return PwCompetitorOfferQuery::create()
            ->filterById($ids, Criteria::IN)
            ->filterByCompetitorId($offer->getCompetitorId())
            ->filterByMatchState($offer->getMatchState())
            ->filterByLastPrice($offer->getLastPrice())
            ->filterByLastOurPrice($offer->getLastOurPrice())
            ->useProductSaleElementsQuery()->filterByProductId($productId)->endUse()
            ->find()
            ->getData();
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
            // Equalities first, as idx_pw_offer_screen wants them.
            ComparatorView::Gaps => $query->filterByLastComparable(1)->filterByMatchState(ComparatorView::GAP_STATES, Criteria::IN),
            ComparatorView::Review => $query->filterByMatchState('needs_review')->filterByLastComparable(0),
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
            // Competitor cheaper, that is: we are dearer.
            $query->filterByLastDeltaPct(-PricePosition::EQUAL_BELOW, Criteria::LESS_EQUAL);
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
