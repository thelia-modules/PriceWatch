<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRunQuery;
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
use PriceWatch\Service\Run\PassStep;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Model\ProductSaleElementsQuery;

final class OfferJudgeTest extends PriceWatchIntegrationTestCase
{
    private int $calls = 0;
    private ?\Throwable $failWith = null;

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

        $judgment = json_decode((string) $offer->getJudgmentJson(), true);
        self::assertEqualsWithDelta(0.94, $judgment[OfferQuestions::SAME_PRODUCT]['value'], 1e-9, 'the answers stay on the offer as proof');
        self::assertSame('yes', $judgment[OfferQuestions::SAME_VARIANT]['band']);
        self::assertSame('comparable', $judgment[OfferQuestions::PRICE_COMPARABILITY]['value']);
    }

    public function testThePassReportsHowManyOffersItChecked(): void
    {
        $this->offer(CompetitorStatus::Watched, '22.00', '21.90');

        $run = $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 1.0);

        self::assertSame(PassStep::Judging->value, $run->getProgressStep());
        self::assertGreaterThanOrEqual(1, $run->getProgressTotal());
        self::assertSame($run->getProgressTotal(), $run->getProgressDone(), 'every offer checked');
    }

    public function testAPassStoppedByAnUnexpectedErrorIsClosedAsFailed(): void
    {
        $this->offer(CompetitorStatus::Watched, '22.00', '21.90');
        $judge = $this->judge([]);
        $this->failWith = new \LogicException('Unexpected');

        try {
            $judge->judgePending(50, 1.0);
            self::fail('the error reaches the command');
        } catch (\LogicException) {
        }

        $run = PwCollectRunQuery::create()->filterByPassName(OfferJudge::PASS)->orderById(Criteria::DESC)->findOne();
        self::assertNotNull($run);
        self::assertSame('failed', $run->getStatus(), 'never left "started"');
        self::assertStringContainsString('Stopped by an error: Unexpected', (string) $run->getErrorsJson());
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

    /**
     * Asked 2026-10-12: a price far from ours used to be rejected blind. It
     * is checked: another article is rejected with Jev's proof.
     */
    public function testAFarPriceOfAnotherArticleIsRejectedOnJevsWord(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '161.30', '329.99');

        $this->judge([$this->jev(0.05, 0.10, 'comparable', 0.90)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame(1, $this->calls, 'checked, not rejected blind');
        self::assertSame('rejected', $offer->getMatchState());
        self::assertSame('PRODUCT_MISMATCH', $offer->getMatchReason());
    }

    public function testAFarPriceOfTheSameArticleGoesToAHuman(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '419.00', '89.00');

        $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('needs_review', $offer->getMatchState(), 'the same article at a fifth of our price: a human looks');
        self::assertSame('PRICE_GAP_IMPLAUSIBLE', $offer->getMatchReason());
        self::assertSame(0, $offer->getLastComparable());
    }

    /**
     * Seen 2026-10-12: a Bell Moto 3 matched by Jev at +33 %, black against
     * matt black, counted in the gap.
     */
    public function testAJevMatchAtASuspectGapWaitsForAHuman(): void
    {
        $offer = $this->offer(CompetitorStatus::Watched, '224.99', '299.99');

        $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90)])->judgePending(50, 1.0);

        $offer->reload();
        self::assertSame('needs_review', $offer->getMatchState());
        self::assertSame('PRICE_GAP_SUSPECT', $offer->getMatchReason());
        self::assertSame(0, $offer->getLastComparable());
        self::assertSame(0, $this->latestQuote($offer)->getComparable());
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

    /**
     * Seen 2026-10-12: six sizes of a Bell helmet at iCasque, a listing that
     * names no size, judged six times to the same "product unclear".
     */
    public function testAProductVerdictIsGivenOnceForSizesThatShareTheEvidence(): void
    {
        $offers = $this->sizes(['XS', 'S', 'M'], 'Bell Custom 500 RSD Black Gold Casques Jet');

        $run = $this->judge([$this->jev(0.58, 0.08, 'comparable', 0.90)])->judgePending(50, 1.0);

        self::assertSame(1, $this->calls, 'whether it is the same article does not depend on our size');
        self::assertSame(1, $run->getJevRequests());

        foreach ($offers as $offer) {
            $offer->reload();
            self::assertSame('needs_review', $offer->getMatchState());
            self::assertSame('PRODUCT_UNCLEAR', $offer->getMatchReason());
        }
    }

    public function testASizeVerdictIsNeverSpreadToAnotherSize(): void
    {
        [$small, $medium] = $this->sizes(['S', 'M'], 'Casque jet taille S');

        $this->judge([$this->jev(0.94, 0.96, 'comparable', 0.90), $this->jev(0.94, 0.04, 'comparable', 0.90)])->judgePending(50, 1.0);

        self::assertSame(2, $this->calls, 'a match for S says nothing of M');
        $small->reload();
        $medium->reload();
        self::assertSame('matched_judged', $small->getMatchState());
        self::assertSame('rejected', $medium->getMatchState());
    }

    // ------------------------------------------------------------------

    /**
     * Offers of one competitor for several sizes of one product, read on the
     * same listing at the same price.
     *
     * @param list<string> $sizes
     *
     * @return list<PwCompetitorOffer>
     */
    private function sizes(array $sizes, string $remoteVariant): array
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle('Casque Bell Custom 500 Crate Stone')->save();
        $competitor = (new CompetitorRepository())->create('judge-'.bin2hex(random_bytes(5)).'.example', CompetitorStatus::Watched, 'manual');
        $offers = [];

        foreach ($sizes as $index => $size) {
            $saleElement = $index === 0 ? ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne() : (new \Thelia\Model\ProductSaleElements())->setProductId($product->getId())->setRef('PW-J-'.bin2hex(random_bytes(4)))->setQuantity(1)->setIsDefault(false);
            self::assertNotNull($saleElement);
            $saleElement->save();
            (new PwProductIdentity())->setProductSaleElementsId($saleElement->getId())->setProductId($product->getId())->setBrandLabel('Bell')->setSizeLabel($size)->save();

            $offer = (new PwCompetitorOffer())
                ->setCompetitorId($competitor->getId())
                ->setProductSaleElementsId($saleElement->getId())
                ->setOfferKey('key-'.bin2hex(random_bytes(5)))
                ->setRemoteTitle('Bell Custom 500')
                ->setRemoteVariantLabel($remoteVariant)
                ->setIdentityFingerprint(sha1('remote identity'))
                ->setMatchState('candidate')
                ->setActive(1);
            $offer->save();
            (new PwPriceQuote())->setOfferId($offer->getId())->setCollectedDay(new \DateTime())->setSource('test')->setCollectedAt(new \DateTime())
                ->setPrice('185.00')->setCurrencyCode('EUR')->setOurPrice('157.69')->save();
            $offers[] = $offer;
        }

        return $offers;
    }

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

            if ($this->failWith !== null) {
                throw $this->failWith;
            }

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
