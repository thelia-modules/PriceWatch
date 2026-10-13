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
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use PriceWatch\Service\Suggestion\SuggestionRepository;
use PriceWatch\Service\Suggestion\SuggestionRun;
use PriceWatch\Service\Suggestion\SuggestionScore;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Category;
use Thelia\Model\OrderProduct;
use Thelia\Model\OrderQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * What the suggestions tab proposes: products we can sell that sell, where
 * we were dearer, whose competitor reading is getting old, or on a deep
 * promotion; the highest score first.
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

    /**
     * A product left to the direct pass is searched on Google once a month,
     * but read every pass: not an old reading.
     */
    public function testAProductReadOnACompetitorPageIsNoOldReading(): void
    {
        $readDirectly = $this->product('Lu en direct '.$this->tag);
        $this->offer($readDirectly, '3.000', collectedDaysAgo: 20);
        $url = 'https://'.$this->watched->getDomain().'/'.bin2hex(random_bytes(4));
        (new \PriceWatch\Model\PwCompetitorPage())
            ->setCompetitorId($this->watched->getId())
            ->setProductId($readDirectly->getId())
            ->setUrl($url)
            ->setUrlKey(sha1($url))
            ->setStatus('confirmed')
            ->setLastFetchedAt(new \DateTime('-1 day'))
            ->setLastOutcome('read')
            ->save();

        $stale = (new SuggestionRepository())->stale(new \DateTimeImmutable('-14 days'), 1000);

        self::assertArrayNotHasKey((int) $readDirectly->getId(), $stale);
    }

    public function testTheHighestScoreComesFirst(): void
    {
        $this->isolateFromTheShop();
        $several = $this->product('Plusieurs raisons '.$this->tag);
        $this->sale($several, 'paid', 1, '10.00');
        $this->offer($several, '-20.000');
        $this->promote($several, '100.00', '60.00');
        $soldOnly = $this->product('Vendu '.$this->tag);
        $this->sale($soldOnly, 'paid', 3, '900.00');
        $soldOnlySale = ProductSaleElementsQuery::create()->filterByProductId($soldOnly->getId())->findOne();
        self::assertNotNull($soldOnlySale);
        $soldOnlySale->setEanCode($this->ean())->save();
        $this->promote($soldOnly, '100.00', '95.00');
        $noEan = $this->product('Vendu sans EAN '.$this->tag);
        // Sells more than anything here: only the lack of a searchable EAN puts it last.
        $this->sale($noEan, 'paid', 9, '900.00');

        $rows = array_column($this->presenter()->present('fr_FR'), null, 'id');
        $order = array_keys($rows);

        self::assertLessThan(array_search((int) $soldOnly->getId(), $order, true), array_search((int) $several->getId(), $order, true), 'dearer by 20 % and 40 % off outweigh a far larger revenue');
        self::assertSame(['sales'], array_column($rows[(int) $soldOnly->getId()]['reasons'], 'key'), '5 % off is no reason');
        self::assertGreaterThan($rows[(int) $soldOnly->getId()]['score'], $rows[(int) $several->getId()]['score']);
        self::assertSame(['sales', 'dearer', 'promo'], array_column($rows[(int) $several->getId()]['reasons'], 'key'));
        self::assertSame(25, $rows[(int) $several->getId()]['scoreParts']['dearer']);
        self::assertSame(20, $rows[(int) $several->getId()]['scoreParts']['promo']);
        self::assertSame(35, $rows[(int) $noEan->getId()]['scoreParts']['sales'], 'the best seller here takes the full weight');
        self::assertFalse($rows[(int) $soldOnly->getId()]['watched'], 'sold but not watched yet');
        self::assertSame(1, $rows[(int) $soldOnly->getId()]['variants'], 'counted on its variants with a valid EAN');
        self::assertSame(0, $rows[(int) $noEan->getId()]['variants'], 'nothing to search: nothing to pay, nothing to tick');
        self::assertLessThan(array_search((int) $noEan->getId(), $order, true), array_search((int) $soldOnly->getId(), $order, true), 'what can be collected comes first');
    }

    public function testWhatWeCannotSellIsNeverSuggested(): void
    {
        $this->isolateFromTheShop();
        $sellable = $this->product('En stock '.$this->tag);
        $hidden = $this->product('Invisible '.$this->tag);
        $hidden->setVisible(0)->save();
        $soldOut = $this->product('Rupture '.$this->tag, stock: 0);

        foreach ([$sellable, $hidden, $soldOut] as $product) {
            $this->sale($product, 'paid', 1, '50.00');
        }

        $presenter = $this->presenter();
        $ids = array_column($presenter->present('fr_FR'), 'id');

        self::assertContains((int) $sellable->getId(), $ids);
        self::assertNotContains((int) $hidden->getId(), $ids, 'hidden: no customer sees its price');
        self::assertNotContains((int) $soldOut->getId(), $ids, 'out of stock: nothing to sell at any price');
        self::assertSame(\count($ids), $presenter->count(), 'the badge counts what the tab shows');
    }

    public function testADeepPromotionPutsAProductForwardWithoutAnySale(): void
    {
        $this->isolateFromTheShop();
        $deep = $this->product('Forte promo '.$this->tag);
        $this->promote($deep, '100.00', '70.00');
        $shallow = $this->product('Petite promo '.$this->tag);
        $this->promote($shallow, '100.00', '95.00');
        $soldOut = $this->product('Promo en rupture '.$this->tag, stock: 0);
        $this->promote($soldOut, '100.00', '50.00');

        $promoted = (new SuggestionRepository())->onPromotion(10.0, 1000);
        $rows = array_column($this->presenter()->present('fr_FR'), null, 'id');

        self::assertContains((int) $deep->getId(), $promoted);
        self::assertNotContains((int) $shallow->getId(), $promoted, '5 % off: most of the catalogue is');
        self::assertNotContains((int) $soldOut->getId(), $promoted);
        self::assertSame(['promo'], array_column($rows[(int) $deep->getId()]['reasons'], 'key'));
        self::assertSame(13, $rows[(int) $deep->getId()]['scoreParts']['promo'], '30 % off: two thirds of the way from 10 to 40 %');
    }

    public function testTheCatalogueSignalsCountTheVariantsInStockOnly(): void
    {
        $product = $this->product('Signaux '.$this->tag, stock: 2);
        $this->promote($product, '100.00', '80.00');
        $factory = $this->createFixtureFactory();
        $soldOutSize = $factory->productSaleElement($product, ['quantity' => 0]);
        $soldOutSize->setPromo(1)->setNewness(1)->save();
        $factory->productPrice($soldOutSize, $factory->currency(), ['price' => '100.000000', 'promoPrice' => '20.000000']);
        $newSize = $factory->productSaleElement($product, ['quantity' => 3]);
        $newSize->setNewness(1)->save();
        $factory->productPrice($newSize, $factory->currency(), ['price' => '50.000000', 'promoPrice' => '50.000000']);
        $hidden = $this->product('Signaux invisible '.$this->tag);
        $hidden->setVisible(0)->save();

        $signals = (new SuggestionRepository())->catalogSignals([(int) $product->getId(), (int) $hidden->getId()]);

        self::assertArrayNotHasKey((int) $hidden->getId(), $signals);
        self::assertEqualsWithDelta(20.0, $signals[(int) $product->getId()]['discountPct'], 0.001, 'the 80 % off size is out of stock');
        self::assertTrue($signals[(int) $product->getId()]['isNew']);
        self::assertEqualsWithDelta(2 * 80.0 + 3 * 50.0, $signals[(int) $product->getId()]['stockValue'], 0.001, 'at the price it sells for');
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
        // 9 sells most but cannot be sold now: absent from the signals.
        $sales = [1 => ['quantity' => 1, 'revenue' => 100.0], 2 => ['quantity' => 9, 'revenue' => 900.0], 5 => ['quantity' => 1, 'revenue' => 100.0], 9 => ['quantity' => 90, 'revenue' => 9000.0]];
        $plain = ['discountPct' => null, 'isNew' => false, 'stockValue' => 0.0];
        $scores = SuggestionPresenter::score(
            $sales,
            [3 => ['gapPct' => -30.0, 'competitors' => 1]],
            [4 => $now->modify('-20 days')],
            [1 => $plain, 2 => $plain, 3 => $plain, 4 => $plain, 5 => ['isNew' => true] + $plain],
            $now,
        );

        self::assertEqualsWithDelta(35.0 / 3, $scores[1]->sales, 0.0001, 'measured against the best seller among the products we can sell');
        self::assertEqualsWithDelta(10.0 / 3, $scores[4]->stale, 0.0001, '20 days out of 60');
        self::assertSame([2, 3, 5, 1, 4], SuggestionPresenter::rank($scores, $sales), '35; 25; 11.7 + 5 new; 11.7; 3.3 for 20 days');
        self::assertSame([7, 6], SuggestionPresenter::rank([6 => SuggestionScore::of(0.0, null, null, 30, false, 0.0), 7 => SuggestionScore::of(0.0, null, null, 30, false, 0.0)], [7 => ['quantity' => 1, 'revenue' => 1.0]]), 'on a tie, what sells');
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
     * Asked 2026-10-12: ranked across the shop, helmets took 47 lines out of
     * 50 and no glove ever got in. Best sellers are read family by family.
     */
    public function testEachFamilyBringsItsOwnBestSellers(): void
    {
        $this->isolateFromTheShop();
        [$helmets, $fullFace] = $this->family('Casques');
        [$gear, $gloves] = $this->family('Equipement');
        $dearHelmets = [];

        foreach ([1, 2, 3] as $index) {
            $dearHelmets[] = $helmet = $this->product('Casque '.$index.' '.$this->tag, category: $fullFace);
            $this->sale($helmet, 'paid', 1, (string) (600 + $index));
        }

        $glove = $this->product('Gants '.$this->tag, category: $gloves);
        $this->sale($glove, 'paid', 2, '30.00');
        $repository = new SuggestionRepository();
        $since = new \DateTimeImmutable('-90 days');

        $byFamily = $repository->bestSellersByFamily($since, 2, []);

        self::assertArrayHasKey((int) $glove->getId(), $byFamily, 'the best glove is read with the best helmets');
        self::assertCount(2, array_intersect(array_keys($byFamily), array_map(static fn (Product $product): int => (int) $product->getId(), $dearHelmets)), 'two per family');
        self::assertSame([(int) $glove->getId() => (int) $gear->getId()], $repository->families([(int) $glove->getId()], []), 'a sub-category belongs to its top-level family');
        self::assertEqualsWithDelta(60.0, $repository->familyRevenue($since, [])[(int) $gear->getId()] ?? 0.0, 0.001);
    }

    public function testEveryLineSaysItsFamilyAndTheFilterKeepsOne(): void
    {
        $this->isolateFromTheShop();
        [$helmets, $fullFace] = $this->family('Casques');
        [$gear, $gloves] = $this->family('Equipement');
        $helmet = $this->product('Casque '.$this->tag, category: $fullFace);
        $this->sale($helmet, 'paid', 1, '600.00');
        $glove = $this->product('Gants '.$this->tag, category: $gloves);
        $this->sale($glove, 'paid', 2, '30.00');

        $screen = $this->presenter()->screen('fr_FR');
        $rows = array_column($screen['rows'], null, 'id');

        self::assertSame('Equipement '.$this->tag, $rows[(int) $glove->getId()]['family'] ?? null);
        self::assertContains((int) $gear->getId(), array_column($screen['families'], 'id'), 'offered by the filter');

        $filtered = $this->presenter()->screen('fr_FR', family: (int) $gear->getId());

        self::assertSame([(int) $glove->getId()], array_column($filtered['rows'], 'id'));
        self::assertSame((int) $gear->getId(), $filtered['family']);
    }

    public function testAnExcludedOrHiddenFamilyIsNeverSuggested(): void
    {
        $this->isolateFromTheShop();
        [$unavailable, $unavailableSub] = $this->family('Articles Indisponibles');
        [$giftCards, $giftSub] = $this->family('Cartes cadeau', visible: false);
        [$helmets, $fullFace] = $this->family('Casques');
        $excluded = $this->product('Indisponible '.$this->tag, category: $unavailableSub);
        $this->sale($excluded, 'paid', 3, '900.00');
        $card = $this->product('Carte cadeau '.$this->tag, category: $giftSub);
        $this->sale($card, 'paid', 3, '900.00');
        $helmet = $this->product('Casque '.$this->tag, category: $fullFace);
        $this->sale($helmet, 'paid', 1, '600.00');

        $ids = array_column($this->presenter([(int) $unavailable->getId()])->present('fr_FR'), 'id');

        self::assertContains((int) $helmet->getId(), $ids);
        self::assertNotContains((int) $excluded->getId(), $ids, 'excluded in the settings');
        self::assertNotContains((int) $card->getId(), $ids, 'a hidden top-level category is no family to suggest from');
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

    /**
     * @param list<int> $excludedFamilies
     */
    private function presenter(array $excludedFamilies = []): SuggestionPresenter
    {
        $routes = new RouteCollection();
        $routes->add('admin.products.update', new Route('/p'));
        $dispatcher = self::coreService('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setExcludedFamilies($excludedFamilies);

        return new SuggestionPresenter(new SuggestionRepository(), new ComparatorRepository(), new CatalogThumbnails($dispatcher), new UrlGenerator($routes, new RequestContext()), new GtinNormalizer(), $settings);
    }

    /**
     * A top-level category, the family the suggestions group by, with one
     * sub-category under it: products sit in sub-categories in a real shop.
     *
     * @return array{Category, Category} the family and its sub-category
     */
    private function family(string $title, bool $visible = true): array
    {
        $factory = $this->createFixtureFactory();
        $root = $factory->category(['visible' => $visible ? 1 : 0]);
        $root->setLocale('fr_FR')->setTitle($title.' '.$this->tag)->save();
        $sub = $factory->category(['parent' => $root->getId()]);

        return [$root, $sub];
    }

    /**
     * A real shop fills the candidates with its own orders and promotions:
     * they are moved out of the way while the test runs (rolled back).
     */
    private function isolateFromTheShop(): void
    {
        OrderQuery::create()
            ->filterByCreatedAt(['min' => new \DateTime('-'.(SuggestionPresenter::SALES_DAYS + 1).' days')])
            ->update(['CreatedAt' => new \DateTime('-1 year')]);
        ProductSaleElementsQuery::create()->filterByPromo(1)->update(['Promo' => 0]);
    }

    /**
     * Visible and in stock unless told otherwise: what the tab may suggest.
     */
    private function product(string $title, int $stock = 5, ?Category $category = null): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($category ?? $factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => $stock]);
        $product->setLocale('fr_FR')->setTitle($title)->save();

        return $product;
    }

    private function promote(Product $product, string $price, string $promoPrice): void
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);
        $saleElement->setPromo(1)->save();

        $productPrice = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($saleElement->getId())
            ->filterByCurrencyId($this->createFixtureFactory()->currency()->getId())
            ->findOne();
        self::assertNotNull($productPrice);
        $productPrice->setPrice($price)->setPromoPrice($promoPrice)->save();
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
