<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\JsonLdExtractor;
use PriceWatch\Service\Direct\NuxtStateExtractor;
use PriceWatch\Service\Direct\PageOutcome;
use PriceWatch\Service\Direct\PageReader;
use PriceWatch\Service\Direct\PageVariant;
use PriceWatch\Service\Normalizer\GtinNormalizer;

/**
 * Pages recorded live on 2026-10-12, trimmed to their structured data.
 */
final class PageReaderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/direct/';
    private const DAFY = 'https://www.dafy-moto.com/casque-sz-r-vas-evo-arai-noir-mat.html';
    private const MOTOBLOUZ = 'https://www.motoblouz.com/vente-casque-jet-arai-sz-r-vas-evo-solid-271011.html';

    public function testDafyGivesEverySizeWithItsGtinFromJsonLd(): void
    {
        $reading = $this->reader()->read($this->fixture('dafy-arai-sz-r-vas-evo-noir-mat.html'), self::DAFY);

        self::assertSame(PageOutcome::Read, $reading->outcome);
        self::assertSame('jsonld', $reading->page?->extractor);
        self::assertSame('EUR', $reading->page->currency);
        self::assertSame(
            [['XS', '04530935639908', '638.70'], ['S', '04530935639915', '638.70'], ['M', '04530935639922', '638.70'], ['L', '04530935639939', '638.70'], ['XL', '04530935639946', '638.70']],
            array_map(static fn (PageVariant $variant): array => [$variant->size, $variant->gtin, $variant->price], $reading->page->variants),
            'the sku carries the EAN-13: a valid GTIN wherever it is written',
        );
        self::assertSame('Noir Mat', $reading->page->variants[0]->colour);
        self::assertTrue($reading->page->variants[0]->inStock);
    }

    public function testMotoblouzGivesTheSellingPriceOfEachSizeOfThePageProductOnly(): void
    {
        $reading = $this->reader()->read($this->fixture('motoblouz-arai-sz-r-vas-evo-solid.html'), self::MOTOBLOUZ);

        self::assertSame(PageOutcome::Read, $reading->outcome);
        self::assertSame('nuxt', $reading->page?->extractor);
        self::assertSame('EUR', $reading->page->currency, 'from the page\'s JSON-LD: the state carries none');
        self::assertCount(5, $reading->page->variants, 'the 32 recommended products of the state are not this page\'s');
        self::assertSame(['XS', 'S', 'M', 'L', 'XL'], array_map(static fn (PageVariant $variant): ?string => $variant->size, $reading->page->variants));
        self::assertSame(['638.70'], array_values(array_unique(array_map(static fn (PageVariant $variant): string => $variant->price, $reading->page->variants))), 'the selling price, never the loyalty one');
        self::assertSame('799.95', $reading->page->variants[0]->listPrice, 'the struck-through price Google showed as the price');
        self::assertNull($reading->page->variants[0]->gtin, 'a 10-digit maker code is no GTIN');
        self::assertSame('Matte Black', $reading->page->variants[0]->colour);
    }

    public function testStructuredDataThatDisagreeOnThePriceAreNotRead(): void
    {
        $html = str_replace('"price":638.7', '"price":600', $this->fixture('motoblouz-arai-sz-r-vas-evo-solid.html'));

        $reading = $this->reader()->read($html, self::MOTOBLOUZ);

        self::assertSame(PageOutcome::Contradictory, $reading->outcome);
        self::assertNull($reading->page);
    }

    public function testAPriceWithoutCurrencyIsNotRead(): void
    {
        $html = str_replace('"priceCurrency":"EUR",', '', $this->fixture('dafy-arai-sz-r-vas-evo-noir-mat.html'));

        self::assertSame(PageOutcome::NoCurrency, $this->reader()->read($html, self::DAFY)->outcome);
    }

    public function testAPriceInAnotherCurrencyIsNotRead(): void
    {
        $html = str_replace('"priceCurrency":"EUR"', '"priceCurrency":"GBP"', $this->fixture('dafy-arai-sz-r-vas-evo-noir-mat.html'));

        self::assertSame(PageOutcome::NoCurrency, $this->reader()->read($html, self::DAFY)->outcome);
    }

    /**
     * A product taken off a site often redirects to another one: data that
     * names another page than the one asked for is never read.
     */
    public function testTheDataOfAnotherPageIsNotRead(): void
    {
        $reading = $this->reader()->read($this->fixture('motoblouz-arai-sz-r-vas-evo-solid.html'), 'https://www.motoblouz.com/vente-autre-produit-1.html');

        self::assertSame(PageOutcome::NoProduct, $reading->outcome);
    }

    public function testTheQueryStringDoesNotMakeAnotherPage(): void
    {
        $reading = $this->reader()->read($this->fixture('dafy-arai-sz-r-vas-evo-noir-mat.html'), self::DAFY.'?attr2=86&utm_source=google-shopping');

        self::assertSame(PageOutcome::Read, $reading->outcome);
    }

    public function testAPageWithoutProductData(): void
    {
        self::assertSame(PageOutcome::NoProduct, $this->reader()->read('<html><body>Rupture</body></html>', self::DAFY)->outcome);
    }

    public function testTwoProductsOnAPageWithoutTheOneOfThePage(): void
    {
        $html = '<script type="application/ld+json">[{"@type":"Product","name":"A","url":"https://shop.example/a","offers":{"@type":"Offer","price":"10","priceCurrency":"EUR"}},'
            .'{"@type":"Product","name":"B","url":"https://shop.example/b","offers":{"@type":"Offer","price":"12","priceCurrency":"EUR"}}]</script>';

        self::assertSame(PageOutcome::NoProduct, $this->reader()->read($html, 'https://shop.example/c')->outcome, 'which one is the page\'s cannot be told');
        self::assertSame('12.00', $this->reader()->read($html, 'https://shop.example/b?utm_source=x')->page?->variants[0]->price);
    }

    public function testAPriceRangeIsNoPrice(): void
    {
        $html = '<script type="application/ld+json">{"@type":"Product","name":"A","offers":{"@type":"AggregateOffer","lowPrice":"10","highPrice":"20","priceCurrency":"EUR"}}</script>';

        self::assertSame(PageOutcome::NoProduct, $this->reader()->read($html, 'https://shop.example/a')->outcome);
    }

    public function testOneSizeAtTwoPricesIsNotRead(): void
    {
        $html = '<script type="application/ld+json">{"@type":"ProductGroup","name":"A","hasVariant":['
            .'{"@type":"Product","size":"M","offers":{"@type":"Offer","price":"10","priceCurrency":"EUR"}},'
            .'{"@type":"Product","size":"M","offers":{"@type":"Offer","price":"12","priceCurrency":"EUR"}},'
            .'{"@type":"Product","size":"L","offers":{"@type":"Offer","price":"12","priceCurrency":"EUR"}}]}</script>';

        $reading = $this->reader()->read($html, 'https://shop.example/a');

        self::assertSame(['L'], array_map(static fn (PageVariant $variant): ?string => $variant->size, $reading->page === null ? [] : $reading->page->variants), 'M is dropped, L stays');
    }

    private function reader(): PageReader
    {
        $gtin = new GtinNormalizer();

        return new PageReader(new NuxtStateExtractor($gtin), new JsonLdExtractor($gtin));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }
}
