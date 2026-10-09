<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Comparator\CatalogThumbnails;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Import\IdentityUpserter;
use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Import\SharedGtinFlags;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Referential\ProductEnrolment;
use PriceWatch\Service\Run\BackgroundRunner;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use PriceWatch\Service\Suggestion\SuggestionRepository;
use PriceWatch\Service\Suggestion\SuggestionRun;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\OrderProduct;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * What the suggestions tab proposes: products that sell, where we were
 * dearer, or whose competitor reading is getting old.
 */
final class SuggestionTest extends PriceWatchIntegrationTestCase
{
    private PwCompetitor $watched;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's competitors are whitelisted while it runs.
        PwCompetitorQuery::create()
            ->filterByStatus([CompetitorStatus::Watched->value, CompetitorStatus::Paused->value])
            ->update(['Status' => CompetitorStatus::Proposed->value]);

        $this->tag = bin2hex(random_bytes(4));
        $this->watched = (new CompetitorRepository())->create('sugg-'.$this->tag.'.example', CompetitorStatus::Watched, 'manual');
    }

    protected function tearDown(): void
    {
        $marker = sys_get_temp_dir().'/pricewatch-test-'.$this->tag.'.started';

        if (is_file($marker)) {
            unlink($marker);
        }

        parent::tearDown();
    }

    public function testOnlyRealSalesOfTheLast90DaysCount(): void
    {
        $gloves = $this->product('Gants '.$this->tag);
        $helmet = $this->product('Casque '.$this->tag);
        $this->sale($gloves, 'paid', 2, '10.00');
        // Sold on promotion: the promotional price is what was paid.
        $this->sale($gloves, 'sent', 1, '10.00', promo: '5.00');
        $this->sale($gloves, 'canceled', 5, '10.00');
        $this->sale($gloves, 'not_paid', 5, '10.00');
        $this->sale($gloves, 'paid', 7, '10.00', daysAgo: 120);
        $this->sale($helmet, 'processing', 1, '100.00');

        $sellers = (new SuggestionRepository())->bestSellers(new \DateTimeImmutable('-90 days'), 1000);

        self::assertSame(['quantity' => 3, 'revenue' => 25.0], $sellers[(int) $gloves->getId()]);
        self::assertSame(['quantity' => 1, 'revenue' => 100.0], $sellers[(int) $helmet->getId()]);
        $order = array_keys($sellers);
        self::assertLessThan(array_search((int) $gloves->getId(), $order, true), array_search((int) $helmet->getId(), $order, true), 'the highest revenue first');
    }

    public function testWhereWeWereDearerTheWidestGapCounts(): void
    {
        $jacket = $this->product('Blouson '.$this->tag);
        $other = (new CompetitorRepository())->create('sugg2-'.$this->tag.'.example', CompetitorStatus::Watched, 'manual');
        $blacklisted = (new CompetitorRepository())->create('black-'.$this->tag.'.example', CompetitorStatus::Rejected, 'manual');
        $this->offer($jacket, '-5.000');
        $this->offer($jacket, '-20.000', competitor: $other);
        $this->offer($jacket, '-50.000', competitor: $blacklisted);
        $this->offer($jacket, '-60.000', comparable: false);
        $equal = $this->product('Égal '.$this->tag);
        $this->offer($equal, '-0.040');

        $dearer = (new SuggestionRepository())->weWereDearer(1000);

        self::assertSame(['gapPct' => -20.0, 'competitors' => 2], $dearer[(int) $jacket->getId()], 'the blacklist and the not comparable never count');
        self::assertArrayNotHasKey((int) $equal->getId(), $dearer, 'shown as 0.0 %: not dearer');
    }

    public function testAnOldReadingNeedsACompetitorFoundAndAnOldCollect(): void
    {
        $old = $this->product('Ancien '.$this->tag);
        $recent = $this->product('Récent '.$this->tag);
        $unknown = $this->product('Inconnu '.$this->tag);
        $this->offer($old, '3.000', collectedDaysAgo: 20);
        $this->offer($recent, '3.000', collectedDaysAgo: 3);
        $this->identity($unknown, collectedDaysAgo: 30);

        $stale = (new SuggestionRepository())->stale(new \DateTimeImmutable('-14 days'), 1000);

        self::assertArrayHasKey((int) $old->getId(), $stale);
        self::assertArrayNotHasKey((int) $recent->getId(), $stale, 'collected 3 days ago');
        self::assertArrayNotHasKey((int) $unknown->getId(), $stale, 'no competitor ever found: the rotation is enough');
    }

    public function testTheProductsWithTheMostReasonsComeFirst(): void
    {
        $both = $this->product('Deux raisons '.$this->tag);
        $this->sale($both, 'paid', 1, '10.00');
        $this->offer($both, '-8.000');
        $soldOnly = $this->product('Vendu '.$this->tag);
        $this->sale($soldOnly, 'paid', 3, '900.00');
        $soldOnlySale = ProductSaleElementsQuery::create()->filterByProductId($soldOnly->getId())->findOne();
        self::assertNotNull($soldOnlySale);
        $soldOnlySale->setEanCode($this->ean())->save();
        $noEan = $this->product('Vendu sans EAN '.$this->tag);
        // Sells more than anything here: only the lack of a searchable EAN puts it last.
        $this->sale($noEan, 'paid', 9, '900.00');

        $rows = array_column($this->presenter()->present('fr_FR'), null, 'id');
        $order = array_keys($rows);

        self::assertLessThan(array_search((int) $soldOnly->getId(), $order, true), array_search((int) $both->getId(), $order, true), 'two reasons before one, whatever the revenue');
        self::assertSame(['sales', 'dearer'], array_column($rows[(int) $both->getId()]['reasons'], 'key'));
        self::assertFalse($rows[(int) $soldOnly->getId()]['watched'], 'sold but not watched yet');
        self::assertSame(1, $rows[(int) $soldOnly->getId()]['variants'], 'counted on its variants with a valid EAN');
        self::assertSame(0, $rows[(int) $noEan->getId()]['variants'], 'nothing to search: nothing to pay, nothing to tick');
        self::assertLessThan(array_search((int) $noEan->getId(), $order, true), array_search((int) $soldOnly->getId(), $order, true), 'what can be collected comes first');
    }

    public function testOnlyValidEanCodesOfTheirOwnAreSearched(): void
    {
        $gtin = new GtinNormalizer();
        $valid = $this->ean();
        $shared = $this->ean();

        self::assertSame(1, SuggestionPresenter::searchableVariants($gtin, [$valid, '', 'NULL', '3660600123455', $shared, ' '.$shared]), 'empty, wrong check digit and shared within the product left out');
    }

    public function testRankingWithoutDatabase(): void
    {
        $now = new \DateTimeImmutable();
        $ranked = SuggestionPresenter::rank(
            [1, 2, 3, 4],
            [1 => ['quantity' => 1, 'revenue' => 10.0], 2 => ['quantity' => 9, 'revenue' => 900.0]],
            [3 => ['gapPct' => -30.0, 'competitors' => 1], 1 => ['gapPct' => -5.0, 'competitors' => 1]],
            [4 => $now->modify('-20 days')],
        );

        self::assertSame([1, 2, 3, 4], $ranked, 'two reasons; then revenue; then the widest gap; then the oldest reading');
    }

    public function testCollectingTheSelectionAddsWhatIsNotWatchedAndLeavesOutWhatCannotBeSearched(): void
    {
        $searchable = $this->product('Cherchable '.$this->tag);
        $sale = ProductSaleElementsQuery::create()->filterByProductId($searchable->getId())->findOne();
        self::assertNotNull($sale);
        $sale->setEanCode($this->ean())->save();
        $withoutEan = $this->product('Sans EAN '.$this->tag);
        $started = [];

        $covered = $this->suggestionRun($started)->start([(int) $searchable->getId(), (int) $withoutEan->getId(), 999999999]);

        self::assertSame(['products' => 1, 'variants' => 1], $covered);
        self::assertSame(1, PwProductIdentityQuery::create()->filterByProductId($searchable->getId())->filterByMonitored(1)->count(), 'added to the watch');
        self::assertCount(1, $started, 'one pass for the whole selection');
        self::assertContains('--product='.$searchable->getRef(), $started[0]);
        self::assertNotContains('--product='.$withoutEan->getRef(), $started[0], 'nothing to search: left out, not failed');
    }

    public function testNothingStartsWhenNothingCanBeSearched(): void
    {
        $started = [];

        $covered = $this->suggestionRun($started)->start([(int) $this->product('Sans EAN '.$this->tag)->getId()]);

        self::assertSame(['products' => 0, 'variants' => 0], $covered);
        self::assertSame([], $started, 'no pass, no cost');
    }

    public function testNothingStartsWhileAPassRuns(): void
    {
        $started = [];
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('pricewatch.collect');
        $held->acquire();
        $product = $this->product('Pendant une passe '.$this->tag);

        $this->expectException(\RuntimeException::class);

        try {
            $this->suggestionRun($started, $locks)->start([(int) $product->getId()]);
        } finally {
            self::assertSame(0, PwProductIdentityQuery::create()->filterByProductId($product->getId())->count(), 'nothing added either');
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param list<list<string>> $started the collect command of each pass started
     */
    private function suggestionRun(array &$started, ?LockFactory $locks = null): SuggestionRun
    {
        $runner = new class($started) implements BackgroundRunner {
            /** @param list<list<string>> $started */
            public function __construct(private array &$started)
            {
            }

            public function start(array $commands, string $logFile): void
            {
                $this->started[] = $commands[0];
            }
        };
        $text = new TextNormalizer();
        $gtin = new GtinNormalizer();
        $enrolment = new ProductEnrolment(
            new CatalogVariantRepository(),
            new RowValidator($gtin, new MpnNormalizer(), new BrandNormalizer($text), new SizeNormalizer($text), new DomainNormalizer(), $text, new PairListParser()),
            new SaleElementResolver($gtin, $text),
            new IdentityUpserter($gtin, $text),
            new SharedGtinFlags(),
        );

        return new SuggestionRun($enrolment, new CatalogVariantRepository(), new ManualRun($runner, $locks ?? new LockFactory(new InMemoryStore()), '/tmp', sys_get_temp_dir().'/pricewatch-test-'.$this->tag.'.started'));
    }

    /**
     * A valid EAN-13 in the 2xx in-store range: never a real product's.
     */
    private function ean(): string
    {
        $body = '2'.str_pad((string) random_int(0, 99999999999), 11, '0', \STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split(strrev($body)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    private function presenter(): SuggestionPresenter
    {
        $routes = new RouteCollection();
        $routes->add('admin.products.update', new Route('/p'));
        $dispatcher = self::coreService('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new SuggestionPresenter(new SuggestionRepository(), new ComparatorRepository(), new CatalogThumbnails($dispatcher), new UrlGenerator($routes, new RequestContext()), new GtinNormalizer());
    }

    private function product(string $title): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title)->save();

        return $product;
    }

    private function sale(Product $product, string $status, int $quantity, string $price, ?string $promo = null, int $daysAgo = 1): void
    {
        $order = $this->createFixtureFactory()->order(null, ['statusCode' => $status]);
        $order->setCreatedAt(new \DateTime('-'.$daysAgo.' days'))->save();

        (new OrderProduct())
            ->setOrderId($order->getId())
            ->setProductRef((string) $product->getRef())
            ->setProductSaleElementsRef((string) $product->getRef())
            ->setTitle((string) $product->getRef())
            ->setQuantity($quantity)
            ->setPrice($price)
            ->setPromoPrice($promo ?? $price)
            ->setWasNew(0)
            ->setWasInPromo($promo === null ? 0 : 1)
            ->save();
    }

    private function identity(Product $product, int $collectedDaysAgo): PwProductIdentity
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        $identity = (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setGtin('0'.random_int(1000000000000, 9999999999999))
            ->setGtinValid(1)
            ->setGtinShared(0)
            ->setMonitored(1)
            ->setLastCollectedAt(new \DateTime('-'.$collectedDaysAgo.' days'));
        $identity->save();

        return $identity;
    }

    private function offer(Product $product, string $deltaPct, bool $comparable = true, ?PwCompetitor $competitor = null, int $collectedDaysAgo = 1): void
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        if (PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() === 0) {
            $this->identity($product, $collectedDaysAgo);
        }

        (new PwCompetitorOffer())
            ->setCompetitorId(($competitor ?? $this->watched)->getId())
            ->setProductSaleElementsId($saleElement->getId())
            ->setOfferKey('sugg-'.bin2hex(random_bytes(5)))
            ->setMatchState('matched_judged')
            ->setLastComparable($comparable ? 1 : 0)
            ->setLastPrice('90.00')
            ->setLastOurPrice('100.00')
            ->setLastDeltaPct($deltaPct)
            ->setActive(1)
            ->save();
    }
}
