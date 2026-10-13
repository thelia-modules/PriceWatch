<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Comparator\CatalogThumbnails;
use PriceWatch\Service\Comparator\ComparatorFilters;
use PriceWatch\Service\Comparator\ComparatorPresenter;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Comparator\OfferReview;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\Propel;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\Product;
use Thelia\Model\ProductImage;
use Thelia\Model\ProductSaleElementsQuery;

final class ComparatorTest extends PriceWatchIntegrationTestCase
{
    private ComparatorRepository $repository;
    private PwCompetitor $watched;
    private string $tag;

    /** @var list<string> pictures written for the test, removed after it */
    private array $pictures = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's competitors are whitelisted while it runs
        // (rolled back after), so the counts are this test's own.
        PwCompetitorQuery::create()
            ->filterByStatus([CompetitorStatus::Watched->value, CompetitorStatus::Paused->value])
            ->update(['Status' => CompetitorStatus::Proposed->value]);

        $this->tag = bin2hex(random_bytes(4));
        $this->repository = new ComparatorRepository();
        $this->watched = (new CompetitorRepository())->create('cmp-'.$this->tag.'.example', CompetitorStatus::Watched, 'manual');
    }

    protected function tearDown(): void
    {
        foreach ($this->pictures as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testEveryOfferIsInExactlyOneTab(): void
    {
        $product = $this->product('Blouson Helstons Indy cuir noir');
        $this->offer($product, 'matched_judged', comparable: true, deltaPct: '5.000');
        // Shows as 0.0 %: counted on neither side, like its neutral colour.
        $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-0.040');
        $cheapest = $this->offer($product, 'matched_manual', comparable: true, deltaPct: '-12.500');
        $this->offer($product, 'matched_judged', comparable: false, deltaPct: '-40.000', exclusion: 'promotional');
        $this->offer($product, 'needs_review', comparable: false, deltaPct: '-30.000');
        $this->offer($product, 'candidate', comparable: false, deltaPct: '1.000');
        $this->offer($product, 'rejected', comparable: false, deltaPct: '-17.000');

        $filters = new ComparatorFilters();

        self::assertSame(['gaps' => 3, 'review' => 1, 'pending' => 1, 'excluded' => 2], $this->repository->counts($filters));
        $gaps = $this->repository->page($filters);
        self::assertSame($cheapest->getId(), $gaps[0]->getId(), 'the most worrying gap first: competitor cheapest');
        self::assertSame(['weAreCheaper' => 1, 'weAreDearer' => 1], array_slice($this->repository->gapSummary($filters), 0, 2));
    }

    /**
     * Asked 2026-10-13: a doubt on the price or the size is shown and counted
     * in the gaps, tagged to check; only a doubt on the article stays in the
     * review tab.
     */
    public function testDoubtsOnPriceOrSizeCountInTheGapsTaggedToCheck(): void
    {
        $product = $this->product('Blouson Segura Lady Funky '.$this->tag);
        // As the judge leaves them: a doubt on the price or the size keeps
        // the offer comparable, a doubt on the article does not.
        $this->offer($product, 'needs_review', comparable: true, deltaPct: '49.500', size: 'T2')->setMatchReason('PRICE_GAP_SUSPECT')->save();
        $this->offer($product, 'needs_review', comparable: true, deltaPct: '-3.000', size: 'T4')->setMatchReason('VARIANT_UNCLEAR')->save();
        $article = $this->offer($product, 'needs_review', comparable: false, deltaPct: '-30.000', size: 'T5');
        $article->setMatchReason('PRODUCT_UNCLEAR')->save();

        $filters = new ComparatorFilters(search: $this->tag);

        self::assertSame(['gaps' => 2, 'review' => 1, 'pending' => 0, 'excluded' => 0], $this->repository->counts($filters));
        self::assertSame(['weAreCheaper' => 1, 'weAreDearer' => 1], array_slice($this->repository->gapSummary($filters), 0, 2), 'counted like any other offer');
        $lines = $this->presenter()->present($filters, 'fr_FR')['products'][0]['lines'];
        self::assertSame([true, true], array_column($lines, 'toCheck'), 'tagged: no human decided');
        self::assertSame([(int) $article->getId()], $this->ids(new ComparatorFilters(view: ComparatorView::Review, search: $this->tag)), 'another article maybe: still a human\'s call');
    }

    public function testOffersOfCompetitorsOffTheWhitelistAreNeverShown(): void
    {
        $product = $this->product('Casque Nexx X.WED3 Keyo');
        $blacklisted = (new CompetitorRepository())->create('black-'.$this->tag.'.example', CompetitorStatus::Rejected, 'manual');
        $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-5.000', competitor: $blacklisted);

        self::assertSame(0, $this->repository->counts(new ComparatorFilters())['gaps']);
        self::assertSame([], $this->repository->page(new ComparatorFilters(competitorId: (int) $blacklisted->getId())), 'not even when asked for by id');
    }

    public function testFiltersNarrowTheList(): void
    {
        $helmet = $this->product('Casque Bell TX501 Sprint '.$this->tag);
        $jacket = $this->product('Blouson Segura Dorian '.$this->tag);
        $cheaper = $this->offer($helmet, 'matched_judged', comparable: true, deltaPct: '-3.000');
        $this->offer($jacket, 'matched_judged', comparable: true, deltaPct: '8.000');
        // Shown as 0.0 %, in neutral: not a place where we are dearer.
        $this->offer($this->product('Gants Furygan '.$this->tag), 'matched_judged', comparable: true, deltaPct: '-0.040');

        self::assertSame([$cheaper->getId()], $this->ids(new ComparatorFilters(search: 'TX501 Sprint '.$this->tag)));
        self::assertSame([$cheaper->getId()], $this->ids(new ComparatorFilters(search: (string) $helmet->getRef())));
        self::assertSame([$cheaper->getId()], $this->ids(new ComparatorFilters(cheaperOnly: true)));
        self::assertSame([], $this->ids(new ComparatorFilters(search: 'nothing-like-this-'.$this->tag)));
    }

    public function testConfirmingMakesTheOfferCountAndKeepsTheHumanName(): void
    {
        $offer = $this->offer($this->product('Stormer Crusher'), 'needs_review', comparable: false, deltaPct: '-10.000');

        (new OfferReview())->confirm($offer, 'emmanuel');

        $offer->reload();
        self::assertSame('matched_manual', $offer->getMatchState());
        self::assertSame(1, $offer->getLastComparable());
        self::assertSame('emmanuel', $offer->getReviewedBy());
        self::assertSame($offer->getIdentityFingerprint(), $offer->getJudgedFingerprint(), 'a later change of the remote product shows as drift');
        self::assertSame(1, $this->latestQuote($offer)->getComparable(), 'the history says what the screen says');
        self::assertSame(1, $this->repository->counts(new ComparatorFilters())['gaps']);
    }

    public function testConfirmingTheProductDoesNotMakeAPromotionComparable(): void
    {
        $offer = $this->offer($this->product('Segura Flex Alpha'), 'matched_judged', comparable: false, deltaPct: '-35.000', exclusion: 'promotional');

        (new OfferReview())->confirm($offer, 'emmanuel');

        $offer->reload();
        self::assertSame('matched_manual', $offer->getMatchState());
        self::assertSame(0, $offer->getLastComparable());
        self::assertSame('promotional', $offer->getExclusionReason());
    }

    public function testExcludingAndReopening(): void
    {
        $offer = $this->offer($this->product('Premier Monza'), 'matched_judged', comparable: true, deltaPct: '-2.000');
        $review = new OfferReview();

        $review->reject($offer, 'emmanuel');
        $offer->reload();
        self::assertSame('rejected', $offer->getMatchState());
        self::assertSame(0, $offer->getLastComparable());
        self::assertSame(0, $this->latestQuote($offer)->getComparable());

        $review->reopen($offer, 'emmanuel');
        $offer->reload();
        self::assertSame('needs_review', $offer->getMatchState());
        self::assertSame('REOPENED', $offer->getMatchReason());
        self::assertSame(1, $offer->getLastComparable(), 'counted again meanwhile, tagged to check (2026-10-13)');

        $promotion = $this->offer($this->product('Premier Trophy'), 'matched_judged', comparable: false, deltaPct: '-40.000', exclusion: 'promotional');
        $review->reopen($promotion, 'emmanuel');
        $promotion->reload();
        self::assertSame([0, 'promotional'], [$promotion->getLastComparable(), $promotion->getExclusionReason()], 'a price that never compares stays out');
    }

    /**
     * Plan stage E8: the offer list sorted by gap must be read through
     * idx_pw_offer_screen, filter and order alike, without a sort pass, at a
     * realistic volume. Since 2026-10-12 the gaps tab pages by product
     * (productPage): its filter still reads this index, its groups are sorted.
     */
    public function testTheGapsTabIsServedByTheScreenIndexWithoutSorting(): void
    {
        $product = $this->product('Volume');
        $saleElementId = (int) ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne()?->getId();
        $connection = Propel::getConnection('TheliaMain');
        $insert = $connection->prepare('INSERT INTO pw_competitor_offer (competitor_id, product_sale_elements_id, offer_key, match_state, last_comparable, last_delta_pct, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');

        for ($i = 0; $i < 2000; ++$i) {
            $insert->execute([
                $this->watched->getId(),
                $saleElementId,
                'volume-'.$i,
                $i % 4 === 0 ? 'needs_review' : 'matched_judged',
                $i % 3 === 0 ? 0 : 1,
                (string) (($i % 200) - 100),
                $i % 10 === 0 ? 0 : 1,
            ]);
        }

        // No ANALYZE TABLE here: it commits implicitly, and the 2,000 rows
        // would stay in the development database (it happened, 2026-10-09).

        // Both directions of the gap sort: the index is read backwards.
        foreach ([new ComparatorFilters(), new ComparatorFilters(sort: ComparatorFilters::SORT_GAP_DESC)] as $filters) {
            $query = $this->repository->pageQuery($filters);
            self::assertNotNull($query);
            // The selected columns are only added when a query runs.
            $query->addSelfSelectColumns();
            $params = [];
            $sql = $query->createSelectSql($params);
            $statement = $connection->prepare('EXPLAIN '.$sql);

            // Propel names its placeholders :p1, :p2... in the order of $params.
            foreach ($params as $position => $param) {
                $statement->bindValue(':p'.($position + 1), $param['value']);
            }

            $statement->execute();
            $plan = $statement->fetchAll(\PDO::FETCH_ASSOC);

            self::assertSame('idx_pw_offer_screen', $plan[0]['key'], 'plan: '.json_encode($plan).' for '.$sql);
            self::assertStringNotContainsString('filesort', (string) $plan[0]['Extra'], 'plan: '.json_encode($plan).' for '.$sql);
        }
    }

    public function testTheGapSortCanBeReversed(): void
    {
        $product = $this->product('Gants Furygan '.$this->tag);
        $dearer = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-12.500');
        $equal = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-0.040');
        $cheaper = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '5.000');

        self::assertSame([$dearer->getId(), $equal->getId(), $cheaper->getId()], $this->ids(new ComparatorFilters()));
        self::assertSame([$cheaper->getId(), $equal->getId(), $dearer->getId()], $this->ids(new ComparatorFilters(sort: ComparatorFilters::SORT_GAP_DESC)), 'we are cheapest first');
    }

    public function testOnlyTheLastReadingOfEachOfferIsLoaded(): void
    {
        $offer = $this->offer($this->product('Casque Shark '.$this->tag), 'matched_judged', comparable: true, deltaPct: '-3.000');
        $today = $this->latestQuote($offer);
        $today->setCollectedDay(new \DateTime('yesterday'))->setCollectedAt(new \DateTime('-1 day'))->save();
        $latest = (new PwPriceQuote())->setOfferId($offer->getId())->setCollectedDay(new \DateTime())->setSource('test')->setCollectedAt(new \DateTime())->setPrice('88.00');
        $latest->save();

        self::assertSame($latest->getId(), $this->repository->latestQuotes([(int) $offer->getId()])[(int) $offer->getId()]->getId());
    }

    public function testAnOfferWithoutPictureBorrowsTheOneOfItsProduct(): void
    {
        $product = $this->product('Blouson Ixon '.$this->tag);
        $withPicture = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-3.000');
        $this->latestQuote($withPicture)->setRawPayloadJson((string) json_encode(['card' => ['image' => 'https://encrypted-tbn0.gstatic.com/'.$this->tag.'.jpg']], \JSON_UNESCAPED_SLASHES))->save();
        $hostile = $this->product('Blouson Hostile '.$this->tag);
        $this->latestQuote($this->offer($hostile, 'matched_judged', comparable: true, deltaPct: '-3.000'))
            ->setRawPayloadJson('{"card":{"image":"http\\u0000javascript:alert(1)"}}')->save();

        $images = $this->repository->cardImages([(int) $product->getId(), (int) $hostile->getId()]);

        self::assertSame(['https://encrypted-tbn0.gstatic.com/'.$this->tag.'.jpg'], array_values($images));
        self::assertArrayHasKey((int) $product->getId(), $images, 'any offer of the product lends its card picture');
    }

    /**
     * Asked 2026-10-12: the sizes of one product read at one price at one
     * competitor are one line, not five.
     */
    public function testTheSizesOfAProductAtOnePriceAreOneLine(): void
    {
        $product = $this->product('Casque Arai '.$this->tag);
        $medium = $this->offer($product, 'matched_exact', comparable: true, deltaPct: '-10.000');
        $small = $this->offer($product, 'matched_exact', comparable: true, deltaPct: '-10.000', size: 'S');
        $large = $this->offer($product, 'matched_exact', comparable: true, deltaPct: '-10.000', size: 'L');
        $dearer = $this->offer($product, 'matched_exact', comparable: true, deltaPct: '-5.000', size: 'XL', price: '95.00');

        $rows = $this->presenter()->present(new ComparatorFilters(search: $this->tag), 'fr_FR')['rows'];

        self::assertCount(2, $rows, 'one line for M, S and L; XL is sold at another price');
        $grouped = array_values(array_filter($rows, static fn (array $row): bool => \count($row['groupIds']) > 1))[0] ?? null;
        self::assertNotNull($grouped);
        self::assertEqualsCanonicalizing(['M', 'S', 'L'], $grouped['sizes']);
        self::assertEqualsCanonicalizing([(int) $medium->getId(), (int) $small->getId(), (int) $large->getId()], $grouped['groupIds']);
        self::assertContains([(int) $dearer->getId()], array_column($rows, 'groupIds'));
    }

    /**
     * Asked 2026-10-12: on the gaps tab, a product is one line carrying every
     * competitor, paged and sorted by product.
     */
    public function testTheGapsTabHasOneLinePerProductWithEveryCompetitor(): void
    {
        $other = (new CompetitorRepository())->create('other-'.$this->tag.'.example', CompetitorStatus::Watched, 'manual');
        $helmet = $this->product('Casque Arai SZ-R '.$this->tag);
        $small = $this->offer($helmet, 'matched_exact', comparable: true, deltaPct: '10.900', size: 'S', price: '110.90');
        $atOther = $this->offer($helmet, 'matched_exact', comparable: true, deltaPct: '-4.000', competitor: $other, price: '96.00');
        $otherSize = $this->offer($helmet, 'matched_exact', comparable: true, deltaPct: '-4.000', competitor: $other, size: 'L', price: '96.00');
        $jacket = $this->product('Blouson Segura '.$this->tag);
        $jacketOffer = $this->offer($jacket, 'matched_judged', comparable: true, deltaPct: '-20.000');

        $filters = new ComparatorFilters(search: $this->tag);
        $screen = $this->presenter()->present($filters, 'fr_FR');
        $products = $screen['products'];

        self::assertSame([(int) $jacket->getId(), (int) $helmet->getId()], array_column($products, 'id'), 'competitor cheapest first, by product');
        self::assertSame(2, $this->repository->productCount($filters));
        self::assertSame(1, $screen['pagination']['pages']);
        $line = $products[1];
        self::assertSame(['other-'.$this->tag.'.example', 'cmp-'.$this->tag.'.example'], array_column($line['competitors'], 'name'), 'every competitor on the line, the cheapest first');
        self::assertSame((int) $atOther->getId(), $line['headline']['id'], 'the line shows its most telling gap');
        self::assertEqualsCanonicalizing([(int) $atOther->getId(), (int) $otherSize->getId()], $line['competitors'][0]['headline']['groupIds'], 'sizes at one price stay one line in the detail');
        self::assertSame([(int) $small->getId()], $line['competitors'][1]['headline']['groupIds']);
        self::assertCount(2, $line['lines']);
        self::assertSame([(int) $jacketOffer->getId()], $products[0]['lines'][0]['groupIds']);

        $reversed = $this->repository->productPage(new ComparatorFilters(search: $this->tag, sort: ComparatorFilters::SORT_GAP_DESC));
        self::assertSame([(int) $helmet->getId(), (int) $jacket->getId()], $reversed, 'we are cheapest first: ranked by the dearest competitor');
    }

    /**
     * Asked 2026-10-13: the gaps tab's layout on every tab, one line per
     * product with its competitors, the offers in the detail.
     */
    public function testEveryTabListsProductsWithTheirCompetitors(): void
    {
        $other = (new CompetitorRepository())->create('other-'.$this->tag.'.example', CompetitorStatus::Watched, 'manual');
        $helmet = $this->product('Casque à revoir '.$this->tag);
        $this->offer($helmet, 'needs_review', comparable: false, deltaPct: '-30.000', size: 'S');
        $this->offer($helmet, 'needs_review', comparable: false, deltaPct: '-10.000', competitor: $other, size: 'M');
        $jacket = $this->product('Blouson écarté '.$this->tag);
        $this->offer($jacket, 'rejected', comparable: false, deltaPct: '-5.000');

        $review = $this->presenter()->present(new ComparatorFilters(view: ComparatorView::Review, search: $this->tag), 'fr_FR');
        $excluded = $this->presenter()->present(new ComparatorFilters(view: ComparatorView::Excluded, search: $this->tag), 'fr_FR');

        self::assertSame([(int) $helmet->getId()], array_column($review['products'], 'id'), 'one line for the product');
        self::assertCount(2, $review['products'][0]['competitors'], 'both competitors on it');
        self::assertCount(2, $review['products'][0]['lines'], 'each offer in the detail, decided on its own');
        self::assertSame([(int) $jacket->getId()], array_column($excluded['products'], 'id'));
    }

    public function testADecisionOnALineReachesEveryOfferOfThatLineOnly(): void
    {
        $product = $this->product('Casque Arai '.$this->tag);
        $medium = $this->offer($product, 'needs_review', comparable: false, deltaPct: '-10.000');
        $small = $this->offer($product, 'needs_review', comparable: false, deltaPct: '-10.000', size: 'S');
        $elsewhere = $this->offer($this->product('Autre '.$this->tag), 'needs_review', comparable: false, deltaPct: '-10.000');

        $siblings = $this->repository->sameLine($medium, [(int) $small->getId(), (int) $elsewhere->getId(), 999999999]);

        self::assertSame([(int) $small->getId()], array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getId(), $siblings), 'another product\'s offer never rides on this line\'s decision');
    }

    public function testEveryLineGetsAPictureAndAnUnsignedEqualGap(): void
    {
        $product = $this->product('Blouson Furygan '.$this->tag);
        $withPicture = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-3.000');
        $this->latestQuote($withPicture)->setRawPayloadJson((string) json_encode(['card' => ['image' => 'https://img.example/'.$this->tag.'.jpg']], \JSON_UNESCAPED_SLASHES))->save();
        $without = $this->offer($product, 'matched_judged', comparable: true, deltaPct: '-0.040');
        $ours = $this->product('Casque catalogue '.$this->tag);
        (new ProductImage())->setProductId($ours->getId())->setFile($this->pictureOnDisk())->setVisible(1)->setPosition(1)->save();
        $catalogue = $this->offer($ours, 'matched_judged', comparable: true, deltaPct: '2.000');

        $rows = array_column($this->presenter()->present(new ComparatorFilters(search: $this->tag), 'fr_FR')['rows'], null, 'id');

        self::assertSame('https://img.example/'.$this->tag.'.jpg', $rows[$without->getId()]['image'], 'borrowed from the other offer on the product');
        self::assertStringContainsString('/cache/images/product/', (string) $rows[$catalogue->getId()]['image'], 'our own picture when Google gave none');
        self::assertStringNotContainsString('-', (string) $rows[$without->getId()]['deltaPct'], 'shown as 0.0 %, without a sign');
        self::assertSame(['side' => 'left', 'width' => 4], $rows[$withPicture->getId()]['bar']);
    }

    public function testOurOwnPictureIsTheLastResort(): void
    {
        $product = $this->product('Casque catalogue '.$this->tag);
        $missing = $this->product('Casque sans fichier '.$this->tag);
        $file = $this->pictureOnDisk();
        (new ProductImage())->setProductId($product->getId())->setFile($file)->setVisible(1)->setPosition(1)->save();
        (new ProductImage())->setProductId($missing->getId())->setFile('missing-'.$this->tag.'.jpg')->setVisible(1)->setPosition(1)->save();

        $dispatcher = self::coreService('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $urls = (new CatalogThumbnails($dispatcher))->urls([(int) $product->getId(), (int) $missing->getId()]);

        self::assertArrayHasKey((int) $product->getId(), $urls);
        // The image cache lowercases the file name.
        self::assertStringContainsString('/cache/images/product/', $urls[(int) $product->getId()]);
        self::assertStringContainsStringIgnoringCase(pathinfo($file, \PATHINFO_FILENAME), $urls[(int) $product->getId()]);
        self::assertArrayNotHasKey((int) $missing->getId(), $urls, 'a missing file: no picture, no error');
    }

    // ------------------------------------------------------------------

    private function presenter(): ComparatorPresenter
    {
        $routes = new RouteCollection();

        foreach (['pricewatch_comparator' => '/c', 'admin.products.update' => '/p'] as $name => $path) {
            $routes->add($name, new Route($path));
        }

        $dispatcher = self::coreService('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return new ComparatorPresenter($this->repository, new UrlGenerator($routes, new RequestContext()), new CatalogThumbnails($dispatcher));
    }

    /**
     * @return list<int>
     */
    private function ids(ComparatorFilters $filters): array
    {
        return array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getId(), $this->repository->page($filters));
    }

    private function product(string $title): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title)->save();

        return $product;
    }

    /**
     * @param string|null $size another size of the product: a variant of its own
     */
    private function offer(Product $product, string $state, bool $comparable, string $deltaPct, ?string $exclusion = null, ?PwCompetitor $competitor = null, ?string $size = null, string $price = '90.00'): PwCompetitorOffer
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        if ($size !== null) {
            $saleElement = (new \Thelia\Model\ProductSaleElements())
                ->setProductId($product->getId())
                ->setRef('PW-SIZE-'.bin2hex(random_bytes(4)))
                ->setQuantity(1)
                ->setIsDefault(false);
            $saleElement->save();
        }

        if (PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() === 0) {
            (new PwProductIdentity())
                ->setProductSaleElementsId($saleElement->getId())
                ->setProductId($product->getId())
                ->setSizeLabel($size ?? 'M')
                ->save();
        }

        $offer = (new PwCompetitorOffer())
            ->setCompetitorId(($competitor ?? $this->watched)->getId())
            ->setProductSaleElementsId($saleElement->getId())
            ->setOfferKey('cmp-'.bin2hex(random_bytes(5)))
            ->setRemoteTitle('Remote '.$product->getRef())
            ->setIdentityFingerprint(sha1('remote '.$product->getRef()))
            ->setMatchState($state)
            ->setLastComparable($comparable ? 1 : 0)
            ->setExclusionReason($exclusion)
            ->setLastPrice($price)
            ->setLastOurPrice('100.00')
            ->setLastDeltaPct($deltaPct)
            ->setLastSeenAt(new \DateTime())
            ->setActive(1);
        $offer->save();

        (new PwPriceQuote())
            ->setOfferId($offer->getId())
            ->setCollectedDay(new \DateTime())
            ->setSource('test')
            ->setCollectedAt(new \DateTime())
            ->setPrice('90.00')
            ->setOurPrice('100.00')
            ->setComparable($comparable ? 1 : 0)
            ->save();

        return $offer;
    }

    private function latestQuote(PwCompetitorOffer $offer): PwPriceQuote
    {
        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->findOne();
        self::assertNotNull($quote);

        return $quote;
    }

    /**
     * A real picture in the catalogue's upload folder: the demo catalogue's
     * are not there on every install.
     */
    private function pictureOnDisk(): string
    {
        $file = 'pw-test-'.$this->tag.'.jpg';
        $path = (new ProductImage())->getUploadDir().\DIRECTORY_SEPARATOR.$file;
        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        self::assertTrue(imagejpeg($image, $path));
        $this->pictures[] = $path;

        return $file;
    }
}
