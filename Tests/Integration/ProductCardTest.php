<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\ProductCardPresenter;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Watched\WatchedRepository;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

final class ProductCardTest extends PriceWatchIntegrationTestCase
{
    private PwCompetitor $first;
    private PwCompetitor $second;

    protected function setUp(): void
    {
        parent::setUp();

        PwCompetitorQuery::create()
            ->filterByStatus([CompetitorStatus::Watched->value, CompetitorStatus::Paused->value])
            ->update(['Status' => CompetitorStatus::Proposed->value]);

        $tag = bin2hex(random_bytes(4));
        $competitors = new CompetitorRepository();
        $this->first = $competitors->create('first-'.$tag.'.example', CompetitorStatus::Watched, 'manual');
        $this->second = $competitors->create('second-'.$tag.'.example', CompetitorStatus::Watched, 'manual');
    }

    public function testOneLinePerVariantWithTheCheapestComparableOfferFirst(): void
    {
        $product = $this->product();
        $small = $this->variant($product, 'S', '3660600123454');
        $this->variant($product, 'M', null);
        $this->offer($small, $this->first, 'matched_judged', true, '-8.000');
        $this->offer($small, $this->second, 'matched_manual', true, '3.000');
        // Shown as 0.0 %, in neutral: not counted as a place where we are dearer.
        $this->offer($small, $this->first, 'matched_judged', true, '-0.040');
        $this->offer($small, $this->second, 'needs_review', false, '-30.000');
        // Same item at a promotional price: matched, but out of the gap.
        $this->offer($small, $this->first, 'matched_judged', false, '-25.000');
        $blacklisted = (new CompetitorRepository())->create('black-'.bin2hex(random_bytes(4)).'.example', CompetitorStatus::Rejected, 'manual');
        $this->offer($small, $blacklisted, 'matched_judged', true, '-50.000');

        $card = $this->presenter()->present((int) $product->getId(), 'fr_FR');

        self::assertTrue($card['inReferential']);
        self::assertSame(['compared' => 3, 'review' => 1, 'weAreDearer' => 1], $card['totals'], 'the blacklisted offer counts nowhere');
        self::assertCount(2, $card['variants']);
        self::assertSame('S', $card['variants'][0]['size']);
        self::assertSame('3660600123454', $card['variants'][0]['gtin'], 'shown as published, without the storage padding');
        self::assertSame($this->first->getDomain(), $card['variants'][0]['best']['competitor']);
        self::assertSame('dearer', $card['variants'][0]['best']['position'], 'the competitor is 8 % cheaper: we are dearer, in red');
        self::assertStringContainsString('8,0', $card['variants'][0]['best']['gap']);
        self::assertNull($card['variants'][1]['best']);
        self::assertSame(['monitored' => 2, 'paused' => 0, 'missing' => 0, 'notCollectable' => 1, 'collectable' => 1], $card['watch'], 'the size without a GTIN is watched but never collected, and says so');
        self::assertFalse($card['variants'][1]['collectable']);
        self::assertStringContainsString('q='.urlencode((string) $product->getRef()), $card['comparatorUrl'], 'the comparator opens on this product');
    }

    public function testAnOfferToCheckCountsAsCompared(): void
    {
        $product = $this->product();
        $small = $this->variant($product, 'S', '3660600123454');
        // A doubt on the price only: comparable, tagged to check (2026-10-13).
        $this->offer($small, $this->first, 'needs_review', true, '-30.000');
        $this->offer($small, $this->second, 'needs_review', false, '-50.000');

        $card = $this->presenter()->present((int) $product->getId(), 'fr_FR');

        self::assertSame(['compared' => 1, 'review' => 1, 'weAreDearer' => 1], $card['totals']);
        self::assertSame($this->first->getDomain(), $card['variants'][0]['best']['competitor'], 'the doubt on the article is never the best price');
    }

    public function testAProductOutsideTheReferentialSaysSo(): void
    {
        $card = $this->presenter()->present((int) $this->product()->getId(), 'fr_FR');

        self::assertFalse($card['inReferential']);
        self::assertSame([], $card['variants']);
        self::assertSame(1, $card['watch']['missing'], 'its catalogue variant can be added');
    }

    public function testTheCardSaysWhenTheProductWasLastWatchedAndWarnsWhenLongAgo(): void
    {
        $product = $this->product();
        $small = $this->variant($product, 'S', '3660600123454');
        $presenter = $this->presenter();

        self::assertNull($presenter->present((int) $product->getId(), 'fr_FR')['lastWatch'], 'never watched');

        PwProductIdentityQuery::create()->filterByProductSaleElementsId($small->getId())->update(['LastCollectedAt' => new \DateTime('2026-09-22 11:00:00')]);
        $card = $presenter->present((int) $product->getId(), 'fr_FR', new \DateTimeImmutable('2026-10-12 12:00:00'));

        self::assertSame(['at' => '22/09/2026 11:00', 'daysAgo' => 20, 'stale' => true], $card['lastWatch']);
        self::assertFalse($presenter->present((int) $product->getId(), 'fr_FR', new \DateTimeImmutable('2026-09-30 12:00:00'))['lastWatch']['stale']);
    }

    // ------------------------------------------------------------------

    private function presenter(): ProductCardPresenter
    {
        $routes = new RouteCollection();
        $routes->add('pricewatch_comparator', new Route('/admin/module/pricewatch/comparator'));

        return new ProductCardPresenter(new ComparatorRepository(), new CatalogVariantRepository(), new UrlGenerator($routes, new RequestContext()), new WatchedRepository(new ComparatorRepository()));
    }

    private function product(): Product
    {
        $factory = $this->createFixtureFactory();

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
    }

    private function variant(Product $product, string $size, ?string $gtin): ProductSaleElements
    {
        // The product's default variant first, then new ones.
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();

        if ($saleElement === null || PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() > 0) {
            $saleElement = (new ProductSaleElements())
                ->setProductId($product->getId())
                ->setRef('PW-CARD-'.bin2hex(random_bytes(4)))
                ->setQuantity(1)
                ->setIsDefault(false);
            $saleElement->save();
        }

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setSizeLabel($size)
            ->setGtin($gtin === null ? null : str_pad($gtin, 14, '0', \STR_PAD_LEFT))
            ->setGtinValid($gtin === null ? 0 : 1)
            ->setMonitored(1)
            ->save();

        return $saleElement;
    }

    private function offer(ProductSaleElements $variant, PwCompetitor $competitor, string $state, bool $comparable, string $deltaPct): void
    {
        (new PwCompetitorOffer())
            ->setCompetitorId($competitor->getId())
            ->setProductSaleElementsId($variant->getId())
            ->setOfferKey('card-'.bin2hex(random_bytes(5)))
            ->setMatchState($state)
            ->setLastComparable($comparable ? 1 : 0)
            ->setLastDeltaPct($deltaPct)
            ->setActive(1)
            ->save();
    }
}
