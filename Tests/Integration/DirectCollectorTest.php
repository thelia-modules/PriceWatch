<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorPage;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\DirectCollector;
use PriceWatch\Service\Direct\DirectMatcher;
use PriceWatch\Service\Direct\JsonLdExtractor;
use PriceWatch\Service\Direct\NuxtStateExtractor;
use PriceWatch\Service\Direct\PageReader;
use PriceWatch\Service\Direct\PageRepository;
use PriceWatch\Service\Matching\MatchDecision;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Source\OurPrice;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactory;
use Thelia\Model\Product;
use Thelia\Model\ProductPrice;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Replays the Dafy and Motoblouz pages recorded on 2026-10-12.
 */
final class DirectCollectorTest extends PriceWatchIntegrationTestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/direct/';
    private const DAFY_URL = 'https://www.dafy-moto.com/casque-sz-r-vas-evo-arai-noir-mat.html';
    private const MOTOBLOUZ_URL = 'https://www.motoblouz.com/vente-casque-jet-arai-sz-r-vas-evo-solid-271011.html';

    /** Dafy's EAN-13 of each size, as on its page and in our catalogue. */
    private const SIZES = ['XS' => '4530935639908', 'S' => '4530935639915', 'M' => '4530935639922', 'L' => '4530935639939', 'XL' => '4530935639946'];

    private PwCompetitor $competitor;
    private PageRepository $pages;
    private string $robots = '';

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's competitor is whitelisted while it runs.
        PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value)->update(['Status' => CompetitorStatus::Proposed->value]);
        $this->competitor = (new CompetitorRepository())->create('direct-'.bin2hex(random_bytes(4)).'.example', CompetitorStatus::Watched, 'manual');
        $this->pages = new PageRepository();
    }

    public function testAPageWithOurGtinsIsConfirmedAndEverySizeRecordedAsAnExactMatch(): void
    {
        $identities = $this->helmet(self::SIZES);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL.'?attr2=86&utm_source=google-shopping', 'shopping');

        $result = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::CONFIRMED, $page->getStatus());
        self::assertSame('ean', $page->getConfirmedBy(), 'proven by a GTIN of ours on the page');
        self::assertSame(self::DAFY_URL.'?attr2=86', $page->getUrl(), 'the tracking parameters are dropped');

        foreach ($identities as $size => $identity) {
            $offer = $this->offer($identity);
            self::assertNotNull($offer, $size.' recorded');
            self::assertSame('matched_exact', $offer->getMatchState());
            self::assertSame('ean', $offer->getMatchMethod());
            self::assertSame(1, $offer->getLastComparable(), 'in the gap at once: no Jev call');
            self::assertEqualsWithDelta(638.70, (float) $offer->getLastPrice(), 0.001);
        }

        $quote = PwPriceQuoteQuery::create()->filterByOfferId($this->offer($identities['M'])?->getId())->findOne();
        self::assertSame('direct', $quote?->getSource());
        self::assertSame('jsonld', $quote->getExtractionMethod());
        self::assertSame('in_stock', $quote->getAvailability());
        self::assertSame(5, $result['run']->getQuotesWritten());
        self::assertSame(1, $result['run']->getPagesFetched());
        self::assertSame(['direct_pages', 1, 1], [$result['run']->getProgressStep(), $result['run']->getProgressDone(), $result['run']->getProgressTotal()], 'the progress bar sees the pages read');
    }

    public function testAPageWithoutGtinIsOnlyReadOnceAHumanConfirmedIt(): void
    {
        $identities = $this->helmet(['XS' => null, 'S' => null, 'M' => null, 'L' => null, 'XL' => null]);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'manual');

        $first = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::CANDIDATE, $page->getStatus(), 'nothing on the page proves it is our article');
        self::assertSame('read_no_match', $first['pages'][0]['outcome']);
        self::assertSame(0, $first['run']->getQuotesWritten());

        $this->pages->setStatus($page, PageRepository::CONFIRMED, 'manual');
        $second = $this->collector()->collect();

        self::assertSame(5, $second['run']->getQuotesWritten());
        $offer = $this->offer($identities['L']);
        self::assertSame(DirectMatcher::BY_SIZE, $offer?->getMatchMethod());
        self::assertEqualsWithDelta(638.70, (float) $offer->getLastPrice(), 0.001, 'the selling price, not the 799.95 Google showed');
        $quote = PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->findOne();
        self::assertEqualsWithDelta(799.95, (float) $quote?->getListPrice(), 0.001, 'kept as the struck-through price');
        self::assertSame('nuxt', $quote->getExtractionMethod());
    }

    public function testAPageListingOtherGtinsIsRejected(): void
    {
        $identities = $this->helmet(['M' => '3660600123454']);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');

        $result = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::REJECTED, $page->getStatus(), 'the page sells other GTINs: another article or colour');
        self::assertNull($this->offer($identities['M']));
        self::assertSame('foreign_gtin', $result['pages'][0]['outcome']);
    }

    public function testTheShoppingOfferOfTheSamePairStepsAside(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']]);
        $shopping = (new PwCompetitorOffer())
            ->setCompetitorId($this->competitor->getId())
            ->setProductSaleElementsId($identities['M']->getProductSaleElementsId())
            ->setOfferKey('shopping:'.$identities['M']->getProductSaleElementsId())
            ->setMatchState('matched_judged')
            ->setActive(1);
        $shopping->save();
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');

        $this->collector()->collect();

        $shopping->reload();
        self::assertSame(0, $shopping->getActive(), 'one offer per competitor and variant on the screen: the proven one');
    }

    public function testASecondPassTheSameDayDuplicatesNothing(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']]);
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');

        $this->collector()->collect();
        $second = $this->collector()->collect();

        self::assertSame(0, $second['run']->getQuotesWritten());
        self::assertSame(1, PwPriceQuoteQuery::create()->filterByOfferId($this->offer($identities['M'])?->getId())->count());
    }

    public function testAPageRobotsTxtForbidsIsNeverFetched(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']]);
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');
        $this->robots = "User-agent: *\nDisallow: /casque-";

        $result = $this->collector()->collect();

        self::assertSame('disallowed', $result['pages'][0]['outcome']);
        self::assertSame(0, $result['run']->getPagesFetched());
        self::assertNotContains(self::DAFY_URL, $this->requested);
    }

    public function testAnImplausibleGapGoesToAHuman(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']], basePrice: 100.0);
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');

        $this->collector()->collect();

        $offer = $this->offer($identities['M']);
        self::assertSame('needs_review', $offer?->getMatchState(), '638.70 against our 120.00: a data problem, not a price');
        self::assertSame(0, $offer->getLastComparable());
    }

    public function testAPageOfACompetitorNoLongerWatchedIsNotRead(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']]);
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');
        $this->competitor->setStatus(CompetitorStatus::Paused->value)->save();

        $result = $this->collector()->collect();

        self::assertSame([], $result['pages']);
        self::assertSame([], $this->requested);
    }

    public function testTheMerchantPagesGoogleLedToAreCandidatesOnce(): void
    {
        $identities = $this->helmet(['M' => self::SIZES['M']]);
        (new PwCompetitorOffer())
            ->setCompetitorId($this->competitor->getId())
            ->setProductSaleElementsId($identities['M']->getProductSaleElementsId())
            ->setOfferKey('shopping:'.$identities['M']->getProductSaleElementsId())
            ->setUrl(self::DAFY_URL.'?attr2=86&utm_source=google-shopping&srsltid=AU7gw4Uo')
            ->save();

        self::assertSame(1, $this->pages->seedFromShoppingOffers());
        self::assertSame(0, $this->pages->seedFromShoppingOffers(), 'a page already known is not added twice');

        $page = \PriceWatch\Model\PwCompetitorPageQuery::create()->filterByCompetitorId($this->competitor->getId())->findOne();
        self::assertSame($this->productId($identities), $page?->getProductId());
        self::assertSame(PageRepository::CANDIDATE, $page->getStatus(), 'Google\'s word is no proof');
        self::assertSame(self::DAFY_URL.'?attr2=86', $page->getUrl());
    }

    // ------------------------------------------------------------------

    /**
     * One of our helmets, a variant per size, priced 500.00 excluding tax
     * (600.00 to a French shopper). Only its variants are monitored.
     *
     * @param array<string, ?string> $gtinBySize
     *
     * @return array<string, PwProductIdentity>
     */
    private function helmet(array $gtinBySize, float $basePrice = 500.0): array
    {
        PwProductIdentityQuery::create()->update(['Monitored' => 0]);
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['basePrice' => $basePrice]);
        $product->setLocale('fr_FR')->setTitle('Casque Arai SZ-R VAS EVO Frost black, noir mat')->save();
        $default = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($default);
        $price = ProductPriceQuery::create()->filterByProductSaleElementsId($default->getId())->findOne();
        self::assertNotNull($price);
        $identities = [];
        $first = true;

        foreach ($gtinBySize as $size => $gtin) {
            $saleElement = $first ? $default : $this->variantOf($product, $price);
            $first = false;
            $identity = (new PwProductIdentity())
                ->setProductSaleElementsId($saleElement->getId())
                ->setProductId($product->getId())
                ->setGtin($gtin === null ? null : str_pad($gtin, 14, '0', \STR_PAD_LEFT))
                ->setGtinValid($gtin === null ? 0 : 1)
                ->setGtinShared(0)
                ->setSizeLabel($size)
                ->setMonitored(1);
            $identity->save();
            $identities[$size] = $identity;
        }

        return $identities;
    }

    private function variantOf(Product $product, ProductPrice $price): ProductSaleElements
    {
        $saleElement = (new ProductSaleElements())
            ->setProductId($product->getId())
            ->setRef('PW-DIRECT-'.bin2hex(random_bytes(4)))
            ->setQuantity(1)
            ->setIsDefault(false);
        $saleElement->save();
        $price->copy()->setProductSaleElementsId($saleElement->getId())->save();

        return $saleElement;
    }

    /**
     * @param array<string, PwProductIdentity> $identities
     */
    private function productId(array $identities): int
    {
        return (int) array_values($identities)[0]->getProductId();
    }

    private function offer(PwProductIdentity $identity): ?PwCompetitorOffer
    {
        return PwCompetitorOfferQuery::create()
            ->filterByCompetitorId($this->competitor->getId())
            ->filterByOfferKey(DirectCollector::OFFER_KEY_PREFIX.$identity->getProductSaleElementsId())
            ->findOne();
    }

    private function collector(): DirectCollector
    {
        $pages = [
            '/casque-sz-r-vas-evo-arai-noir-mat.html' => 'dafy-arai-sz-r-vas-evo-noir-mat.html',
            '/vente-casque-jet-arai-sz-r-vas-evo-solid-271011.html' => 'motoblouz-arai-sz-r-vas-evo-solid.html',
        ];
        $http = new MockHttpClient(function (string $method, string $url) use ($pages): MockResponse {
            $this->requested[] = (string) strtok($url, '?');
            $path = (string) parse_url($url, \PHP_URL_PATH);

            if ($path === '/robots.txt') {
                return new MockResponse($this->robots);
            }

            return isset($pages[$path])
                ? new MockResponse((string) file_get_contents(self::FIXTURES.$pages[$path]))
                : new MockResponse('', ['http_code' => 404]);
        });
        $gtin = new GtinNormalizer();

        return new DirectCollector(
            $http,
            new PageReader(new NuxtStateExtractor($gtin), new JsonLdExtractor($gtin)),
            new DirectMatcher(new SizeNormalizer(new TextNormalizer())),
            $this->pages,
            new OurPrice(new TaxCalculatorFactory()),
            new MatchDecision(),
            null,
            static function (int $seconds): void {},
        );
    }
}
