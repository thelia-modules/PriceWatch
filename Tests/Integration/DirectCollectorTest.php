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
use PriceWatch\Service\Direct\PageConfirmation;
use PriceWatch\Service\Direct\PageReader;
use PriceWatch\Service\Direct\SitemapDiscovery;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Direct\SitemapMatcher;
use PriceWatch\Service\Direct\PageRepository;
use PriceWatch\Service\Jev\CachedJev;
use PriceWatch\Service\Jev\JevClient;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Jev\OfferState;
use PriceWatch\Service\Matching\MatchDecision;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Source\OurPrice;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
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

    /** @var array<string, string> path => body, the sitemaps a test publishes */
    private array $sitemaps = [];
    private string $sitemapDirectory;

    /** @var list<MockResponse> what Jev answers, in order; nothing left is a failure */
    private array $jevAnswers = [];
    private int $jevCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's competitor is whitelisted while it runs.
        PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value)->update(['Status' => CompetitorStatus::Proposed->value]);
        $this->competitor = (new CompetitorRepository())->create('direct-'.bin2hex(random_bytes(4)).'.example', CompetitorStatus::Watched, 'manual');
        $this->pages = new PageRepository();
        $this->sitemapDirectory = sys_get_temp_dir().'/pw-sitemap-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sitemapDirectory.'/var/pricewatch/sitemaps/*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
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
        self::assertSame(0, $this->jevCalls, 'proven by a GTIN: Jev is not asked');
    }

    public function testAPageWithoutGtinJevIsUnsureOfWaitsForAHuman(): void
    {
        $identities = $this->helmet(['XS' => null, 'S' => null, 'M' => null, 'L' => null, 'XL' => null]);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'manual');
        $this->jevAnswers = [$this->jev(0.6, 0.9)];

        $first = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::CANDIDATE, $page->getStatus(), 'nothing on the page proves it is our article, and Jev is not sure');
        self::assertSame(1, $this->jevCalls);
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

    /**
     * Asked 2026-10-13: Jev confirms the pages a human used to, where Jev is
     * sure; their sizes are Jev matches, not proven ones.
     */
    public function testAPageWithoutGtinJevVouchesForIsReadAsJevMatches(): void
    {
        $identities = $this->helmet(['XS' => null, 'S' => null, 'M' => null, 'L' => null, 'XL' => null]);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'shopping');
        $this->jevAnswers = [$this->jev(0.95, 0.92)];

        $result = $this->collector()->collect();

        $page->reload();
        self::assertSame([PageRepository::CONFIRMED, PageConfirmation::BY], [$page->getStatus(), $page->getConfirmedBy()]);
        self::assertSame(5, $result['run']->getQuotesWritten(), 'read in the same pass');
        $offer = $this->offer($identities['L']);
        self::assertSame(['matched_judged', PageConfirmation::METHOD, 'SIZE_ON_JEV_CONFIRMED_PAGE', 1], [$offer?->getMatchState(), $offer?->getMatchMethod(), $offer?->getMatchReason(), $offer?->getLastComparable()]);
        $confidence = \PriceWatch\Service\Comparator\MatchConfidence::of($offer->getMatchMethod(), (array) json_decode((string) $offer->getJudgmentJson(), true), $offer->getMatchState());
        self::assertSame([\PriceWatch\Service\Comparator\MatchConfidence::HIGH, 95], [$confidence['level'], $confidence['score']], 'shown as Jev\'s word on the article, never as a proof');
        self::assertSame(1, $result['run']->getJevRequests());

        $this->collector()->collect();
        self::assertSame(1, $this->jevCalls, 'a confirmed page is not put to Jev again');
    }

    public function testAPageOfAnotherColourwayIsRejectedByJev(): void
    {
        $identities = $this->helmet(['M' => null]);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'shopping');
        $this->jevAnswers = [$this->jev(0.95, 0.05)];

        $result = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::REJECTED, $page->getStatus());
        self::assertSame(['jev_rejected', 'COLOURWAY_MISMATCH'], [$result['pages'][0]['outcome'], $result['pages'][0]['detail']]);
        self::assertNull($this->offer($identities['M']));
    }

    public function testJevFailingLeavesThePageACandidate(): void
    {
        $identities = $this->helmet(['M' => null]);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'shopping');

        $result = $this->collector()->collect();

        $page->reload();
        self::assertSame(PageRepository::CANDIDATE, $page->getStatus(), 'for the next pass or a human');
        self::assertSame(1, $result['run']->getJevFailed());
        self::assertSame('success', $result['run']->getStatus(), 'one page Jev could not judge does not sink the pass');
    }

    public function testAJevPageAtASuspectGapGoesToAHuman(): void
    {
        // 480.00 to a French shopper against 638.70 on the page: 33 % apart.
        $identities = $this->helmet(['L' => null], basePrice: 400.0);
        $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::MOTOBLOUZ_URL, 'shopping');
        $this->jevAnswers = [$this->jev(0.95, 0.92)];

        $this->collector()->collect();

        $offer = $this->offer($identities['L']);
        self::assertSame(['needs_review', 'PRICE_GAP_SUSPECT', 0], [$offer?->getMatchState(), $offer?->getMatchReason(), $offer?->getLastComparable()]);
    }

    /**
     * Asked 2026-10-13: the competitors' sitemaps first, DataForSEO as a last
     * resort.
     */
    public function testTheSitemapLeadsToOurPageWhichOurGtinThenProves(): void
    {
        $dafy = $this->dafyPublishingItsSitemap();
        $identities = $this->helmet(self::SIZES);

        $result = $this->collector()->collect([], null, discover: true);

        $page = \PriceWatch\Model\PwCompetitorPageQuery::create()->filterByCompetitorId($dafy->getId())->filterByProductId($this->productId($identities))->find();
        self::assertSame([self::DAFY_URL], $page->getColumnValues('Url'), 'the product page only: not the spare visor, not the advice article');
        self::assertSame([SitemapDiscovery::BY, PageRepository::CONFIRMED, 'ean'], [$page[0]->getDiscoveredBy(), $page[0]->getStatus(), $page[0]->getConfirmedBy()]);
        self::assertSame(1, $result['discovered']['added']);
        self::assertSame(5, $result['run']->getQuotesWritten(), 'found, proven and read in the same pass, without Google');
    }

    public function testAProductWithAPageAlreadyIsNotLookedUpAgain(): void
    {
        $dafy = $this->dafyPublishingItsSitemap();
        $identities = $this->helmet(self::SIZES);
        $this->pages->add((int) $dafy->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');

        $this->collector()->collect([], null, discover: true);

        self::assertNotContains('https://www.dafy-moto.com/sitemap-index.xml', $this->requested, 'nothing to find: the sitemap is not even downloaded');
    }

    public function testASitemapIsDownloadedOnceAWeek(): void
    {
        $this->dafyPublishingItsSitemap();
        $this->helmet(['M' => null]);

        $this->collector()->collect([], null, discover: true);
        $this->collector()->collect([], null, discover: true);

        self::assertCount(1, array_keys($this->requested, 'https://www.dafy-moto.com/sitemap-produits.xml', true));
    }

    public function testTheCandidatesOnlyPassLeavesTheConfirmedPagesUnread(): void
    {
        $identities = $this->helmet(self::SIZES);
        $page = $this->pages->add((int) $this->competitor->getId(), $this->productId($identities), self::DAFY_URL, 'shopping');
        $this->pages->setStatus($page, PageRepository::CONFIRMED, 'ean');

        $result = $this->collector()->collect([], null, candidatesOnly: true);

        self::assertSame(0, $result['run']->getPagesFetched(), 'read by the pass before the collect one');
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
                ->setBrandLabel('Arai')
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
     * The real Dafy competitor, watched for the test only (rolled back after),
     * with a sitemap that holds our helmet's page among others.
     */
    private function dafyPublishingItsSitemap(): PwCompetitor
    {
        $dafy = PwCompetitorQuery::create()->findOneByDomain('dafy-moto.com') ?? (new CompetitorRepository())->create('dafy-moto.com', CompetitorStatus::Watched, 'manual');
        $dafy->setStatus(CompetitorStatus::Watched->value)->save();
        // The only watched competitor: the robots.txt below answers for every host.
        $this->competitor->setStatus(CompetitorStatus::Proposed->value)->save();
        $this->robots = "User-agent: *\nDisallow: /recherche\nSitemap: https://www.dafy-moto.com/sitemap-index.xml\n";
        $this->sitemaps = [
            '/sitemap-index.xml' => '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://www.dafy-moto.com/sitemap-cms.xml</loc></sitemap><sitemap><loc>https://www.dafy-moto.com/sitemap-produits.xml</loc></sitemap></sitemapindex>',
            '/sitemap-produits.xml' => '<?xml version="1.0"?><urlset>'
                .'<url><loc>https://www.dafy-moto.com/ecran-casque-sz-r-vas-evo-arai.html</loc></url>'
                .'<url><loc>'.self::DAFY_URL.'</loc></url>'
                .'<url><loc>https://www.dafy-moto.com/casque-j-cruise-shoei-noir-mat.html</loc></url>'
                .'<url><loc>https://www.motoblouz.com/vente-casque-jet-arai-sz-r-vas-evo-solid-271011.html</loc></url>'
                .'</urlset>',
            '/sitemap-cms.xml' => '<?xml version="1.0"?><urlset><url><loc>https://www.dafy-moto.com/conseils-casque-sz-r-vas-evo-frost-arai.html</loc></url></urlset>',
        ];

        return $dafy;
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

            if (isset($this->sitemaps[$path])) {
                return new MockResponse($this->sitemaps[$path]);
            }

            return isset($pages[$path])
                ? new MockResponse((string) file_get_contents(self::FIXTURES.$pages[$path]))
                : new MockResponse('', ['http_code' => 404]);
        });
        $gtin = new GtinNormalizer();
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setJevProvider(JevProvider::Openrouter);
        $settings->storeSecret(SecretName::OpenrouterApiKey, 'sk-or-test');
        $jevHttp = new MockHttpClient(function (): MockResponse {
            ++$this->jevCalls;

            return array_shift($this->jevAnswers) ?? new MockResponse('{"error": "boom"}', ['http_code' => 500]);
        });
        $offerState = new OfferState();

        return new DirectCollector(
            $http,
            new PageReader(new NuxtStateExtractor($gtin), new JsonLdExtractor($gtin)),
            new DirectMatcher(new SizeNormalizer(new TextNormalizer())),
            $this->pages,
            new OurPrice(new TaxCalculatorFactory()),
            new MatchDecision(),
            new PageConfirmation(new CachedJev(new JevClient($jevHttp, $settings, static function (int $seconds): void {}), $offerState), $offerState, new OfferQuestions()),
            new SitemapDiscovery(new SitemapIndex($this->sitemapDirectory, new DomainNormalizer()), new SitemapMatcher(new TextNormalizer()), $this->pages),
            null,
            static function (int $seconds): void {},
        );
    }

    private function jev(float $sameProduct, float $sameColourway): MockResponse
    {
        return new MockResponse((string) json_encode([
            'answers' => [
                OfferQuestions::SAME_PRODUCT => ['noul' => $sameProduct],
                PageConfirmation::SAME_COLOURWAY => ['noul' => $sameColourway],
            ],
            'usage' => ['input_tokens' => 512],
            'model' => 'typesafe/jev-test',
        ]));
    }
}
