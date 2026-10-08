<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Import;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Import\CatalogCsvReader;
use PriceWatch\Service\Import\MalformedReferenceFileException;

final class CatalogCsvReaderTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/reference-sample.csv';

    private CatalogCsvReader $reader;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        $this->reader = new CatalogCsvReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        $this->temporaryFiles = [];
    }

    public function testSampleFileYieldsThreeRowsNumberedFromTwo(): void
    {
        $rows = iterator_to_array($this->reader->read(self::FIXTURE));

        self::assertSame([2, 3, 4], array_keys($rows), 'line numbers must match the file, header included');
        self::assertCount(3, $rows);
    }

    public function testColumnsAreAddressedByHeaderName(): void
    {
        $rows = iterator_to_array($this->reader->read(self::FIXTURE));
        [$columns] = $rows[2];

        self::assertSame('CR-BLOUSON-TRACKER', $columns['product_ref']);
        self::assertSame('3660600123454', $columns['ean_code']);
        self::assertSame('Taille:L|Coloris:Noir', $columns['attribute_combination']);
        self::assertSame('dafy-moto.com|motoblouz.com', $columns['competitor_domains']);
        self::assertSame('', $columns['pse_id'], 'an empty cell is an empty string, not a missing key');
    }

    public function testQuotedCellKeepingASemicolonIsNotSplit(): void
    {
        $path = $this->write("product_ref;notes\nREF1;\"a; b; c\"\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertSame('a; b; c', $rows[2][0]['notes']);
    }

    public function testByteOrderMarkIsStrippedFromTheFirstHeader(): void
    {
        $path = $this->write("\xEF\xBB\xBFproduct_ref;ean_code\nREF1;3660600123454\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertArrayHasKey('product_ref', $rows[2][0], 'a BOM must not hide the first column');
        self::assertSame('REF1', $rows[2][0]['product_ref']);
    }

    public function testColumnOrderIsFree(): void
    {
        $path = $this->write("ean_code;notes;product_ref\n3660600123454;hello;REF1\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertSame('REF1', $rows[2][0]['product_ref']);
        self::assertSame('3660600123454', $rows[2][0]['ean_code']);
    }

    public function testHeaderNamesAreCaseAndSpaceInsensitive(): void
    {
        $path = $this->write(" Product_Ref ; EAN_Code \nREF1;3660600123454\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertSame('REF1', $rows[2][0]['product_ref']);
        self::assertSame('3660600123454', $rows[2][0]['ean_code']);
    }

    public function testBlankLineIsSkippedButStillCountsInTheLineNumbering(): void
    {
        // The line number is what a human types into a spreadsheet's "go to
        // line", so it must match the file, blank lines included.
        $path = $this->write("product_ref\nREF1\n\nREF2\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertSame([2, 4], array_keys($rows));
        self::assertSame('REF1', $rows[2][0]['product_ref']);
        self::assertSame('REF2', $rows[4][0]['product_ref']);
    }

    public function testRawLineIsKeptForTheRejectReport(): void
    {
        $path = $this->write("product_ref;notes\nREF1;hello\n");
        $rows = iterator_to_array($this->reader->read($path));

        self::assertSame('REF1;hello', $rows[2][1]);
    }

    public function testFileWithoutProductRefColumnIsRefusedWholesale(): void
    {
        $path = $this->write("ean_code;notes\n3660600123454;hello\n");

        $this->expectException(MalformedReferenceFileException::class);
        $this->expectExceptionMessageMatches('/no "product_ref" column/');

        iterator_to_array($this->reader->read($path));
    }

    public function testEmptyFileIsRefused(): void
    {
        $path = $this->write('');

        $this->expectException(MalformedReferenceFileException::class);
        $this->expectExceptionMessageMatches('/no header line/');

        iterator_to_array($this->reader->read($path));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(MalformedReferenceFileException::class);

        iterator_to_array($this->reader->read(__DIR__.'/does-not-exist.csv'));
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pricewatch-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
