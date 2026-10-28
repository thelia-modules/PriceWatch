<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwImportBatchQuery;
use PriceWatch\Model\PwImportRejectQuery;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Import\CatalogCsvReader;
use PriceWatch\Service\Import\CompetitorSeeder;
use PriceWatch\Service\Import\IdentityUpserter;
use PriceWatch\Service\Import\ImportOptions;
use PriceWatch\Service\Import\MalformedReferenceFileException;
use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\ReferenceImporter;
use PriceWatch\Service\Import\ResolutionCode;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Import\SharedGtinFlags;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

final class ReferenceImporterTest extends PriceWatchIntegrationTestCase
{
    private const HEADER = 'product_ref;pse_id;pse_ref;ean_code;mpn;brand_title;attribute_combination;size_label;currency_code;target_price;monitored;competitor_domains;locale;notes';

    private ReferenceImporter $importer;

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
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testValidFileCreatesOneIdentityPerLine(): void
    {
        $first = $this->product();
        $second = $this->product();
        $gtin = $this->uniqueGtin();

        $report = $this->importer->import($this->file([
            $this->line($first, ['ean_code' => $gtin, 'brand_title' => 'Ixon']),
            $this->line($second, ['ean_code' => $this->uniqueGtin()]),
        ]), new ImportOptions());

        self::assertSame(2, $report->linesTotal);
        self::assertSame(2, $report->created);
        self::assertSame(0, $report->rejected);
        self::assertSame('success', $report->status());

        $identity = PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId((int) $this->saleElement($first)->getId())
            ->findOne();

        self::assertNotNull($identity);
        self::assertSame(str_pad($gtin, 14, '0', \STR_PAD_LEFT), $identity->getGtin());
        self::assertSame(1, $identity->getGtinValid());
        self::assertSame('import', $identity->getGtinSource());
        self::assertSame('ixon', $identity->getBrandNormalized());
        self::assertSame($report->batchId, $identity->getImportBatchId());
    }

    public function testReimportOfTheSameFileChangesNothing(): void
    {
        $product = $this->product();
        $path = $this->file([$this->line($product, ['ean_code' => $this->uniqueGtin()])]);

        $this->importer->import($path, new ImportOptions());
        $before = PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId((int) $this->saleElement($product)->getId())
            ->findOne();
        self::assertNotNull($before);
        $updatedAt = $before->getUpdatedAt();

        $report = $this->importer->import($path, new ImportOptions());

        self::assertSame(1, $report->unchanged);
        self::assertSame(0, $report->created);
        self::assertSame(0, $report->updated);

        $after = PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId((int) $this->saleElement($product)->getId())
            ->findOne();
        self::assertNotNull($after);
        self::assertEquals($updatedAt, $after->getUpdatedAt(), 'an unchanged line must not be rewritten');
    }

    public function testChangedLineUpdatesTheIdentity(): void
    {
        $product = $this->product();

        $this->importer->import($this->file([$this->line($product, ['brand_title' => 'Ixon'])]), new ImportOptions());
        $report = $this->importer->import($this->file([$this->line($product, ['brand_title' => 'Bell'])]), new ImportOptions());

        self::assertSame(1, $report->updated);
        self::assertSame(0, $report->created);

        $identity = PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId((int) $this->saleElement($product)->getId())
            ->findOne();
        self::assertNotNull($identity);
        self::assertSame('bell', $identity->getBrandNormalized());
    }

    public function testUnknownProductRefIsRefusedWithoutStoppingTheImport(): void
    {
        $product = $this->product();

        $report = $this->importer->import($this->file([
            'NO-SUCH-PRODUCT-'.uniqid().';;;;;;;;;;;;;',
            $this->line($product),
        ]), new ImportOptions());

        self::assertSame(2, $report->linesTotal);
        self::assertSame(1, $report->created, 'the good line must still go in');
        self::assertSame(1, $report->rejected);
        self::assertSame('partial', $report->status());

        $reject = PwImportRejectQuery::create()->filterByBatchId($report->batchId)->findOne();
        self::assertNotNull($reject);
        self::assertSame(ResolutionCode::UnknownProductRef->value, $reject->getErrorCode());
        self::assertSame(2, $reject->getLineNumber());
        self::assertNotSame('', (string) $reject->getRawLine(), 'the raw line is kept so nothing is lost');
    }

    public function testTwoLinesClaimingTheSameVariantAreNotSilentlyMerged(): void
    {
        $product = $this->product();

        $report = $this->importer->import($this->file([
            $this->line($product, ['brand_title' => 'Ixon']),
            $this->line($product, ['brand_title' => 'Bell']),
        ]), new ImportOptions());

        self::assertSame(1, $report->created);
        self::assertSame(1, $report->rejected);

        $reject = PwImportRejectQuery::create()->filterByBatchId($report->batchId)->findOne();
        self::assertNotNull($reject);
        self::assertSame(ResolutionCode::DuplicateInFile->value, $reject->getErrorCode());
    }

    public function testDryRunWritesNoIdentityButKeepsItsTrace(): void
    {
        $product = $this->product();

        $report = $this->importer->import(
            $this->file([$this->line($product), 'UNKNOWN-'.uniqid().';;;;;;;;;;;;;']),
            new ImportOptions(dryRun: true),
        );

        self::assertSame(
            0,
            PwProductIdentityQuery::create()->filterByProductId($product->getId())->count(),
            'a dry run writes no identity at all',
        );
        self::assertSame(0, $report->created);
        self::assertSame(1, $report->rejected);

        $batch = PwImportBatchQuery::create()->findPk($report->batchId);
        self::assertNotNull($batch);
        self::assertSame(1, $batch->getDryRun());
        self::assertSame('partial', $batch->getStatus());
        self::assertSame(1, PwImportRejectQuery::create()->filterByBatchId($report->batchId)->count());
    }

    public function testCompetitorDomainsAreProposedNeverWatched(): void
    {
        $product = $this->product();

        $domain = $this->uniqueDomain();
        $report = $this->importer->import(
            $this->file([$this->line($product, ['competitor_domains' => 'https://www.'.$domain.'/x|'.$this->uniqueDomain()])]),
            new ImportOptions(),
        );

        self::assertSame(2, $report->competitorsSeeded);

        $competitor = PwCompetitorQuery::create()->filterByDomain($domain)->findOne();
        self::assertNotNull($competitor);
        self::assertSame(CompetitorSeeder::STATUS_PROPOSED, $competitor->getStatus());
        self::assertSame('import', $competitor->getDiscoverySource());
    }

    public function testAlreadyRejectedCompetitorIsNotResurrectedByAnImport(): void
    {
        $product = $this->product();
        $domain = $this->uniqueDomain();
        $path = $this->file([$this->line($product, ['competitor_domains' => $domain])]);
        $this->importer->import($path, new ImportOptions());

        $competitor = PwCompetitorQuery::create()->filterByDomain($domain)->findOne();
        self::assertNotNull($competitor);
        $competitor->setStatus('rejected')->save();

        $report = $this->importer->import($path, new ImportOptions());

        self::assertSame(0, $report->competitorsSeeded);
        $reloaded = PwCompetitorQuery::create()->filterByDomain($domain)->findOne();
        self::assertNotNull($reloaded);
        self::assertSame('rejected', $reloaded->getStatus(), 'a human decision must survive an import');
    }

    public function testGtinCarriedByTwoVariantsIsFlaggedShared(): void
    {
        $product = $this->product();
        $second = $this->addSaleElement($product, 'SECOND');

        $gtin = $this->uniqueGtin();
        $report = $this->importer->import($this->file([
            $this->line($product, ['ean_code' => $gtin, 'pse_id' => (string) $this->saleElement($product)->getId()]),
            $this->line($product, ['ean_code' => $gtin, 'pse_id' => (string) $second->getId()]),
        ]), new ImportOptions());

        self::assertSame(2, $report->created);
        self::assertSame(
            2,
            PwProductIdentityQuery::create()->filterByGtin(str_pad($gtin, 14, '0', \STR_PAD_LEFT))->filterByGtinShared(1)->count(),
            'a GTIN on two variants can never designate one, so both are flagged',
        );
    }

    public function testDeactivateMissingUnmonitorsWithoutDeleting(): void
    {
        $product = $this->product();
        $second = $this->addSaleElement($product, 'SECOND');
        $firstId = (int) $this->saleElement($product)->getId();

        $this->importer->import($this->file([
            $this->line($product, ['pse_id' => (string) $firstId]),
            $this->line($product, ['pse_id' => (string) $second->getId()]),
        ]), new ImportOptions());

        $report = $this->importer->import(
            $this->file([$this->line($product, ['pse_id' => (string) $firstId])]),
            new ImportOptions(deactivateMissing: true),
        );

        // The option sweeps the whole referential, not just this file's
        // product, so the count depends on what else is monitored. What must
        // hold is the effect on the variant the file dropped.
        self::assertGreaterThanOrEqual(1, $report->deactivated);
        self::assertSame(
            2,
            PwProductIdentityQuery::create()->filterByProductId($product->getId())->count(),
            'nothing is deleted, history keeps its attachment',
        );
        self::assertSame(
            0,
            PwProductIdentityQuery::create()->filterByProductSaleElementsId((int) $second->getId())->findOne()?->getMonitored(),
        );
    }

    public function testEmptyFileDeactivatesNothing(): void
    {
        $product = $this->product();
        $this->importer->import($this->file([$this->line($product)]), new ImportOptions());

        $report = $this->importer->import($this->file([]), new ImportOptions(deactivateMissing: true));

        self::assertSame(0, $report->deactivated, 'an empty file must not unmonitor the whole referential');
        self::assertSame(
            1,
            PwProductIdentityQuery::create()->filterByProductId($product->getId())->filterByMonitored(1)->count(),
        );
    }

    public function testFileWithoutProductRefColumnLeavesNoBatchBehind(): void
    {
        $before = PwImportBatchQuery::create()->count();
        $path = $this->rawFile("ean_code;notes\n3660600123454;hello\n");

        try {
            $this->importer->import($path, new ImportOptions());
            self::fail('a file without product_ref must be refused');
        } catch (MalformedReferenceFileException) {
            self::assertSame($before, PwImportBatchQuery::create()->count());
        }
    }

    // ------------------------------------------------------------------

    /**
     * Unique per call, with a valid check digit. A hardcoded GTIN would make
     * the test depend on the table being empty, which it is not on a real
     * install.
     */
    private function uniqueGtin(): string
    {
        $body = str_pad((string) random_int(0, 999999999999), 12, '0', \STR_PAD_LEFT);

        return $body.(new GtinNormalizer())->checkDigit(str_pad($body, 13, '0', \STR_PAD_LEFT));
    }

    private function uniqueDomain(): string
    {
        return 'competitor-'.bin2hex(random_bytes(5)).'.example';
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

    private function addSaleElement(Product $product, string $ref): ProductSaleElements
    {
        return $this->createFixtureFactory()->productSaleElement($product, ['ref' => $ref]);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function line(Product $product, array $overrides = []): string
    {
        $columns = array_merge([
            'product_ref' => (string) $product->getRef(),
            'pse_id' => '',
            'pse_ref' => '',
            'ean_code' => '',
            'mpn' => '',
            'brand_title' => '',
            'attribute_combination' => '',
            'size_label' => '',
            'currency_code' => '',
            'target_price' => '',
            'monitored' => '',
            'competitor_domains' => '',
            'locale' => '',
            'notes' => '',
        ], $overrides);

        return implode(';', $columns);
    }

    /**
     * @param list<string> $lines
     */
    private function file(array $lines): string
    {
        return $this->rawFile(self::HEADER."\n".($lines === [] ? '' : implode("\n", $lines)."\n"));
    }

    private function rawFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pricewatch-import-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
