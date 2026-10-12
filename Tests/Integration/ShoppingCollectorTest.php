<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCollectRunQuery;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
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
use PriceWatch\Service\Source\ShoppingCollector;
use PriceWatch\Service\Source\ShownSize;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
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
        self::assertContains([PassStep::GtinSearch->value, 0, 1, PassItems::GTIN], $seen, 'the wait shows as the GTIN search, 0 of 1 answered, the product being searched by GTIN');
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

        self::assertSame(PassItems::GTIN, $atPost, 'the list shows while Google is being asked, not after');
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
     * Observed live (2026-10-12): pass #198 closed "partial" on four GTIN
     * searches in 40101, though the title search found both products.
     */
    public function testAFailedGtinSearchTheTitleSearchMadeUpForIsNoError(): void
    {
        $identity = $this->identity('3660600123454');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_get:title'] = $answers['products/task_get'];
        $answers['products/task_get']['tasks'][0]['status_code'] = 40101;
        $answers['products/task_get']['tasks'][0]['status_message'] = 'Internal SE Server Error.';
        $answers['products/task_get']['tasks'][0]['result'] = null;

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame('success', $result['run']->getStatus());
        self::assertNull($result['run']->getErrorsJson());
        self::assertSame(1, $result['run']->getQuotesWritten());
        self::assertSame([PassItems::DONE, 1], $this->item($result['run'], (int) $identity->getProductId()));
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

    public function testAnUnrelatedCardIsNotWorthASellersLookup(): void
    {
        $identity = $this->identity('3660600123454', title: 'Casque Bell Custom 500 Gloss Black');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(['Casque Bell Custom 500 Gloss Black'], array_keys($result['noPlausibleCard']));
        self::assertContains('GTIN: Apple iPhone 18 Pro', $result['noPlausibleCard']['Casque Bell Custom 500 Gloss Black'], 'what Google returned is shown');
        self::assertContains('title: Apple iPhone 18 Pro', $result['noPlausibleCard']['Casque Bell Custom 500 Gloss Black']);
        self::assertSame(2, $result['run']->getDataforseoTasks(), 'both searches are paid, the sellers lookup is not');
        self::assertSame(0, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->count());
    }

    public function testVariantsLedToTheSameCardShareOneSellersLookup(): void
    {
        $small = $this->identity('3660600123454');
        $large = $this->identity('3660600123461');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(3, $result['run']->getDataforseoTasks(), 'two searches, one sellers lookup');
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($small->getProductSaleElementsId())->count());
        self::assertSame(1, PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($large->getProductSaleElementsId())->count(), 'the card\'s sellers reach every variant led to it');
    }

    public function testAGtinGoogleDoesNotKnowFallsBackToOneTitleSearchPerProduct(): void
    {
        $small = $this->identity('3660600123454');
        $this->addVariant($small, '3660600123461');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_get:title'] = $answers['products/task_get'];
        $answers['products/task_get'] = $this->unrelatedCards();

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame([], $result['noPlausibleCard']);
        self::assertSame(4, $result['run']->getDataforseoTasks(), 'two GTIN searches, one title search for the product, one sellers lookup');
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

        self::assertSame(2, $result['run']->getDataforseoTasks(), 'one GTIN search for the product, one sellers lookup');
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

    public function testSizesAtDifferentPricesAreEachSearched(): void
    {
        $small = $this->identity('3660600123454');
        $this->addVariant($small, '3660600123461', 'M', pricedLike: true, price: 20.0);

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(3, $result['run']->getDataforseoTasks(), 'two GTIN searches, one sellers lookup');
    }

    public function testAKnownGtinIsNotSearchedByTitle(): void
    {
        $identity = $this->identity('3660600123454');
        $answers = $this->answers([$this->watched, null, null]);
        $answers['products/task_get:title'] = $this->unrelatedCards();

        $result = $this->collector($answers)->collect(10, 1, 60, [], true);

        self::assertSame(2, $result['run']->getDataforseoTasks(), 'one GTIN search, one sellers lookup');
        $offer = PwCompetitorOfferQuery::create()->filterByProductSaleElementsId($identity->getProductSaleElementsId())->findOne();
        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer?->getId())->findOne();
        self::assertSame('gtin', json_decode((string) $quote?->getRawPayloadJson(), true)['searched_by'] ?? null);
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
            new DataForSeoClient($http, $settings),
            new DomainNormalizer(),
            $text,
            new IdentityFingerprint($text, new BrandNormalizer($text), new GtinNormalizer()),
            new OurPrice(new TaxCalculatorFactory()),
            new CardRelevance($text),
            new ShownSize(new SizeNormalizer($text)),
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
