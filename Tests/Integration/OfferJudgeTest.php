<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwJevJudgmentQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Jev\CachedJev;
use PriceWatch\Service\Jev\JevClient;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Jev\OfferState;
use PriceWatch\Service\Matching\MatchDecision;
use PriceWatch\Service\Matching\OfferJudge;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Model\ProductSaleElementsQuery;

final class OfferJudgeTest extends PriceWatchIntegrationTestCase
{
    private int $calls = 0;

    public function testConfirmedOfferBecomesMatchedAndComparable(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '21.90');

        $run = $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('matched_judged', $offer->getMatchState());
        self::assertSame('jev', $offer->getMatchMethod());
        self::assertSame(1, $offer->getLastComparable());
        self::assertSame($offer->getIdentityFingerprint(), $offer->getJudgedFingerprint(), 'a later change of identity can be detected');
        self::assertSame(1, $this->latestQuote($offer)->getComparable());
        self::assertSame(1, $run->getOffersMatched());
        self::assertSame(1, $run->getJevRequests());
        self::assertSame(1, $this->calls);
    }

    public function testClearancePriceKeepsTheMatchButStaysOutOfTheGap(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '9.90');

        $this->judge([$this->jev(0.95, 0.97, 'promotional', 0.98)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('matched_judged', $offer->getMatchState());
        self::assertSame(0, $offer->getLastComparable());
        self::assertSame('promotional', $offer->getExclusionReason());
    }

    public function testOtherSizeIsRejected(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '21.90');

        $this->judge([$this->jev(0.93, 0.03, 'comparable', 0.54)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('rejected', $offer->getMatchState());
        self::assertSame('VARIANT_MISMATCH', $offer->getMatchReason());
        self::assertSame(0, $offer->getLastComparable());
    }

    public function testImplausibleGapIsRejectedWithoutCallingJev(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '419.00', '89.00');

        $run = $this->judge([])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('rejected', $offer->getMatchState());
        self::assertSame('code', $offer->getMatchMethod());
        self::assertSame('PRICE_GAP_IMPLAUSIBLE', $offer->getMatchReason());
        self::assertSame(0, $this->calls, 'code saw it: nothing paid');
        self::assertSame(0, $run->getJevRequests());
    }

    public function testSameFactsAreNeverPaidTwice(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '21.90');
        $judge = $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)]);

        $judge->judgePending(50, 1.0);
        $offer->reload();
        $offer->setMatchState('candidate')->save();

        $second = $judge->judgePending(50, 1.0);

        self::assertSame(1, $this->calls, 'the second pass reads the cache');
        self::assertSame(0, $second->getJevRequests());
        self::assertSame(3, $second->getJevCacheHits());
        self::assertSame(3, PwJevJudgmentQuery::create()->filterByQuestionVersion(OfferQuestions::VERSION)->filterByStateHash($this->stateHashOf($offer))->count());
    }

    public function testOffersOfNonWhitelistedCompetitorsAreNotJudged(): void
    {
        $proposed = $this->offer(CompetitorStatus::Proposed, '22.00', '21.90');
        $blacklisted = $this->offer(CompetitorStatus::Rejected, '22.00', '21.90');

        $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 1.0);

        $proposed->reload();
        $blacklisted->reload();
        self::assertSame('candidate', $proposed->getMatchState());
        self::assertSame('candidate', $blacklisted->getMatchState());
        self::assertSame(0, $this->calls);
    }

    public function testJevFailureLeavesTheOfferToBeJudgedAgain(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '21.90');

        $run = $this->judge([new MockResponse('{"error": "boom"}', ['http_code' => 500])])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('candidate', $offer->getMatchState(), 'not judged is never judged good');
        self::assertSame('partial', $run->getStatus());
    }

    public function testZeroBudgetJudgesNothing(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '22.00', '21.90');

        $run = $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 0.0);

        $offer->reload();
        self::assertSame('candidate', $offer->getMatchState());
        self::assertSame(0, $this->calls);
        self::assertSame('partial', $run->getStatus());
    }

    // ------------------------------------------------------------------

    private function offer(CompetitorStatus $status, string $ourPrice, string $theirPrice): PwCompetitorOffer
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle('Pantalon de pluie moto DG ECO 2400')->save();
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setBrandLabel('DG')
            ->setSizeLabel('XS')
            ->setVariantAxisJson('{"Taille vêtement":"XS"}')
            ->save();

        $competitor = (new CompetitorRepository())->create('judge-'.bin2hex(random_bytes(5)).'.example', $status, 'manual');

        $offer = (new PwCompetitorOffer())
            ->setCompetitorId($competitor->getId())
            ->setProductSaleElementsId($saleElement->getId())
            ->setOfferKey('key-'.bin2hex(random_bytes(5)))
            ->setRemoteTitle('Pantalon pluie moto DG ECO 2400 noir')
            ->setRemoteVariantLabel('Taille XS')
            ->setIdentityFingerprint(sha1('remote identity'))
            ->setMatchState('candidate')
            ->setActive(1);
        $offer->save();

        (new PwPriceQuote())
            ->setOfferId($offer->getId())
            ->setCollectedDay(new \DateTime())
            ->setSource('test')
            ->setCollectedAt(new \DateTime())
            ->setPrice($theirPrice)
            ->setCurrencyCode('EUR')
            ->setOurPrice($ourPrice)
            ->save();

        return $offer;
    }

    private function latestQuote(PwCompetitorOffer $offer): PwPriceQuote
    {
        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->findOne();
        self::assertNotNull($quote);

        return $quote;
    }

    private function stateHashOf(PwCompetitorOffer $offer): string
    {
        // Rebuilt the same way OfferJudge does, through one more cache read:
        // the judgment rows of this offer are the ones written for its state.
        return (string) PwJevJudgmentQuery::create()->orderById('desc')->findOne()?->getStateHash();
    }

    private function jev(float $product, float $variant, string $price, float $confidence): MockResponse
    {
        return new MockResponse((string) json_encode([
            'answers' => [
                OfferQuestions::SAME_PRODUCT => ['noul' => $product],
                OfferQuestions::SAME_VARIANT => ['noul' => $variant],
                OfferQuestions::PRICE_COMPARABILITY => ['choice' => $price, 'confidence' => $confidence],
            ],
            'usage' => ['input_tokens' => 967],
            'model' => 'typesafe/jev-test',
        ]));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function judge(array $responses): OfferJudge
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setJevProvider(JevProvider::Openrouter);
        $settings->storeSecret(SecretName::OpenrouterApiKey, 'sk-or-test');

        $http = new MockHttpClient(function () use (&$responses): MockResponse {
            ++$this->calls;

            return array_shift($responses) ?? new MockResponse('{"error": "boom"}', ['http_code' => 500]);
        });

        $offerState = new OfferState();

        return new OfferJudge(
            new CachedJev(new JevClient($http, $settings, static function (int $seconds): void {}), $offerState),
            new OfferQuestions(),
            $offerState,
            new MatchDecision(),
        );
    }
}
