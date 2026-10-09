<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
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

        $rows = $this->rows(new WatchedFilters(search: $this->tag));
        $byTitle = array_column($rows, null, 'title');

        self::assertCount(4, $rows, 'the referential is the list, offers or not');
        self::assertSame(2, (int) $byTitle['Casque jamais relevé '.$this->tag]['monitored']);
        self::assertNull($byTitle['Casque jamais relevé '.$this->tag]['last_collected_at']);
        self::assertSame(2, (int) $byTitle['Blouson comparé '.$this->tag]['compared']);
        self::assertSame(1, (int) $byTitle['Blouson comparé '.$this->tag]['review']);
        self::assertEqualsWithDelta(-12.5, (float) $byTitle['Blouson comparé '.$this->tag]['best_gap'], 1e-9, 'the worst gap for us, among comparable offers only');
        self::assertSame('Blouson comparé '.$this->tag, $rows[0]['title'], 'sorted by best gap first, products without one after');

        self::assertSame(
            ['all' => 4, 'never' => 2, 'not_collectable' => 1, 'compared' => 1, 'paused' => 1],
            $this->repository()->counts(new WatchedFilters(search: $this->tag), 'fr_FR'),
        );
        self::assertSame(['Gants sans GTIN '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::NotCollectable, $this->tag)), 'title'));
        self::assertSame(['Bottes en pause '.$this->tag], array_column($this->rows(new WatchedFilters(WatchedView::Paused, $this->tag)), 'title'));
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
