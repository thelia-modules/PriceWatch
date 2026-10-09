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
use PriceWatch\Service\Comparator\ComparatorFilters;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Comparator\OfferReview;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\Propel;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;

final class ComparatorTest extends PriceWatchIntegrationTestCase
{
    private ComparatorRepository $repository;
    private PwCompetitor $watched;
    private string $tag;

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

    public function testEveryOfferIsInExactlyOneTab(): void
    {
        $product = $this->product('Blouson Helstons Indy cuir noir');
        $this->offer($product, 'matched_judged', comparable: true, deltaPct: '5.000');
        $cheapest = $this->offer($product, 'matched_manual', comparable: true, deltaPct: '-12.500');
        $this->offer($product, 'matched_judged', comparable: false, deltaPct: '-40.000', exclusion: 'promotional');
        $this->offer($product, 'needs_review', comparable: false, deltaPct: '-30.000');
        $this->offer($product, 'candidate', comparable: false, deltaPct: '1.000');
        $this->offer($product, 'rejected', comparable: false, deltaPct: '-17.000');

        $filters = new ComparatorFilters();

        self::assertSame(['gaps' => 2, 'review' => 1, 'pending' => 1, 'excluded' => 2], $this->repository->counts($filters));
        $gaps = $this->repository->page($filters);
        self::assertSame($cheapest->getId(), $gaps[0]->getId(), 'the most worrying gap first: competitor cheapest');
        self::assertSame(['cheaper' => 1, 'dearer' => 1], array_slice($this->repository->gapSummary($filters), 0, 2));
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

        $query = $this->repository->pageQuery(new ComparatorFilters());
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
        self::assertStringNotContainsString('filesort', (string) $plan[0]['Extra'], 'plan: '.json_encode($plan));
    }

    // ------------------------------------------------------------------

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
}
