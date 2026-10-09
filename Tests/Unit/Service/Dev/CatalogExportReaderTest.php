<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Dev;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Dev\CatalogExportReader;

final class CatalogExportReaderTest extends TestCase
{
    private const HEADER = '"ref","gtin","title","title","title","price"';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testOneLinePerSizeGivesOneVariantPerSize(): void
    {
        $parsed = $this->read([
            '"183ECO","03664191100832","Pantalon DG ECO","Taille vêtement","XS",18.333333',
            '"183ECO","03664191100849","Pantalon DG ECO","Taille vêtement","S",18.333333',
        ]);

        self::assertCount(2, $parsed['products']['183ECO']['variants']);
        self::assertSame(['Taille vêtement' => 'S'], $parsed['products']['183ECO']['variants'][1]['axes']);
        self::assertSame('18.333333', $parsed['products']['183ECO']['variants'][0]['price']);
    }

    public function testLiteralNullGtinDoesNotFoldSizesTogether(): void
    {
        // As found in the real export: every size of the product carries NULL.
        $parsed = $this->read([
            '"JDL5012","NULL","Blouson","Taille vêtement","S",100',
            '"JDL5012","NULL","Blouson","Taille vêtement","M",100',
            '"JDL5012","NULL","Blouson","Taille vêtement","L",100',
        ]);

        $variants = $parsed['products']['JDL5012']['variants'];

        self::assertCount(3, $variants, 'three sizes, three variants');
        self::assertSame('', $variants[0]['gtin'], 'NULL is not a GTIN');
        self::assertSame(0, $parsed['axisConflicts']);
    }

    public function testSeveralAxesOfOneVariantAreMerged(): void
    {
        $parsed = $this->read([
            '"CASQUE1","04006381333931","Casque","Taille casque","57",200',
            '"CASQUE1","04006381333931","Casque","Coloris","Noir",200',
        ]);

        self::assertSame(
            ['Taille casque' => '57', 'Coloris' => 'Noir'],
            $parsed['products']['CASQUE1']['variants'][0]['axes'],
        );
    }

    public function testTwoValuesOnOneAxisForOneGtinIsCountedAsAConflict(): void
    {
        // Also found in the real export: one GTIN given for both S and XS.
        $parsed = $this->read([
            '"102472BLK","05056136252832","Veste","Taille vêtement","XS",50',
            '"102472BLK","05056136252832","Veste","Taille vêtement","S",50',
        ]);

        self::assertSame(1, $parsed['axisConflicts']);
        self::assertSame(['Taille vêtement' => 'XS'], $parsed['products']['102472BLK']['variants'][0]['axes'], 'first value kept');
    }

    /**
     * @param list<string> $lines
     *
     * @return array{products: array<string, mixed>, lines: int, axisConflicts: int}
     */
    private function read(array $lines): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pw-export-');
        file_put_contents($path, self::HEADER."\n".implode("\n", $lines)."\n");
        $this->files[] = $path;

        return (new CatalogExportReader())->read($path);
    }
}
