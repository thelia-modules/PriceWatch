<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Comparator\CatalogThumbnails;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Proposal\PriceProposalPresenter;
use PriceWatch\Service\Proposal\PriceProposalRepository;
use PriceWatch\Service\Proposal\SalesHistogram;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Source\OurPrice;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Propel\Runtime\Propel;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactory;
use Thelia\Model\OrderProduct;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Products are created at 100 excluding tax under the first tax rule of the
 * base, 20 % for France (120 including tax), and the lines are read for the
 * test's own products only, whatever the development database holds.
 */
final class PriceProposalTest extends PriceWatchIntegrationTestCase
{
    private const NOW = '2026-10-15 12:00:00';

    private string $tag;
    private PwCompetitor $watched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'PWP'.bin2hex(random_bytes(4));
        $this->watched = (new CompetitorRepository())->create('p-'.strtolower($this->tag).'.example', CompetitorStatus::Watched, 'manual');
    }

    public function testDearerThanACompetitorAndSellingLessThanLastYearIsTheUrgentKind(): void
    {
        $product = $this->product('Casque');
        $variant = $this->variant($product, 'M', '50,00');
        $this->offer($variant, 'matched_exact', '110.49');
        $this->sale($product, 10, daysAgo: 375);
        $this->sale($product, 2, daysAgo: 10);

        $lines = $this->lines([$product]);

        self::assertCount(1, $lines);
        self::assertSame(120.0, $lines[0]['ourPrice'], 'precondition: 100 excl. tax at 20 %');
        self::assertSame('lower_declining', $lines[0]['kind']);
        self::assertSame(110.0, $lines[0]['proposedPrice'], 'the cheapest competitor rounded down to the euro');
        self::assertEqualsWithDelta(91.67, $lines[0]['proposedExclTax'], 0.001);
        self::assertSame(50.0, $lines[0]['purchase'], 'read with its decimal comma');
        self::assertEqualsWithDelta(50.0, $lines[0]['marginNowPct'], 1e-9);
        self::assertSame($this->watched->getDomain(), $lines[0]['competitor']);
        self::assertSame([2, 10, 0], [$lines[0]['units'], $lines[0]['unitsYearBefore'], $lines[0]['unitsPeriodBefore']]);
        self::assertEqualsWithDelta(-80.0, $lines[0]['yearOnYearPct'], 1e-9);
        self::assertNull($lines[0]['periodOnPeriodPct'], 'nothing sold the 90 days before: no change to give');
    }

    public function testSalesSteadyOnLastYearMakeItAPlainProposalWhateverTheSeason(): void
    {
        $product = $this->product('Blouson');
        $this->offer($this->variant($product, 'L', '50'), 'matched_judged', '100.00');
        $this->sale($product, 4, daysAgo: 380);
        $this->sale($product, 9, daysAgo: 120);
        $this->sale($product, 4, daysAgo: 20);

        $line = $this->lines([$product])[0];

        self::assertSame('lower', $line['kind'], 'steady on last year, down on the 90 days before: the season');
        self::assertSame([4, 4, 9], [$line['units'], $line['unitsYearBefore'], $line['unitsPeriodBefore']]);
    }

    public function testSizesReadAtTheSamePriceMakeOneLineAndAnUnreadSizeIsNotProposedFor(): void
    {
        $product = $this->product('Gants');
        $small = $this->variant($product, 'S', '50');
        $medium = $this->variant($product, 'M', '50');
        $this->variant($product, 'L', '50');
        $this->offer($small, 'matched_exact', '99.90');
        $this->offer($medium, 'matched_exact', '99.90');

        $lines = $this->lines([$product]);

        self::assertCount(1, $lines);
        self::assertSame(['S', 'M'], $lines[0]['sizes']);
        self::assertSame([(int) $small->getId(), (int) $medium->getId()], $lines[0]['saleElementIds']);
    }

    public function testOnlyMatchedOffersOfWatchedCompetitorsCount(): void
    {
        $product = $this->product('Bottes');
        $variant = $this->variant($product, '42', '40');
        $this->offer($variant, 'needs_review', '60.00');
        $rejected = (new CompetitorRepository())->create('r-'.strtolower($this->tag).'.example', CompetitorStatus::Rejected, 'manual');
        $this->offer($variant, 'matched_exact', '70.00', $rejected);
        $this->offer($variant, 'matched_manual', '115.00');
        $this->offer($variant, 'matched_exact', '118.00', (new CompetitorRepository())->create('o-'.strtolower($this->tag).'.example', CompetitorStatus::Watched, 'manual'));

        $line = $this->lines([$product])[0];

        self::assertSame(115.0, $line['proposedPrice'], 'a doubt or a blacklisted shop never sets a price');
        self::assertSame(2, $line['competitors']);
    }

    public function testACompetitorBelowTheFloorIsNotFollowedThere(): void
    {
        $product = $this->product('Dorsale');
        // Floor: 80 / 0.8 = 100 excl. tax, 120 incl. tax: where we are.
        $variant = $this->variant($product, null, '80');
        $this->offer($variant, 'matched_exact', '90.00');

        $line = $this->lines([$product])[0];

        self::assertSame('floor_blocked', $line['kind']);
        self::assertNull($line['proposedPrice']);
        self::assertSame(120.0, $line['floorPrice']);
        self::assertSame([(string) $variant->getRef()], $line['sizes'], 'a variant without size shows by its reference');
    }

    public function testACompetitorBelowTheFloorStillLetsUsComeDownToIt(): void
    {
        $product = $this->product('Casque jet');
        // Floor: 70 / 0.8 * 1.2 = 105.
        $this->offer($this->variant($product, 'S', '70'), 'matched_exact', '90.00');

        $line = $this->lines([$product])[0];

        self::assertSame('floor_blocked', $line['kind']);
        self::assertSame(105.0, $line['proposedPrice']);
        self::assertEqualsWithDelta(20.0, $line['marginProposedPct'], 1e-9);
    }

    public function testAPriceBelowTheFloorIsFlaggedWithoutAnyCompetitor(): void
    {
        $product = $this->product('Pantalon');
        $this->variant($product, 'XL', '96,5');

        $line = $this->lines([$product])[0];

        self::assertSame('margin_alert', $line['kind']);
        // 96.5 / 0.8 * 1.2 = 144.75
        self::assertSame(144.75, $line['proposedPrice']);
        self::assertNull($line['competitor']);
    }

    public function testTheMinimumMarginComesFromTheSettings(): void
    {
        $product = $this->product('Veste');
        $this->variant($product, 'M', '96,5');

        self::assertSame([], $this->lines([$product], minimumMargin: '3'), '3.5 % of margin left: above a 3 % floor');
    }

    public function testWithoutPurchasePriceTheAlignmentHasNoFloor(): void
    {
        $product = $this->product('Écran');
        $this->offer($this->variant($product, null, null), 'matched_exact', '30.00');

        $line = $this->lines([$product])[0];

        self::assertSame('lower', $line['kind']);
        self::assertSame(30.0, $line['proposedPrice']);
        self::assertNull($line['purchase']);
        self::assertNull($line['marginProposedPct']);
    }

    public function testAPausedVariantIsLeftOut(): void
    {
        $product = $this->product('En pause');
        $variant = $this->variant($product, 'M', '50');
        $this->offer($variant, 'matched_exact', '80.00');
        \PriceWatch\Model\PwProductIdentityQuery::create()->filterByProductSaleElementsId($variant->getId())->update(['Monitored' => 0]);

        self::assertSame([], $this->lines([$product]));
    }

    /**
     * Asked 2026-10-20: an offline product is never listed. No customer
     * sees its price: nothing to propose.
     */
    public function testAnOfflineProductIsLeftOut(): void
    {
        $product = $this->product('Hors ligne');
        $this->offer($this->variant($product, 'M', '50'), 'matched_exact', '80.00');
        $product->setVisible(0)->save();

        self::assertSame([], $this->lines([$product]));
    }

    public function testTheSalesChartCountsRealSalesPerMonthOverTheLast24Months(): void
    {
        $product = $this->product('Casque modulable');
        $this->offer($this->variant($product, 'M', '50'), 'matched_exact', '110.49');
        $this->sale($product, 3, daysAgo: 10);
        $this->sale($product, 2, daysAgo: 20);
        $this->sale($product, 4, daysAgo: 400);
        $this->sale($product, 7, daysAgo: 720);
        $this->sale($product, 5, daysAgo: 5, status: 'canceled');

        $chart = $this->forScreen($this->lines([$product]))[0]['salesHistory'];

        self::assertInstanceOf(SalesHistogram::class, $chart);
        self::assertSame('11/2024', $chart->bars[0]['month'], 'the sale of 10/2024 is out of the window');
        $units = array_column($chart->bars, 'units', 'month');
        self::assertSame([3, 2, 4], [$units['10/2026'], $units['09/2026'], $units['09/2025']], 'a cancelled order is not a sale');
        self::assertSame([5, 4], [$chart->lastTwelveUnits, $chart->twelveBeforeUnits]);
    }

    public function testTheLinesOfOneProductShareItsSalesChart(): void
    {
        $product = $this->product('Gants été');
        $this->offer($this->variant($product, 'S', '50'), 'matched_exact', '99.90');
        $this->offer($this->variant($product, 'M', '50'), 'matched_exact', '95.00');
        $this->sale($product, 2, daysAgo: 3);

        $lines = $this->forScreen($this->lines([$product]));

        self::assertCount(2, $lines, 'precondition: two competitor prices, two lines');
        self::assertSame([2, 2], [$lines[0]['salesHistory']->lastTwelveUnits, $lines[1]['salesHistory']->lastTwelveUnits]);
    }

    public function testAProductNeverSoldHasNoSalesChart(): void
    {
        $product = $this->product('Sans vente');
        $this->offer($this->variant($product, 'M', '50'), 'matched_exact', '80.00');

        self::assertNull($this->forScreen($this->lines([$product]))[0]['salesHistory']);
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    private function forScreen(array $lines): array
    {
        return $this->presenter()->forScreen($lines, new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<Product> $products
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $products, string $minimumMargin = ''): array
    {
        return $this->presenter($minimumMargin)->lines('fr_FR', new \DateTimeImmutable(self::NOW), array_map(static fn (Product $product): int => (int) $product->getId(), $products));
    }

    private function presenter(string $minimumMargin = ''): PriceProposalPresenter
    {
        $routes = new RouteCollection();
        $routes->add('admin.products.update', new Route('/p'));
        $routes->add('pricewatch_proposals', new Route('/proposals'));
        $routes->add('pricewatch_proposals_csv', new Route('/proposals.csv'));
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setMinimumMarginPct($minimumMargin);
        $dispatcher = self::coreService('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new PriceProposalPresenter(
            new PriceProposalRepository(),
            new ComparatorRepository(),
            new OurPrice(new TaxCalculatorFactory()),
            $settings,
            new UrlGenerator($routes, new RequestContext()),
            new CatalogThumbnails($dispatcher),
        );
    }

    private function product(string $title): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['basePrice' => 100.0, 'ref' => 'PWP-'.$this->tag.'-'.bin2hex(random_bytes(3))]);
        $product->setLocale('fr_FR')->setTitle($title.' '.$this->tag)->save();

        return $product;
    }

    /**
     * The first call takes the product's default variant, the next ones a
     * new variant at the same price.
     */
    private function variant(Product $product, ?string $size, ?string $purchase): ProductSaleElements
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();

        if ($saleElement === null || \PriceWatch\Model\PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() > 0) {
            $factory = $this->createFixtureFactory();
            $saleElement = $factory->productSaleElement($product);
            $factory->productPrice($saleElement, $factory->currency(), ['price' => '100.000000', 'promoPrice' => '100.000000']);
        }

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setSizeLabel($size)
            ->setMonitored(1)
            ->setGtinValid(1)
            ->setGtinShared(0)
            ->save();

        if ($purchase !== null) {
            $statement = Propel::getConnection('TheliaMain')->prepare('INSERT INTO '.PriceProposalRepository::PURCHASE_TABLE.' (product_sale_elements_id, purchase) VALUES (:id, :purchase)');
            self::assertNotFalse($statement);
            $statement->execute(['id' => $saleElement->getId(), 'purchase' => $purchase]);
        }

        return $saleElement;
    }

    private function offer(ProductSaleElements $variant, string $state, string $price, ?PwCompetitor $competitor = null): void
    {
        (new PwCompetitorOffer())
            ->setCompetitorId(($competitor ?? $this->watched)->getId())
            ->setProductSaleElementsId($variant->getId())
            ->setOfferKey('p-'.bin2hex(random_bytes(5)))
            ->setMatchState($state)
            ->setLastComparable(1)
            ->setLastPrice($price)
            ->setLastCurrencyCode('EUR')
            ->setLastSeenAt(new \DateTime('2026-10-14 08:00:00'))
            ->setActive(1)
            ->save();
    }

    private function sale(Product $product, int $quantity, int $daysAgo, string $status = 'paid'): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => $status]);
        $order->setCreatedAt((new \DateTime(self::NOW))->modify('-'.$daysAgo.' days'))->save();

        (new OrderProduct())
            ->setOrderId($order->getId())
            ->setProductRef((string) $product->getRef())
            ->setProductSaleElementsRef((string) $product->getRef())
            ->setTitle((string) $product->getRef())
            ->setQuantity($quantity)
            ->setPrice('100.00')
            ->setPromoPrice('100.00')
            ->setWasNew(0)
            ->setWasInPromo(0)
            ->save();
    }
}
