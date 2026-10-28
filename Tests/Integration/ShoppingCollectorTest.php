<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCollectRunQuery;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Ai\ExternalSpend;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\DirectCollector;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\IdentityFingerprint;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Run\PassItems;
use PriceWatch\Service\Run\PassStep;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Source\CardRelevance;
use PriceWatch\Service\Source\DataForSeoClient;
use PriceWatch\Service\Source\DataForSeoException;
use PriceWatch\Service\Source\OurPrice;
use PriceWatch\Service\Source\PageCoverage;
use PriceWatch\Service\Source\ShoppingCollector;
use PriceWatch\Service\Source\ShownSize;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use PriceWatch\Tests\Doubles\RecordingCostRecorder;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactory;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Replays answers recorded on the sandbox (Tests/Fixtures/dataforseo). The
 * sandbox leaves every seller domain empty, so the test fills in domains on
 * the recorded structure to play whitelisted and unknown merchants.
 */
final class ShoppingCollectorTest extends PriceWatchIntegrationTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/dataforseo/';

    private int $calls = 0;
    private bool $isolated = false;
    private string $watched;
    private string $proposed;

    protected function setUp(): void
    {
        parent::setUp();

        $tag = bin2hex(random_bytes(4));
        $this->watched = 'watched-'.$tag.'.example';
        $this->proposed = 'undecided-'.$tag.'.example';

        // Isolation from the real lists in this database: only this test's
        // competitor is whitelisted while the test runs (rolled back after).
        \PriceWatch\Model\PwCompetitorQuery::create()
            ->filterByStatus(CompetitorStatus::Watched->value)
            ->update(['Status' => CompetitorStatus::Proposed->value]);

        $repository = new CompetitorRepository();
        $repository->create($this->watched, CompetitorStatus::Watched, 'manual');
        $repository->create($this->proposed, CompetitorStatus::Proposed, 'manual');
    }

    public function testWhitelistedSellerIsRecordedAsACandidateWithOurPriceAlongside(): void
    {
        $identity = $this->identity('3660600123454');

        $result = $this->collector($this->answers([$this->watched, $this->proposed, null]))->collect(10, 1, 60, [], true);

        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer, 'the whitelisted seller is stored');
        self::assertSame('candidate', $offer->getMatchState(), 'stored, not trusted: pricewatch:judge decides');
        self::assertSame('Apple iPhone 18 Pro', $offer->getRemoteTitle(), 'the card title is what Jev will compare');
        self::assertEqualsWithDelta(124.99, (float) $offer->getLastPrice(), 0.001);

        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->findOne();
        self::assertNotNull($quote);
        // The factory prices its product 10.00 excluding tax, under the first
        // tax rule of the database: French VAT at 20 % on this install.
        self::assertEqualsWithDelta(12.0, (float) $quote->getOurPrice(), 0.001, 'our price incl. tax travels with the reading');
        self::assertSame(0, $quote->getComparable(), 'nothing is comparable before judgment');
        self::assertEqualsWithDelta(124.99 - (float) $quote->getOurPrice(), (float) $quote->getDelta(), 0.01);

        self::assertSame(1, $result['run']->getQuotesWritten());
        self::assertSame(3, $result['run']->getOffersSeen());
    }

    public function testSellersOutsideTheWhitelistAreCountedNeverStored(): void
    {
        $identity = $this->identity('3660600123454');

        $result = $this->collector($this->answers([$this->proposed, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->count());
        self::assertArrayHasKey($this->proposed, $result['unknownSellers']);
        self::assertArrayHasKey('eBay', $result['unknownSellers'], 'without a domain, the seller name is reported');
    }

    public function testSecondPassTheSameDayDuplicatesNothing(): void
    {
        $this->identity('3660600123454');

        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);
        $second = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $second['run']->getQuotesWritten());
    }

    public function testAFinishedPassHasRecordedEveryCardItRead(): void
    {
        $identity = $this->identity('3660600123454');

        $run = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true)['run'];

        self::assertSame(PassStep::Recording->value, $run->getProgressStep(), 'the last step of a collect pass');
        self::assertSame([1, 1], [$run->getProgressDone(), $run->getProgressTotal()]);
        self::assertSame([PassItems::DONE, 1], $this->item($run, (int) $identity->getProductId()), 'listed as done, with its one whitelisted offer');
    }

    /**
     * Seen 2026-10-20: with the balance at zero DataForSEO refused all ten
     * searches (40200) and the pass listed ten products Google did not know,
     * then sent them to the back of the queue.
     */
    public function testASearchDataForSeoRefusedIsAnErrorAndKeepsItsPlaceInTheQueue(): void
    {
        $identity = $this->identity('3660600123454');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_post']['tasks'][0]['status_code'] = 40200;
        $answers['products/task_post']['tasks'][0]['status_message'] = 'Payment Required.';

        $run = $this->collector($answers)->collect(10, 1, 60, [], true)['run'];

        self::assertSame(PassItems::ERROR, $this->item($run, (int) $identity->getProductId())[0], 'never searched: not a product Google does not know');
        self::assertSame('partial', $run->getStatus());
        self::assertStringContainsString('no funds left', (string) $run->getErrorsJson());
        self::assertNull(\PriceWatch\Model\PwProductIdentityQuery::create()->findPk($identity->getId())?->getLastCollectedAt(), 'neither paid nor searched: first in line next time');
    }

    /**
     * Observed live (2026-10-09): a search that ends in error (40101) never
     * shows in tasks_ready, and the pass waited for it until the deadline.
     * Read directly after a while, it stops being waited for.
     */
    public function testASearchThatFailedIsNotWaitedForUntilTheDeadline(): void
    {
        $identity = $this->identity('3660600123454');
        $clock = 1_000_000;
        $seen = [];
        $failed = $this->fixture('products-task_get_advanced');
        $failed['tasks'][0]['status_code'] = 40101;
        $failed['tasks'][0]['status_message'] = 'Internal SE Server Error.';
        $failed['tasks'][0]['result'] = null;

        $result = $this->collector(
            [
                'products/task_post' => $this->fixture('products-task_post'),
                'products/tasks_ready' => ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => []]]],
                'products/task_get' => $failed,
            ],
            function (int $seconds) use (&$clock, &$seen, $identity): void {
                $clock += $seconds;
                // What the back office would read while the pass waits.
                $row = PwCollectRunQuery::create()->filterByPassName(ShoppingCollector::PASS)->filterByStatus('started')->orderById(Criteria::DESC)->findOne();
                $seen[] = [$row?->getProgressStep(), $row?->getProgressDone(), $row?->getProgressTotal(), $row === null ? null : $this->item($row, (int) $identity->getProductId())[0]];
            },
            static function () use (&$clock): int {
                return $clock;
            },
        )->collect(1, 2, 600);

        self::assertLessThanOrEqual(1_000_000 + 2 * 60, $clock, 'given up after the probe, not after the 600 s deadline');
        self::assertSame('partial', $result['run']->getStatus());
        self::assertStringContainsString('Internal SE Server Error', (string) $result['run']->getErrorsJson());
        self::assertContains([PassStep::TitleSearch->value, 0, 1, PassItems::TITLE], $seen, 'the wait shows as the title search, 0 of 1 answered, the product being searched by title');
        self::assertSame([PassItems::ERROR, 0], $this->item($result['run'], (int) $identity->getProductId()), 'a failed search is no unknown product');
    }

    /**
     * Observed live (2026-10-12): tasks_ready listed none of the title or
     * sellers tasks, and each step ended at the first direct read, 30 s in.
     */
    public function testATaskTasksReadyNeverListsIsReadWithinSeconds(): void
    {
        $this->identity('3660600123454');
        $clock = 1_000_000;
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/tasks_ready'] = ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => []]]];
        $answers['sellers/tasks_ready'] = $answers['products/tasks_ready'];

        $this->collector(
            $answers,
            static function (int $seconds) use (&$clock): void {
                $clock += $seconds;
            },
            static function () use (&$clock): int {
                return $clock;
            },
        )->collect(1, 2, 600);

        self::assertLessThanOrEqual(1_000_000 + 2 * 10, $clock, 'the search and the sellers lookup each read within 10 s');
    }

    public function testTheProductsAreListedBeforeTheFirstSearchLeaves(): void
    {
        $identity = $this->identity('3660600123454');
        $atPost = null;

        $this->collector($this->answers([$this->watched, null, null]), onRequest: function (string $url) use (&$atPost, $identity): void {
            if ($atPost === null && str_contains($url, 'products/task_post')) {
                $row = PwCollectRunQuery::create()->filterByPassName(ShoppingCollector::PASS)->filterByStatus('started')->orderById(Criteria::DESC)->findOne();
                $atPost = $row === null ? null : $this->item($row, (int) $identity->getProductId())[0];
            }
        })->collect(10, 1, 60, [], true);

        self::assertSame(PassItems::TITLE, $atPost, 'the list shows while Google is being asked, not after');
    }

    public function testASearchWithNoResultIsNoError(): void
    {
        $identity = $this->identity('3660600123454');
        $empty = $this->fixture('products-task_get_advanced');
        $empty['tasks'][0]['status_code'] = 40102;
        $empty['tasks'][0]['status_message'] = 'No Search Results.';
        $empty['tasks'][0]['result'] = null;

        $result = $this->collector([
            'products/task_post' => $this->fixture('products-task_post'),
            'products/task_get' => $empty,
        ])->collect(10, 1, 60, [], true);

        self::assertSame('success', $result['run']->getStatus(), 'Google found nothing: an answer, not a failure');
        self::assertNull($result['run']->getErrorsJson());
        self::assertSame([PassItems::NO_CARD, 0], $this->item($result['run'], (int) $identity->getProductId()));
    }

    public function testAPassStoppedByAnErrorIsClosedAsFailed(): void
    {
        $this->identity('3660600123454');

        try {
            // Nothing answers: posting the searches fails.
            $this->collector([])->collect(10, 1, 60, [], true);
            self::fail('the error reaches the command');
        } catch (DataForSeoException) {
        }

        $run = PwCollectRunQuery::create()->filterByPassName(ShoppingCollector::PASS)->orderById(Criteria::DESC)->findOne();
        self::assertNotNull($run);
        self::assertSame('failed', $run->getStatus(), 'never left "started"');
        self::assertNotNull($run->getFinishedAt());
        self::assertStringContainsString('Stopped by an error', (string) $run->getErrorsJson());
    }

    public function testOneFailedSearchDoesNotSinkThePass(): void
    {
        $this->identity('3660600123454');
        $failed = $this->fixture('products-task_get_advanced');
        $failed['tasks'][0]['status_code'] = 40101;
        $failed['tasks'][0]['status_message'] = 'Internal SE Server Error.';
        $failed['tasks'][0]['result'] = null;

        $result = $this->collector([
            'products/task_post' => $this->fixture('products-task_post'),
            'products/task_get' => $failed,
        ])->collect(10, 1, 60, [], true);

        self::assertSame('partial', $result['run']->getStatus());
        self::assertStringContainsString('Internal SE Server Error', (string) $result['run']->getErrorsJson());
    }

    /**
     * A failed search is no product Google does not know: an error, and a
     * later pass searches the title again.
     */
    public function testAFailedTitleSearchIsAnError(): void
    {
        $identity = $this->identity('3660600123454');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_get:title'] = $answers['products/task_get'];
        $answers['products/task_get:title']['tasks'][0]['status_code'] = 40101;
        $answers['products/task_get:title']['tasks'][0]['status_message'] = 'Internal SE Server Error.';
        $answers['products/task_get:title']['tasks'][0]['result'] = null;

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getDataforseoTasks(), 'the title search only');
        self::assertSame('partial', $result['run']->getStatus());
        self::assertSame([PassItems::ERROR, 0], $this->item($result['run'], (int) $identity->getProductId()));
    }

    public function testAVariantReadOnTheCompetitorsOwnPageIsNotRecordedFromGoogle(): void
    {
        $identity = $this->identity('3660600123454');
        $competitor = \PriceWatch\Model\PwCompetitorQuery::create()->findOneByDomain($this->watched);
        self::assertNotNull($competitor);
        (new \PriceWatch\Model\PwCompetitorOffer())
            ->setCompetitorId($competitor->getId())
            ->setProductSaleElementsId($identity->getProductSaleElementsId())
            ->setOfferKey(DirectCollector::OFFER_KEY_PREFIX.$identity->getProductSaleElementsId())
            ->setMatchState('matched_exact')
            ->setActive(1)
            ->save();

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByOfferKey('shopping:'.$identity->getProductSaleElementsId())->count(), 'the proven reading is not doubled by Google\'s');
        self::assertSame(0, $result['run']->getQuotesWritten());
    }

    /**
     * Seen live (2026-10-12): a Stormer History led to a Stormer Ride Path
     * card, which reached the review list at 21 %.
     */
    public function testACardSharingOnlyOurBrandIsNotWorthASellersLookup(): void
    {
        $identity = $this->identity('3660600123454', title: 'Casque Apple Vision');
        $identity->setBrandLabel('Apple')->save();

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertArrayHasKey('Casque Apple Vision', $result['noPlausibleCard'], 'the card is "Apple iPhone 18 Pro": only the brand and a generic word are shared');
        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->count());
    }

    public function testAnUnrelatedCardIsNotWorthASellersLookup(): void
    {
        $identity = $this->identity('3660600123454', title: 'Casque Bell Custom 500 Gloss Black');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(['Casque Bell Custom 500 Gloss Black'], array_keys($result['noPlausibleCard']));
        self::assertContains('title: Apple iPhone 18 Pro', $result['noPlausibleCard']['Casque Bell Custom 500 Gloss Black'], 'what Google returned is shown');
        self::assertEmpty(array_filter($result['noPlausibleCard']['Casque Bell Custom 500 Gloss Black'], static fn (string $line): bool => !str_starts_with($line, 'title: ')), 'no GTIN search to report');
        self::assertSame(1, $result['run']->getDataforseoTasks(), 'the search is paid, the sellers lookup is not');
        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->count());
    }

    /**
     * Measured live (2026-10-09 to 12): 2 GTIN searches out of 91+ led to a
     * card of ours, 18 ended in 40101 after about three minutes, billed all
     * the same. Asked 2026-10-12: no GTIN search at all.
     */
    public function testATitleThatLeadsNowhereIsNeverFollowedByAGtinSearch(): void
    {
        $identity = $this->identity('3660600123454');
        $answers = $this->answers([$this->watched, null, null]);
        // The GTIN would have found the card: it is not asked.
        $answers['products/task_get:title'] = $this->unrelatedCards();

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getDataforseoTasks(), 'the title search only');
        self::assertSame([PassItems::NO_CARD, 0], $this->item($result['run'], (int) $identity->getProductId()));
        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->count());
    }

    public function testVariantsLedToTheSameCardShareOneSellersLookup(): void
    {
        $small = $this->identity('3660600123454');
        $large = $this->identity('3660600123461');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(3, $result['run']->getDataforseoTasks(), 'two title searches, one sellers lookup');
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($small->getProductSaleElementsId())->count());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($large->getProductSaleElementsId())->count(), 'the card\'s sellers reach every variant led to it');
    }

    public function testAProductIsSearchedOnceByTitleForAllItsSizes(): void
    {
        $small = $this->identity('3660600123454');
        $this->addVariant($small, '3660600123461');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_get:title'] = $answers['products/task_get'];
        $answers['products/task_get'] = $this->unrelatedCards();

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame([], $result['noPlausibleCard']);
        self::assertSame(2, $result['run']->getDataforseoTasks(), 'one title search for the product, one sellers lookup');
        self::assertSame(2, $result['run']->getQuotesWritten(), 'both variants reached through the title');

        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($small->getProductSaleElementsId())->findOne();
        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer?->getId())->findOne();
        self::assertSame('title', json_decode((string) $quote?->getRawPayloadJson(), true)['searched_by'] ?? null, 'how the card was found stays on the reading');
    }

    /**
     * Observed live (2026-10-12): a card lists its sellers for one size, named
     * in the offer's details, and recording it against every variant had Jev
     * reject four sizes out of five.
     */
    public function testAnOfferOnlyReachesTheVariantsOfTheSizeItsCardShows(): void
    {
        $small = $this->identity('3660600123454');
        $small->setSizeLabel('S')->save();
        $medium = $this->addVariant($small, '3660600123461', 'M');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['sellers/task_get']['tasks'][0]['result'][0]['items'][0]['details'] = 'Casque SZ-R Vas Evo Arai Noir Mat M - casque jet moto Arai Homme ou Femme';

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($small->getProductSaleElementsId())->count(), 'the card shows M: nothing to judge for S');
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($medium->getProductSaleElementsId())->count());
        self::assertSame(1, $result['run']->getQuotesWritten());
    }

    /**
     * Asked 2026-10-12: every size of a helmet sold at the same price is one
     * comparison, not five searches and five checks.
     */
    public function testOneSearchServesEverySizeWeSellAtTheSamePrice(): void
    {
        $small = $this->identity('3660600123454');
        $small->setSizeLabel('S')->save();
        $medium = $this->addVariant($small, '3660600123461', 'M', pricedLike: true);
        $answers = $this->answers([$this->watched, null, null]);
        $answers['sellers/task_get']['tasks'][0]['result'][0]['items'][0]['details'] = 'Casque SZ-R Vas Evo Arai Noir Mat M - casque jet moto';

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame(2, $result['run']->getDataforseoTasks(), 'one title search for the product, one sellers lookup');
        self::assertSame(1, $result['run']->getTargetsPlanned());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($medium->getProductSaleElementsId())->count(), 'the size the card shows is recorded, though it was not searched');
        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($small->getProductSaleElementsId())->count());
        $medium->reload();
        self::assertNotNull($medium->getLastCollectedAt(), 'covered by the search: to the back of the queue too');
    }

    public function testTheLimitCountsSearchesSoSameMoneyReachesMoreProducts(): void
    {
        $small = $this->identity('3660600123454');
        $this->addVariant($small, '3660600123461', 'M', pricedLike: true);
        $other = $this->identity('3660600123478');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(2, 1, 60, [], true);

        self::assertSame(2, $result['run']->getTargetsPlanned());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($other->getProductSaleElementsId())->count(), 'the second search goes to another product, not to a size the first one covers');
    }

    public function testSizesAtDifferentPricesAreEachATargetOfOneTitleSearch(): void
    {
        $small = $this->identity('3660600123454');
        $this->addVariant($small, '3660600123461', 'M', pricedLike: true, price: 20.0);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(2, $result['run']->getTargetsPlanned());
        self::assertSame(2, $result['run']->getDataforseoTasks(), 'one title search for the product, one sellers lookup');
    }

    public function testAHumanMatchThatChangesGoesBackToAHumanNotToTheMachine(): void
    {
        $identity = $this->identity('3660600123454');
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);
        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer);
        $offer->setMatchState('matched_manual')->setJudgedFingerprint(sha1('what the human saw'))->setLastComparable(1)->save();

        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        $offer->reload();
        self::assertSame('needs_review', $offer->getMatchState());
        self::assertSame('IDENTITY_DRIFT', $offer->getMatchReason());
        self::assertSame(0, $offer->getLastComparable());
    }

    public function testAnUnchangedMatchKeepsCountingInTheGap(): void
    {
        $identity = $this->identity('3660600123454');
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);
        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer);
        $offer->setMatchState('matched_judged')->setJudgedFingerprint($offer->getIdentityFingerprint())->setLastComparable(1)->save();
        // Yesterday's reading, as far as the daily uniqueness is concerned.
        PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->update(['CollectedDay' => new \DateTime('yesterday')]);

        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        $offer->reload();
        self::assertSame('matched_judged', $offer->getMatchState());
        $today = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->filterByCollectedDay(new \DateTime('today'))->findOne();
        self::assertSame(1, $today?->getComparable(), 'today\'s reading counts as yesterday\'s did');
    }

    /**
     * Asked 2026-10-13: how a competitor's price moved since the reading of
     * an earlier day, kept on the offer.
     */
    public function testAReadingKeepsHowThePriceMovedSinceAnEarlierDay(): void
    {
        $identity = $this->identity('3660600123454');
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);
        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer);
        self::assertNull($offer->getPreviousPrice(), 'first reading: nothing to compare with');
        // Yesterday the seller asked 150.00.
        PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->update(['CollectedDay' => new \DateTime('yesterday'), 'Price' => '150.000000']);

        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        $offer->reload();
        self::assertEqualsWithDelta(150.0, (float) $offer->getPreviousPrice(), 0.001, 'yesterday\'s, not this morning\'s: a second pass the same day compares with yesterday');
        self::assertSame((new \DateTime('yesterday'))->format('Y-m-d'), $offer->getPreviousPriceDay()?->format('Y-m-d'));
        self::assertEqualsWithDelta(-16.673, (float) $offer->getPriceChangePct(), 0.001, '124.99 against 150.00');
    }

    public function testTheProductPageIsKeptWhenItIsOnThisCompetitorsSite(): void
    {
        $identity = $this->identity('3660600123454');

        $this->collector($this->answers([$this->watched, null, null], 'https://www.'.$this->watched.'/casque-123.html?size=M'))->collect(10, 1, 60, [], true);

        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertSame('https://www.'.$this->watched.'/casque-123.html?size=M', $offer?->getUrl());
        $payload = json_decode((string) PwPriceQuoteQuery::create()->filterByOfferId($offer?->getId())->findOne()?->getRawPayloadJson(), true);
        self::assertStringStartsWith('https://google.com/search?ibp=oshop', (string) $payload['google_url'], 'the Google Shopping page lists every seller with a working link');
        self::assertStringStartsWith('https://encrypted-tbn', (string) $payload['card']['image']);
    }

    public function testAnotherSellersPageIsNeverTakenForThisCompetitors(): void
    {
        $identity = $this->identity('3660600123454');

        // The recorded answer's page is walmart.com: not our competitor.
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer);
        self::assertNull($offer->getUrl(), 'and the seller\'s Google redirect token is no link either');
    }

    public function testALinkFoundOnAnEarlierDayStays(): void
    {
        $identity = $this->identity('3660600123454');
        $this->collector($this->answers([$this->watched, null, null], 'https://www.'.$this->watched.'/casque-123.html'))->collect(10, 1, 60, [], true);
        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        self::assertNotNull($offer);
        PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->update(['CollectedDay' => new \DateTime('yesterday')]);

        // Today Google lists another seller first.
        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        $offer->reload();
        self::assertSame('https://www.'.$this->watched.'/casque-123.html', $offer->getUrl());
    }

    public function testSuccessivePassesRotateThroughTheReferential(): void
    {
        $first = $this->identity('3660600123454');
        $second = $this->identity('3660600123478');

        $this->collector($this->answers([$this->watched, null, null]))->collect(1, 1, 60, [], true);
        $this->collector($this->answers([$this->watched, null, null]))->collect(1, 1, 60, [], true);

        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($first->getProductSaleElementsId())->count());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($second->getProductSaleElementsId())->count(), 'the second pass takes the variant the first one left');
        $first->reload();
        self::assertNotNull($first->getLastCollectedAt(), 'searched: to the back of the queue');
    }

    public function testSamplingTakesOneVariantPerProduct(): void
    {
        $first = $this->identity('3660600123454');
        $this->addVariant($first, '3660600123461');
        $other = $this->identity('3660600123478');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(2, 1, 60, [], true, onePerProduct: true);

        self::assertSame(2, $result['run']->getTargetsPlanned());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($other->getProductSaleElementsId())->count(), 'the second product is reached despite the limit');
    }

    /**
     * Asked 2026-10-13: DataForSEO only for a product no confirmed page
     * covers, Google asked again every 90 days for new sellers.
     */
    public function testAProductWithAConfirmedPageIsLeftToTheDirectPass(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-80 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-80 days');
        // Google showed another seller without a page: one page is enough.
        $other = 'other-'.bin2hex(random_bytes(4)).'.example';
        $this->watchedSince($other, '-85 days');
        $this->shoppingOffer($identity, $other, 'candidate');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $result['run']->getDataforseoTasks(), 'nothing to ask Google');
        self::assertSame(0, $result['run']->getTargetsPlanned());
    }

    /**
     * Seen 2026-10-20: speedway.fr, martimotos.com and motocard.com were
     * watched, none readable directly, and no product a page covered was
     * put to Google, their only source.
     */
    public function testACompetitorWatchedSinceTheLastSearchPutsTheProductToGoogleAgain(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-10 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-10 days');
        $this->watchedSince('new-'.bin2hex(random_bytes(4)).'.example', '-1 day');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getTargetsPlanned(), 'Google never saw the new competitor for it');
    }

    public function testACompetitorWatchedSinceWithItsOwnConfirmedPageAsksGoogleNothing(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-10 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-10 days');
        $newcomer = 'new-'.bin2hex(random_bytes(4)).'.example';
        $this->watchedSince($newcomer, '-1 day');
        $this->confirmedPage($identity, $newcomer);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $result['run']->getTargetsPlanned(), 'its page is read directly');
    }

    public function testACoveredProductIsSearchedAgainAfterNinetyDays(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-91 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-120 days');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getTargetsPlanned(), 'for the sellers that appeared since');
    }

    public function testAProductFoundInASitemapIsNeverPutToGoogleBeforeNinetyDays(): void
    {
        $identity = $this->identity('3660600123454');
        $this->confirmedPage($identity, $this->watched, '-10 days');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $result['run']->getTargetsPlanned(), 'never searched: the clock starts with the first page found');
    }

    public function testACandidatePageSparesNoSearch(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-3 days'))->save();
        $this->confirmedPage($identity, $this->watched, status: \PriceWatch\Service\Direct\PageRepository::CANDIDATE);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getTargetsPlanned(), 'not proven yet: it covers nothing');
    }

    /**
     * Seen 2026-10-16 and 22 (Segura Mojo gloves, Bell Race Star): a
     * confirmed page that reads nothing of ours left the product unread by
     * both the direct pass and Google.
     */
    public function testAConfirmedPageThatReadsNothingSendsTheProductToGoogle(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-10 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-10 days');
        \PriceWatch\Model\PwCompetitorPageQuery::create()->filterByProductId($identity->getProductId())->update(['LastFetchedAt' => new \DateTime('-1 hour'), 'LastOutcome' => 'read_no_match']);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['run']->getTargetsPlanned(), 'a page that reads nothing covers nothing');
    }

    public function testAConfirmedPageThatReadAPriceStillCovers(): void
    {
        $identity = $this->identity('3660600123454');
        $identity->setLastCollectedAt(new \DateTime('-10 days'))->save();
        $this->confirmedPage($identity, $this->watched, '-10 days');
        \PriceWatch\Model\PwCompetitorPageQuery::create()->filterByProductId($identity->getProductId())->update(['LastFetchedAt' => new \DateTime('-1 hour'), 'LastOutcome' => \PriceWatch\Service\Direct\PageOutcome::Read->value]);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $result['run']->getTargetsPlanned());
    }

    public function testWithoutWhitelistNothingIsPaidFor(): void
    {
        \PriceWatch\Model\PwCompetitorQuery::create()->filterByDomain($this->watched)->update(['Status' => CompetitorStatus::Proposed->value]);
        $this->identity('3660600123454');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, $this->calls);
        self::assertSame('partial', $result['run']->getStatus());
    }

    public function testSharedOrInvalidGtinsAreNotSearched(): void
    {
        $shared = $this->identity('3660600123454', shared: true);
        $invalid = $this->identity('3660600123457', valid: false);

        $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId([$shared->getProductSaleElementsId(), $invalid->getProductSaleElementsId()], Criteria::IN)->count());
    }

    // ------------------------------------------------------------------

    /**
     * Only this test's identities are monitored while it runs, so the
     * collector's selection is deterministic despite the real referential.
     */
    private function identity(string $gtin, bool $shared = false, bool $valid = true, string $title = 'Apple iPhone 18 Pro reconditionné'): PwProductIdentity
    {
        // Per test, not static: the rollback of the previous test has put the
        // real identities back under monitoring.
        if (!$this->isolated) {
            \PriceWatch\Model\PwProductIdentityQuery::create()->update(['Monitored' => 0]);
            $this->isolated = true;
        }

        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title)->save();
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        $identity = (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setGtin(str_pad($gtin, 14, '0', \STR_PAD_LEFT))
            ->setGtinValid($valid ? 1 : 0)
            ->setGtinShared($shared ? 1 : 0)
            ->setMonitored(1);
        $identity->save();

        return $identity;
    }

    private function confirmedPage(PwProductIdentity $identity, string $domain, string $createdAt = 'now', string $status = \PriceWatch\Service\Direct\PageRepository::CONFIRMED): void
    {
        $competitor = \PriceWatch\Model\PwCompetitorQuery::create()->findOneByDomain($domain);
        self::assertNotNull($competitor);
        $pages = new \PriceWatch\Service\Direct\PageRepository();
        $page = $pages->add((int) $competitor->getId(), (int) $identity->getProductId(), 'https://www.'.$domain.'/casque-'.bin2hex(random_bytes(3)).'.html', 'manual');
        $pages->setStatus($page, $status, 'manual');
        \PriceWatch\Model\PwCompetitorPageQuery::create()->filterById($page->getId())->update(['CreatedAt' => new \DateTime($createdAt)]);
    }

    private function watchedSince(string $domain, string $decidedAt): void
    {
        $competitor = (new CompetitorRepository())->create($domain, CompetitorStatus::Watched, 'manual', decidedBy: 'test');
        \PriceWatch\Model\PwCompetitorQuery::create()->filterById($competitor->getId())->update(['ValidatedAt' => new \DateTime($decidedAt), 'CreatedAt' => new \DateTime($decidedAt)]);
    }

    private function shoppingOffer(PwProductIdentity $identity, string $domain, string $state): void
    {
        $competitor = \PriceWatch\Model\PwCompetitorQuery::create()->findOneByDomain($domain);
        self::assertNotNull($competitor);
        (new \PriceWatch\Model\PwCompetitorOffer())
            ->setCompetitorId($competitor->getId())
            ->setProductSaleElementsId($identity->getProductSaleElementsId())
            ->setOfferKey('shopping:'.$identity->getProductSaleElementsId())
            ->setMatchState($state)
            ->setActive(1)
            ->save();
    }

    /**
     * @param bool $pricedLike given the price row of $of (factory: 10.00 excluding tax), or $price when set
     */
    private function addVariant(PwProductIdentity $of, string $gtin, ?string $size = null, bool $pricedLike = false, ?float $price = null): PwProductIdentity
    {
        $saleElement = (new \Thelia\Model\ProductSaleElements())
            ->setProductId($of->getProductId())
            ->setRef('PW-VARIANT-'.bin2hex(random_bytes(4)))
            ->setQuantity(1)
            ->setIsDefault(false);
        $saleElement->save();

        if ($pricedLike) {
            $model = \Thelia\Model\ProductPriceQuery::create()->filterByProductSaleElementsId($of->getProductSaleElementsId())->findOne();
            self::assertNotNull($model);
            $copy = $model->copy()->setProductSaleElementsId($saleElement->getId());

            if ($price !== null) {
                $copy->setPrice(number_format($price, 6, '.', ''))->setPromoPrice(number_format($price, 6, '.', ''));
            }

            $copy->save();
        }

        $identity = (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($of->getProductId())
            ->setGtin(str_pad($gtin, 14, '0', \STR_PAD_LEFT))
            ->setGtinValid(1)
            ->setGtinShared(0)
            ->setSizeLabel($size)
            ->setMonitored(1);
        $identity->save();

        return $identity;
    }

    /**
     * The recorded search answer with every title replaced by things seen
     * live for GTINs Google did not know.
     *
     * @return array<string, mixed>
     */
    private function unrelatedCards(): array
    {
        $answer = $this->fixture('products-task_get_advanced');

        foreach ($answer['tasks'][0]['result'][0]['items'] as $index => $item) {
            $answer['tasks'][0]['result'][0]['items'][$index]['title'] = ['Kinetic Sand La Chocolaterie', 'Figurine Funko Pop! Jujutsu Kaisen', 'Pétrole liquide Ptx 2000'][$index % 3];
        }

        return $answer;
    }

    /**
     * @return array{0: ?string, 1: int} status and offers of a product, as listed under the progress bar
     */
    private function item(PwCollectRun $run, int $productId): array
    {
        foreach ((array) json_decode((string) $run->getProgressItems(), true) as $item) {
            if (($item['id'] ?? null) === $productId) {
                return [$item['status'], $item['offers']];
            }
        }

        return [null, 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES.'dataforseo-sandbox-'.$name.'.json'), true);
    }

    /**
     * Recorded answers, with the first sellers given the requested domains.
     *
     * @param list<?string> $sellerDomains
     *
     * @return array<string, array<string, mixed>>
     */
    private function answers(array $sellerDomains, ?string $pageUrl = null): array
    {
        $sellers = $this->fixture('sellers-task_get_advanced');

        if ($pageUrl !== null) {
            // The product page Google gives with the result: the first seller's.
            $sellers['tasks'][0]['result'][0]['url'] = $pageUrl;
        }

        $items = \array_slice($sellers['tasks'][0]['result'][0]['items'], 0, \count($sellerDomains));

        foreach ($sellerDomains as $index => $domain) {
            $items[$index]['domain'] = $domain;
            $items[$index]['url'] = null;
            $items[$index]['product_condition'] = null;
        }

        $sellers['tasks'][0]['result'][0]['items'] = $items;

        return [
            'products/task_post' => $this->fixture('products-task_post'),
            'products/task_get' => $this->fixture('products-task_get_advanced'),
            'sellers/task_post' => $this->fixture('sellers-task_post'),
            'sellers/task_get' => $sellers,
        ];
    }

    /**
     * Each request is answered by the recorded answer of its endpoint; the
     * task posts are re-tagged with the identities actually searched.
     *
     * @param array<string, array<string, mixed>> $answers
     */
    private function collector(array $answers, ?\Closure $sleep = null, ?\Closure $now = null, ?\Closure $onRequest = null): ShoppingCollector
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setDataforseoLogin('login@example.com');
        $settings->storeSecret(SecretName::DataforseoPassword, 'api-password');

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answers, $onRequest): MockResponse {
            ++$this->calls;

            if ($onRequest !== null) {
                $onRequest($url);
            }

            // Title searches are tagged "p<product id>", and retag() puts
            // the tag in the task id.
            if (isset($answers['products/task_get:title']) && preg_match('#/products/task_get/advanced/task-\d+-p\d+$#', $url) === 1) {
                return new MockResponse((string) json_encode($answers['products/task_get:title']));
            }

            foreach ($answers as $endpoint => $answer) {
                if (str_contains($url, '/merchant/google/'.$endpoint)) {
                    if (str_ends_with($endpoint, 'task_post')) {
                        $answer = $this->retag($answer, json_decode((string) $options['body'], true));
                    }

                    return new MockResponse((string) json_encode($answer));
                }
            }

            return new MockResponse('{"status_code": 40400, "status_message": "Not Found."}', ['http_code' => 404]);
        });

        $text = new TextNormalizer();

        return new ShoppingCollector(
            new DataForSeoClient($http, $settings, new ExternalSpend(new RecordingCostRecorder())),
            new DomainNormalizer(),
            $text,
            new IdentityFingerprint($text, new BrandNormalizer($text), new GtinNormalizer()),
            new OurPrice(new TaxCalculatorFactory()),
            new CardRelevance($text),
            new ShownSize(new SizeNormalizer($text)),
            new PageCoverage(),
            $sleep ?? static function (int $seconds): void {},
            null,
            $now,
        );
    }

    /**
     * One recorded "Task Created" entry per task actually posted, carrying
     * that task's tag, as DataForSEO echoes it.
     *
     * @param array<string, mixed>       $answer
     * @param list<array<string, mixed>> $posted
     *
     * @return array<string, mixed>
     */
    private function retag(array $answer, array $posted): array
    {
        $template = $answer['tasks'][0];
        $answer['tasks'] = [];

        foreach ($posted as $index => $task) {
            $entry = $template;
            $entry['id'] = 'task-'.$index.'-'.$task['tag'];
            $entry['data']['tag'] = $task['tag'];
            $answer['tasks'][] = $entry;
        }

        return $answer;
    }
}
