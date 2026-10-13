<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorPage;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedRepository;
use PriceWatch\Service\Watched\WatchedView;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Searches use a tag unique to the test, so the figures are the test's own
 * whatever the development database holds.
 */
final class WatchedTest extends PriceWatchIntegrationTestCase
{
    private string $tag;
    private PwCompetitor $watched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'PWW'.bin2hex(random_bytes(4));
        $this->watched = (new CompetitorRepository())->create('w-'.strtolower($this->tag).'.example', CompetitorStatus::Watched, 'manual');
    }

    public function testEveryWatchedProductIsListedEvenWhenNeverCollected(): void
    {
        $never = $this->product('Casque jamais relevé');
        $this->variant($never, monitored: true, collectable: true);
        $this->variant($never, monitored: true, collectable: true);

        $compared = $this->product('Blouson comparé');
        $comparedVariant = $this->variant($compared, monitored: true, collectable: true, collectedAt: '2026-10-09 10:00:00');
        $this->offer($comparedVariant, 'matched_judged', true, '-12.500');
        $this->offer($comparedVariant, 'matched_judged', true, '4.000');
        $this->offer($comparedVariant, 'needs_review', false, '-30.000');

        $noGtin = $this->product('Gants sans GTIN');
        $this->variant($noGtin, monitored: true, collectable: false);

        $paused = $this->product('Bottes en pause');
        $this->variant($paused, monitored: false, collectable: true);

        // One size paused, the other still watched: not a paused product.
        $partly = $this->product('Veste en partie suspendue');
        $this->variant($partly, monitored: true, collectable: true);
        $this->variant($partly, monitored: false, collectable: true);

        $rows = $this->rows(new WatchedFilters(search: $this->tag));
        $byTitle = array_column($rows, null, 'title');

        self::assertCount(5, $rows, 'the referential is the list, offers or not');
        self::assertSame(2, (int) $byTitle['Casque jamais relevé '.$this->tag]['monitored']);
        self::assertNull($byTitle['Casque jamais relevé '.$this->tag]['last_collected_at']);
        self::assertSame(2, (int) $byTitle['Blouson comparé '.$this->tag]['compared']);
        self::assertSame(1, (int) $byTitle['Blouson comparé '.$this->tag]['review']);
        self::assertEqualsWithDelta(-12.5, (float) $byTitle['Blouson comparé '.$this->tag]['best_gap'], 1e-9, 'the worst gap for us, among comparable offers only');
        self::assertSame('Blouson comparé '.$this->tag, $rows[0]['title'], 'sorted by best gap first, products without one after');

        self::assertSame(
            ['all' => 5, 'never' => 3, 'stale' => 0, 'not_collectable' => 1, 'compared' => 1, 'paused' => 1],
            $this->repository()->counts(new WatchedFilters(search: $this->tag), 'fr_FR', new \DateTimeImmutable('2026-10-12 12:00:00')),
        );
        self::assertSame(['Gants sans GTIN '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::NotCollectable, $this->tag)), 'title'));
        self::assertSame(['Bottes en pause '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::Paused, $this->tag)), 'title'));
    }

    public function testAnOfferToCheckCountsAsCompared(): void
    {
        $product = $this->product('À vérifier');
        $variant = $this->variant($product, monitored: true, collectable: true);
        $this->offer($variant, 'needs_review', true, '-30.000');
        $this->offer($variant, 'needs_review', false, '-50.000');

        $row = $this->rows(new WatchedFilters(search: $this->tag))[0];

        self::assertSame([1, 1], [(int) $row['compared'], (int) $row['review']]);
        self::assertEqualsWithDelta(-30.0, (float) $row['best_gap'], 1e-9, 'a doubt on the article never makes the best gap');
    }

    public function testOffersOfCompetitorsOffTheWhitelistDoNotCount(): void
    {
        $product = $this->product('Casque');
        $variant = $this->variant($product, monitored: true, collectable: true);
        $blacklisted = (new CompetitorRepository())->create('b-'.strtolower($this->tag).'.example', CompetitorStatus::Rejected, 'manual');
        $this->offer($variant, 'matched_judged', true, '-50.000', $blacklisted);

        $row = $this->rows(new WatchedFilters(search: $this->tag))[0];

        self::assertSame(0, (int) $row['compared']);
        self::assertNull($row['best_gap']);
    }

    public function testSortingByLastCollection(): void
    {
        $older = $this->product('Ancien');
        $this->variant($older, monitored: true, collectable: true, collectedAt: '2026-10-01 10:00:00');
        $newer = $this->product('Récent');
        $this->variant($newer, monitored: true, collectable: true, collectedAt: '2026-10-09 10:00:00');
        $never = $this->product('Jamais');
        $this->variant($never, monitored: true, collectable: true);

        self::assertSame(
            ['Récent '.$this->tag, 'Ancien '.$this->tag, 'Jamais '.$this->tag],
            array_column($this->rows(new WatchedFilters(search: $this->tag, sort: 'collected')), 'title'),
        );
    }

    /**
     * Asked 2026-10-12: show when a product was last watched, and say so
     * when it was long ago.
     */
    public function testAProductLastWatchedOverFourteenDaysAgoIsStale(): void
    {
        $old = $this->product('Ancien');
        $this->variant($old, monitored: true, collectable: true, collectedAt: '-20 days');
        $recent = $this->product('Récent');
        $this->variant($recent, monitored: true, collectable: true, collectedAt: '-3 days');
        // Searched on Google long ago, but read on a competitor page yesterday.
        $readDirectly = $this->product('Lu en direct');
        $this->variant($readDirectly, monitored: true, collectable: true, collectedAt: '-20 days');
        $this->page($readDirectly, 'read', '-1 day');
        // A page that failed to read watched nothing.
        $failedPage = $this->product('Page en erreur');
        $this->variant($failedPage, monitored: true, collectable: true);
        $this->page($failedPage, 'http_error', '-1 day');
        $paused = $this->product('En pause');
        $this->variant($paused, monitored: false, collectable: true, collectedAt: '-40 days');

        self::assertSame(['Ancien '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::Stale, $this->tag)), 'title'));
        $counts = $this->repository()->counts(new WatchedFilters(search: $this->tag), 'fr_FR');
        self::assertSame(1, $counts['stale'], 'a paused product raises no alert');
        self::assertSame(1, $counts['never']);
        self::assertSame(['Page en erreur '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::Never, $this->tag)), 'title'));

        $byTitle = array_column($this->rows(new WatchedFilters(search: $this->tag)), null, 'title');
        self::assertSame((new \DateTimeImmutable('-1 day'))->format('Y-m-d'), substr((string) $byTitle['Lu en direct '.$this->tag]['last_watched_at'], 0, 10), 'the latest of the Google search and the page read');

        self::assertEqualsWithDelta((new \DateTimeImmutable('-20 days'))->getTimestamp(), $this->repository()->lastWatched((int) $old->getId())?->getTimestamp(), 5);
        self::assertNull($this->repository()->lastWatched((int) $failedPage->getId()));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(WatchedFilters $filters): array
    {
        return $this->repository()->page($filters, 'fr_FR');
    }

    private function repository(): WatchedRepository
    {
        return new WatchedRepository(new ComparatorRepository());
    }

    private function product(string $title): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title.' '.$this->tag)->save();

        return $product;
    }

    private function variant(Product $product, bool $monitored, bool $collectable, ?string $collectedAt = null): ProductSaleElements
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();

        if ($saleElement === null || \PriceWatch\Model\PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() > 0) {
            $saleElement = (new ProductSaleElements())->setProductId($product->getId())->setRef('PWW-'.bin2hex(random_bytes(4)))->setQuantity(1)->setIsDefault(false);
            $saleElement->save();
        }

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setMonitored($monitored ? 1 : 0)
            ->setGtinValid($collectable ? 1 : 0)
            ->setGtinShared(0)
            ->setLastCollectedAt($collectedAt === null ? null : new \DateTime($collectedAt))
            ->save();

        return $saleElement;
    }

    private function page(Product $product, string $outcome, string $fetchedAt): void
    {
        $url = 'https://'.$this->watched->getDomain().'/'.bin2hex(random_bytes(4));

        (new PwCompetitorPage())
            ->setCompetitorId($this->watched->getId())
            ->setProductId($product->getId())
            ->setUrl($url)
            ->setUrlKey(sha1($url))
            ->setStatus('confirmed')
            ->setLastFetchedAt(new \DateTime($fetchedAt))
            ->setLastOutcome($outcome)
            ->save();
    }

    private function offer(ProductSaleElements $variant, string $state, bool $comparable, string $gapPct, ?PwCompetitor $competitor = null): void
    {
        (new PwCompetitorOffer())
            ->setCompetitorId(($competitor ?? $this->watched)->getId())
            ->setProductSaleElementsId($variant->getId())
            ->setOfferKey('w-'.bin2hex(random_bytes(5)))
            ->setMatchState($state)
            ->setLastComparable($comparable ? 1 : 0)
            ->setLastDeltaPct($gapPct)
            ->setActive(1)
            ->save();
    }
}
