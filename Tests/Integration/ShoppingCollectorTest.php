<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\IdentityFingerprint;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Source\CardRelevance;
use PriceWatch\Service\Source\DataForSeoClient;
use PriceWatch\Service\Source\OurPrice;
use PriceWatch\Service\Source\ShoppingCollector;
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

    public function testAnUnrelatedCardIsNotWorthASellersLookup(): void
    {
        $identity = $this->identity('3660600123454', title: 'Casque Bell Custom 500 Gloss Black');

        $result = $this->collector($this->answers([$this->watched, null, null]))->collect(10, 1, 60, [], true);

        self::assertSame(1, $result['noPlausibleCard']);
        self::assertSame(1, $result['run']->getDataforseoTasks(), 'the search is paid, the sellers lookup is not');
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

    private function addVariant(PwProductIdentity $of, string $gtin): void
    {
        $saleElement = (new \Thelia\Model\ProductSaleElements())
            ->setProductId($of->getProductId())
            ->setRef('PW-VARIANT-'.bin2hex(random_bytes(4)))
            ->setQuantity(1)
            ->setIsDefault(false);
        $saleElement->save();

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($of->getProductId())
            ->setGtin(str_pad($gtin, 14, '0', \STR_PAD_LEFT))
            ->setGtinValid(1)
            ->setGtinShared(0)
            ->setMonitored(1)
            ->save();
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
    private function answers(array $sellerDomains): array
    {
        $sellers = $this->fixture('sellers-task_get_advanced');
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
    private function collector(array $answers): ShoppingCollector
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setDataforseoLogin('login@example.com');
        $settings->storeSecret(SecretName::DataforseoPassword, 'api-password');

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($answers): MockResponse {
            ++$this->calls;

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
            static function (int $seconds): void {},
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
