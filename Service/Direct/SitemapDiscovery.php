<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Model\PwCompetitorPageQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Run\PriceWatchLog;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Thelia\Model\ProductI18nQuery;

/**
 * Finds the pages of our products in the watched competitors' sitemaps, for
 * free, before anyone pays Google for them (asked 2026-10-13: sitemap first,
 * DataForSEO as a last resort).
 *
 * A product that already has a confirmed or candidate page at a competitor is
 * left alone there. The pages found are candidates: the direct pass proves
 * them by a GTIN of ours or puts them to Jev.
 */
final readonly class SitemapDiscovery
{
    public const BY = 'sitemap';
    private const LOCALE = 'fr_FR';

    private LoggerInterface $log;

    public function __construct(
        private SitemapIndex $index,
        private SitemapMatcher $matcher,
        private PageRepository $pages,
        ?PriceWatchLog $log = null,
    ) {
        $this->log = $log ?? new NullLogger();
    }

    /**
     * @param list<string> $productRefs
     *
     * @return array{competitors: int, searched: int, added: int}
     */
    public function discover(array $productRefs, ?string $competitorDomain, PageFetcher $fetcher): array
    {
        $report = ['competitors' => 0, 'searched' => 0, 'added' => 0];
        $products = $this->products($productRefs);

        if ($products === []) {
            return $report;
        }

        $competitors = PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value);

        if ($competitorDomain !== null) {
            $competitors->filterByDomain($competitorDomain);
        }

        foreach ($competitors->find() as $competitor) {
            $known = array_flip(array_map('intval', PwCompetitorPageQuery::create()
                ->filterByCompetitorId($competitor->getId())
                ->filterByStatus([PageRepository::CANDIDATE, PageRepository::CONFIRMED], Criteria::IN)
                ->select(['ProductId'])
                ->find()
                ->getData()));
            $pending = array_diff_key($products, $known);

            if ($pending === []) {
                continue;
            }

            $urls = $this->index->urls((string) $competitor->getDomain(), $fetcher);
            ++$report['competitors'];

            foreach ($pending as $productId => $product) {
                ++$report['searched'];

                foreach ($this->matcher->match($product['title'], $product['brand'], $urls) as $url) {
                    // A page rejected once stays rejected: never added again.
                    $known = PwCompetitorPageQuery::create()->filterByCompetitorId($competitor->getId())->filterByUrlKey(sha1(PageValues::cleanUrl($url)))->count() > 0;
                    $this->pages->add((int) $competitor->getId(), $productId, $url, self::BY);
                    $report['added'] += $known ? 0 : 1;
                }
            }

            $this->log->info('Direct: sitemap of {domain}, {urls} address(es), searched for {products} product(s).', ['domain' => $competitor->getDomain(), 'urls' => \count($urls), 'products' => \count($pending)]);
        }

        return $report;
    }

    /**
     * @param list<string> $productRefs
     *
     * @return array<int, array{title: string, brand: ?string}> the watched products, by id
     */
    private function products(array $productRefs): array
    {
        $query = PwProductIdentityQuery::create()->filterByMonitored(1);

        if ($productRefs !== []) {
            $query->useProductSaleElementsQuery()->useProductQuery()->filterByRef($productRefs, Criteria::IN)->endUse()->endUse();
        }

        $products = [];

        foreach ($query->find() as $identity) {
            $productId = (int) $identity->getProductId();
            $products[$productId] ??= ['title' => '', 'brand' => null];
            $products[$productId]['brand'] ??= $identity->getBrandLabel();
        }

        if ($products === []) {
            return [];
        }

        foreach (ProductI18nQuery::create()->filterById(array_keys($products), Criteria::IN)->filterByLocale(self::LOCALE)->find() as $i18n) {
            $products[(int) $i18n->getId()]['title'] = (string) $i18n->getTitle();
        }

        return array_filter($products, static fn (array $product): bool => $product['title'] !== '');
    }
}
