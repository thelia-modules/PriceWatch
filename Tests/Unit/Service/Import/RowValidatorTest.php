<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Import\CatalogCsvReader;
use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\ReferenceRow;
use PriceWatch\Service\Import\RejectCode;
use PriceWatch\Service\Import\RowRejection;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeFamily;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

final class RowValidatorTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/reference-sample.csv';

    private RowValidator $validator;

    protected function setUp(): void
    {
        $text = new TextNormalizer();
        $this->validator = new RowValidator(
            new GtinNormalizer(),
            new MpnNormalizer(),
            new BrandNormalizer($text),
            new SizeNormalizer($text),
            new DomainNormalizer(),
            $text,
            new PairListParser(),
        );
    }

    public function testSampleFileValidatesEntirely(): void
    {
        foreach ($this->sampleRows() as $lineNumber => $row) {
            self::assertInstanceOf(ReferenceRow::class, $row, 'line '.$lineNumber.' should validate');
        }
    }

    public function testFirstSampleLineCarriesAUsableGtinAndSeededCompetitors(): void
    {
        $row = $this->sampleRows()[2];
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('CR-BLOUSON-TRACKER', $row->productRef);
        self::assertNull($row->pseId);
        self::assertSame('03660600123454', $row->gtin->value);
        self::assertTrue($row->gtin->isUsable());
        self::assertSame('IXON1001010451023L', $row->mpn);
        self::assertSame('ixon', $row->brandNormalized);
        self::assertSame(['dafy-moto.com', 'motoblouz.com'], $row->competitorDomains);
        self::assertSame('349.000000', $row->targetPrice);
        self::assertSame('EUR', $row->currencyCode);
        self::assertSame(1, $row->monitored);
    }

    public function testThirdSampleLineHasNoGtinAndRemainsValid(): void
    {
        $row = $this->sampleRows()[4];
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame(4821, $row->pseId);
        self::assertFalse($row->gtin->isUsable(), 'a missing EAN is not a rejection');
        self::assertNull($row->gtin->value);
        self::assertSame('BELL7138563', $row->mpn);
        self::assertSame(SizeFamily::Numeric, $row->size->family);
        self::assertSame('57', $row->size->canonical);
        self::assertSame('M', $row->size->alphaHint);
    }

    public function testInvalidGtinIsRecordedRatherThanRejected(): void
    {
        $row = $this->validate(['product_ref' => 'REF1', 'ean_code' => '3660600123457']);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('03660600123457', $row->gtin->value, 'kept so the back-office can show what to fix');
        self::assertFalse($row->gtin->checkDigitValid);
        self::assertFalse($row->gtin->isUsable());
    }

    public function testSizeAndColourAreDerivedFromTheAttributeCombination(): void
    {
        $row = $this->validate([
            'product_ref' => 'REF1',
            'attribute_combination' => 'Coloris:Noir mat|Taille:XL',
        ]);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('XL', $row->size->canonical);
        self::assertSame('Noir mat', $row->colorLabel);
    }

    public function testLetterHintIsRecoveredFromTheAttributeAxisForTheSameSize(): void
    {
        $row = $this->validate([
            'product_ref' => 'REF1',
            'size_label' => '57',
            'attribute_combination' => 'Taille:57 (M)',
        ]);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('57', $row->size->canonical);
        self::assertSame('M', $row->size->alphaHint);
    }

    public function testLetterHintIsNotRecoveredWhenTheTwoNotationsDisagree(): void
    {
        // Taking the letter here would quietly merge two different sizes.
        $row = $this->validate([
            'product_ref' => 'REF1',
            'size_label' => '57',
            'attribute_combination' => 'Taille:59 (L)',
        ]);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('57', $row->size->canonical);
        self::assertNull($row->size->alphaHint);
    }

    public function testExplicitSizeLabelWinsOverTheAttributeAxis(): void
    {
        $row = $this->validate([
            'product_ref' => 'REF1',
            'size_label' => 'L',
            'attribute_combination' => 'Taille:XL',
        ]);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame('L', $row->size->canonical);
    }

    public function testFlagsDefaultToOne(): void
    {
        $row = $this->validate(['product_ref' => 'REF1']);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame(1, $row->monitored);
        self::assertSame(1, $row->priceVatIncluded);
        self::assertSame('fr_FR', $row->locale);
    }

    public function testDuplicateCompetitorDomainsCollapseAndSort(): void
    {
        $row = $this->validate([
            'product_ref' => 'REF1',
            'competitor_domains' => 'https://www.motoblouz.com/x|MOTOBLOUZ.COM|dafy-moto.com',
        ]);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertSame(['dafy-moto.com', 'motoblouz.com'], $row->competitorDomains);
    }

    /**
     * @return iterable<string, array{array<string, string>, RejectCode}>
     */
    public static function rejections(): iterable
    {
        yield 'blank row' => [['product_ref' => '', 'notes' => '  '], RejectCode::EmptyRow];
        yield 'missing product ref' => [['product_ref' => '', 'ean_code' => '3660600123454'], RejectCode::MissingProductRef];
        yield 'pse id not a number' => [['product_ref' => 'REF1', 'pse_id' => '12a'], RejectCode::InvalidPseId];
        yield 'pse id zero' => [['product_ref' => 'REF1', 'pse_id' => '0'], RejectCode::InvalidPseId];
        yield 'pse id negative' => [['product_ref' => 'REF1', 'pse_id' => '-4'], RejectCode::InvalidPseId];
        yield 'price without currency' => [['product_ref' => 'REF1', 'target_price' => '12.50'], RejectCode::MissingCurrencyWithPrice];
        yield 'price with a comma' => [['product_ref' => 'REF1', 'currency_code' => 'EUR', 'target_price' => '12,50'], RejectCode::InvalidTargetPrice];
        yield 'price zero' => [['product_ref' => 'REF1', 'currency_code' => 'EUR', 'target_price' => '0'], RejectCode::InvalidTargetPrice];
        yield 'price with seven decimals' => [['product_ref' => 'REF1', 'currency_code' => 'EUR', 'target_price' => '1.1234567'], RejectCode::InvalidTargetPrice];
        yield 'currency too long' => [['product_ref' => 'REF1', 'currency_code' => 'EURO'], RejectCode::InvalidCurrencyCode];
        yield 'flag out of range' => [['product_ref' => 'REF1', 'monitored' => '2'], RejectCode::InvalidFlag];
        yield 'locale malformed' => [['product_ref' => 'REF1', 'locale' => 'fr'], RejectCode::InvalidLocale];
        yield 'pairs without a colon' => [['product_ref' => 'REF1', 'attribute_combination' => 'Taille L'], RejectCode::InvalidPairs];
        yield 'pairs with an empty value' => [['product_ref' => 'REF1', 'attribute_combination' => 'Taille:'], RejectCode::InvalidPairs];
        yield 'pairs with a dangling pipe' => [['product_ref' => 'REF1', 'attribute_combination' => 'Taille:L|'], RejectCode::InvalidPairs];
        yield 'feature pairs broken' => [['product_ref' => 'REF1', 'feature_values' => 'oops'], RejectCode::InvalidPairs];
        yield 'competitor domain unusable' => [['product_ref' => 'REF1', 'competitor_domains' => 'localhost'], RejectCode::InvalidCompetitorDomain];
        yield 'mpn longer than its column' => [['product_ref' => 'REF1', 'mpn' => str_repeat('A', 65)], RejectCode::ValueTooLong];
        yield 'product ref longer than its column' => [['product_ref' => str_repeat('R', 256)], RejectCode::ValueTooLong];
    }

    /**
     * @param array<string, string> $row
     */
    #[DataProvider('rejections')]
    public function testRejection(array $row, RejectCode $expected): void
    {
        $result = $this->validate($row);

        self::assertInstanceOf(RowRejection::class, $result);
        self::assertSame($expected, $result->code);
        self::assertNotSame('', $result->message());
    }

    public function testChecksumIgnoresTheOrderOfAttributePairs(): void
    {
        $first = $this->validate(['product_ref' => 'REF1', 'attribute_combination' => 'Taille:L|Coloris:Noir']);
        $second = $this->validate(['product_ref' => 'REF1', 'attribute_combination' => 'Coloris:Noir|Taille:L']);

        self::assertInstanceOf(ReferenceRow::class, $first);
        self::assertInstanceOf(ReferenceRow::class, $second);
        self::assertSame($first->checksum, $second->checksum);
    }

    public function testChecksumIgnoresCosmeticDifferences(): void
    {
        $first = $this->validate([
            'product_ref' => 'REF1',
            'ean_code' => '3660600123454',
            'brand_title' => 'IXON',
            'currency_code' => 'eur',
            'target_price' => '349',
        ]);
        $second = $this->validate([
            'product_ref' => 'REF1',
            'ean_code' => ' 3660-600 123454 ',
            'brand_title' => 'Ixon ',
            'currency_code' => 'EUR',
            'target_price' => '349.000000',
        ]);

        self::assertInstanceOf(ReferenceRow::class, $first);
        self::assertInstanceOf(ReferenceRow::class, $second);
        self::assertSame($first->checksum, $second->checksum, 'a re-import of the same data must count as unchanged');
    }

    public function testChecksumChangesWhenAMeaningfulValueChanges(): void
    {
        $first = $this->validate(['product_ref' => 'REF1', 'ean_code' => '3660600123454']);
        $second = $this->validate(['product_ref' => 'REF1', 'ean_code' => '3660600123461']);

        self::assertInstanceOf(ReferenceRow::class, $first);
        self::assertInstanceOf(ReferenceRow::class, $second);
        self::assertNotSame($first->checksum, $second->checksum);
    }

    public function testCorrectingAGtinTypoChangesTheChecksum(): void
    {
        // Both normalise to the same 14 digits, but one fails the check digit:
        // fixing it must not be reported as unchanged.
        $wrong = $this->validate(['product_ref' => 'REF1', 'ean_code' => '366060012345']);
        $right = $this->validate(['product_ref' => 'REF1', 'ean_code' => '3660600123454']);

        self::assertInstanceOf(ReferenceRow::class, $wrong);
        self::assertInstanceOf(ReferenceRow::class, $right);
        self::assertNotSame($wrong->checksum, $right->checksum);
    }

    public function testTwoDifferentUnreadableGtinsDoNotShareAChecksum(): void
    {
        // Neither length is acceptable, so both normalise to no value at all.
        // If the raw form were left out of the checksum, replacing one wrong
        // EAN with another would be reported as unchanged and the identity
        // would never be refreshed.
        $first = $this->validate(['product_ref' => 'REF1', 'ean_code' => '12345']);
        $second = $this->validate(['product_ref' => 'REF1', 'ean_code' => '67890']);

        self::assertInstanceOf(ReferenceRow::class, $first);
        self::assertInstanceOf(ReferenceRow::class, $second);
        self::assertNull($first->gtin->value);
        self::assertNull($second->gtin->value);
        self::assertNotSame($first->checksum, $second->checksum);
    }

    public function testChecksumIsAFortyCharacterHexString(): void
    {
        $row = $this->validate(['product_ref' => 'REF1']);
        self::assertInstanceOf(ReferenceRow::class, $row);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $row->checksum);
    }

    /**
     * @param array<string, string> $row
     */
    private function validate(array $row): ReferenceRow|RowRejection
    {
        return $this->validator->validate(2, $row, implode(';', $row));
    }

    /**
     * @return array<int, ReferenceRow|RowRejection>
     */
    private function sampleRows(): array
    {
        $validated = [];

        foreach ((new CatalogCsvReader())->read(self::FIXTURE) as $lineNumber => [$columns, $rawLine]) {
            $validated[$lineNumber] = $this->validator->validate($lineNumber, $columns, $rawLine);
        }

        return $validated;
    }
}
