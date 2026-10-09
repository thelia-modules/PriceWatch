<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Import\CatalogCsvReader;
use PriceWatch\Service\Import\CompetitorSeeder;
use PriceWatch\Service\Import\IdentityUpserter;
use PriceWatch\Service\Import\ImportOptions;
use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\ReferenceExporter;
use PriceWatch\Service\Import\ReferenceFileFormat;
use PriceWatch\Service\Import\ReferenceImporter;
use PriceWatch\Service\Import\SharedGtinFlags;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeCombination;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The acceptance test of the file format: what comes out must go back in
 * without a single refusal and without a single change. Any divergence in
 * normalisation - decimals, pair order, label casing - shows up here.
 */
final class ReferenceRoundTripTest extends PriceWatchIntegrationTestCase
{
    private ReferenceImporter $importer;
    private ReferenceExporter $exporter;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $text = new TextNormalizer();
        $gtin = new GtinNormalizer();

        $this->importer = new ReferenceImporter(
            new CatalogCsvReader(),
            new RowValidator(
                $gtin,
                new MpnNormalizer(),
                new BrandNormalizer($text),
                new SizeNormalizer($text),
                new DomainNormalizer(),
                $text,
                new PairListParser(),
            ),
            new SaleElementResolver($gtin, $text),
            new IdentityUpserter($gtin, $text),
            new CompetitorSeeder(),
            new SharedGtinFlags(),
        );
        $this->exporter = new ReferenceExporter();

        // The exporter writes the whole referential, so a round trip is only
        // deterministic from a known state. Safe: this runs inside the
        // transaction the base class rolls back after every test, so the
        // development data is untouched.
        PwProductIdentityQuery::create()->deleteAll();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testExportThenDryRunImportAttachesEveryLineAndRefusesNone(): void
    {
        $this->importRichReferential();

        $exportPath = $this->temporaryPath();
        $written = $this->exporter->write($exportPath);

        self::assertGreaterThanOrEqual(2, $written);

        $report = $this->importer->import($exportPath, new ImportOptions(dryRun: true));

        self::assertSame($written, $report->linesTotal);
        self::assertSame(0, $report->rejected, 'the format must read back everything it wrote: '.json_encode($report->rejections));
    }

    public function testExportThenRealImportReportsEverythingUnchanged(): void
    {
        $this->importRichReferential();

        $exportPath = $this->temporaryPath();
        $written = $this->exporter->write($exportPath);

        $report = $this->importer->import($exportPath, new ImportOptions());

        self::assertSame(0, $report->rejected, (string) json_encode($report->rejections));
        self::assertSame(0, $report->created);
        self::assertSame(0, $report->updated, 'a round trip must not rewrite a single row');
        self::assertSame($written, $report->unchanged);
    }

    public function testExportCarriesTheHeaderAndTheSaleElementId(): void
    {
        $this->importRichReferential();

        $path = $this->temporaryPath();
        $this->exporter->write($path);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

        self::assertSame(ReferenceFileFormat::header(), $lines[0]);

        $rows = iterator_to_array((new CatalogCsvReader())->read($path));

        foreach ($rows as $lineNumber => [$columns]) {
            self::assertNotSame('', $columns['pse_id'], 'line '.$lineNumber.' must carry its pse_id');
            self::assertSame('', $columns['competitor_domains'], 'competitors are not attached to a variant');
        }
    }

    public function testChangingOnlyTheCompetitorListLeavesIdentitiesUntouched(): void
    {
        $product = $this->product();
        $base = ['brand_title' => 'Ixon', 'size_label' => 'L'];

        $this->importer->import($this->file([$this->line($product, $base)]), new ImportOptions());

        $domain = 'competitor-'.bin2hex(random_bytes(5)).'.example';
        $report = $this->importer->import(
            $this->file([$this->line($product, $base + ['competitor_domains' => $domain])]),
            new ImportOptions(),
        );

        self::assertSame(1, $report->unchanged, 'the competitor list is a seeding instruction, not identity data');
        self::assertSame(0, $report->updated);
        self::assertSame(1, $report->competitorsSeeded, 'but the competitor is still proposed');
    }

    public function testAddressingTheSameVariantByADifferentKeyIsNotAChange(): void
    {
        $product = $this->product();
        $saleElementId = (int) $this->saleElement($product)->getId();
        $base = ['brand_title' => 'Ixon', 'size_label' => 'L'];

        // First addressed through the only-variant rung, then by explicit id.
        $this->importer->import($this->file([$this->line($product, $base)]), new ImportOptions());
        $report = $this->importer->import(
            $this->file([$this->line($product, $base + ['pse_id' => (string) $saleElementId])]),
            new ImportOptions(),
        );

        self::assertSame(1, $report->unchanged);
        self::assertSame(0, $report->updated);
    }

    // ------------------------------------------------------------------

    private function importRichReferential(): void
    {
        $product = $this->product();
        $size = $this->attribute('Taille');
        $large = $this->attributeValue($size, 'L');
        $extraLarge = $this->attributeValue($size, 'XL');

        $first = $this->saleElement($product);
        $second = $this->createFixtureFactory()->productSaleElement($product, ['ref' => 'XL-'.uniqid()]);
        $this->combine($first, $size, $large);
        $this->combine($second, $size, $extraLarge);

        $report = $this->importer->import($this->file([
            $this->line($product, [
                'pse_id' => (string) $first->getId(),
                'ean_code' => $this->uniqueGtin(),
                'mpn' => 'IXON-100101045-1023-L',
                'manufacturer_ref' => '100101045',
                'brand_title' => 'Ixon',
                'attribute_combination' => 'Taille:L',
                'size_label' => 'L',
                'color_label' => 'Noir mat',
                'feature_values' => 'Homologation:CE EN 17092-4',
                'currency_code' => 'EUR',
                'target_price' => '349.50',
                'price_vat_included' => '1',
                'monitored' => '1',
                'locale' => 'fr_FR',
                'notes' => 'ligne avec accents: homologué',
            ]),
            $this->line($product, [
                'pse_id' => (string) $second->getId(),
                'attribute_combination' => 'Taille:XL',
                'size_label' => 'XL',
                'brand_title' => 'Ixon',
            ]),
        ]), new ImportOptions());

        self::assertSame(0, $report->rejected, (string) json_encode($report->rejections));
        self::assertSame(2, $report->created);
    }

    private function uniqueGtin(): string
    {
        $body = str_pad((string) random_int(0, 999999999999), 12, '0', \STR_PAD_LEFT);

        return $body.(new GtinNormalizer())->checkDigit(str_pad($body, 13, '0', \STR_PAD_LEFT));
    }

    private function product(): Product
    {
        $factory = $this->createFixtureFactory();

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
    }

    private function saleElement(Product $product): ProductSaleElements
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->orderById()->findOne();
        self::assertNotNull($saleElement);

        return $saleElement;
    }

    private function attribute(string $title): Attribute
    {
        return $this->createFixtureFactory()->attribute(['title' => $title, 'locale' => 'fr_FR']);
    }

    private function attributeValue(Attribute $attribute, string $title): AttributeAv
    {
        return $this->createFixtureFactory()->attributeAv($attribute, ['title' => $title, 'locale' => 'fr_FR']);
    }

    private function combine(ProductSaleElements $saleElement, Attribute $attribute, AttributeAv $value): void
    {
        (new AttributeCombination())
            ->setProductSaleElementsId($saleElement->getId())
            ->setAttributeId($attribute->getId())
            ->setAttributeAvId($value->getId())
            ->save();
    }

    /**
     * @param array<string, string> $overrides
     */
    private function line(Product $product, array $overrides = []): string
    {
        $columns = array_fill_keys(ReferenceFileFormat::COLUMNS, '');
        $columns['product_ref'] = (string) $product->getRef();

        return implode(CatalogCsvReader::DELIMITER, array_merge($columns, $overrides));
    }

    /**
     * @param list<string> $lines
     */
    private function file(array $lines): string
    {
        $path = $this->temporaryPath();
        file_put_contents($path, ReferenceFileFormat::header()."\n".implode("\n", $lines)."\n");

        return $path;
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pricewatch-roundtrip-');
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
