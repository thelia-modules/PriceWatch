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
    }

    /**
     * Plan stage E8: the gaps tab must be read through idx_pw_offer_screen,
     * filter and order alike, without a sort pass, at a realistic volume.
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

    private function offer(Product $product, string $state, bool $comparable, string $deltaPct, ?string $exclusion = null, ?PwCompetitor $competitor = null): PwCompetitorOffer
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        if (PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() === 0) {
            (new PwProductIdentity())
                ->setProductSaleElementsId($saleElement->getId())
                ->setProductId($product->getId())
                ->setSizeLabel('M')
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
            ->setLastPrice('90.00')
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
