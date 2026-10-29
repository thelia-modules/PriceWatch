<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\NormalizedSize;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

/**
 * Turns one raw CSV line into a normalised row, or into a rejection.
 *
 * Only format is judged here. An invalid GTIN is never a rejection: it is
 * recorded as unusable so the line can still be attached through another key,
 * and so the back-office can show the merchant what to fix.
 */
final readonly class RowValidator
{
    /**
     * Matched on the first word, because real catalogues qualify the axis:
     * "Taille vêtement", "Taille casque", "Taille chaussures", "Couleur cadre".
     */
    private const SIZE_AXIS_WORDS = ['taille', 'size', 'pointure', 'tour'];
    private const COLOR_AXIS_WORDS = ['coloris', 'couleur', 'color', 'colour'];

    private const MAX_LENGTH = [
        'product_ref' => 255,
        'pse_ref' => 255,
        'mpn' => 64,
        'manufacturer_ref' => 64,
        'brand_title' => 128,
        'size_label' => 32,
        'color_label' => 48,
    ];

    private const NOTES_MAX_LENGTH = 512;

    public function __construct(
        private GtinNormalizer $gtinNormalizer,
        private MpnNormalizer $mpnNormalizer,
        private BrandNormalizer $brandNormalizer,
        private SizeNormalizer $sizeNormalizer,
        private DomainNormalizer $domainNormalizer,
        private TextNormalizer $textNormalizer,
        private PairListParser $pairListParser,
    ) {
    }

    /**
     * @param array<string, string> $row
     */
    public function validate(int $lineNumber, array $row, string $rawLine): ReferenceRow|RowRejection
    {
        $reject = static fn (RejectCode $code, ?string $detail = null): RowRejection => new RowRejection($lineNumber, $code, $rawLine, $detail);

        if ($this->isBlank($row)) {
            return $reject(RejectCode::EmptyRow);
        }

        $productRef = $this->value($row, 'product_ref');

        if ($productRef === null) {
            return $reject(RejectCode::MissingProductRef);
        }

        foreach (self::MAX_LENGTH as $column => $limit) {
            $candidate = $this->value($row, $column);

            if ($candidate !== null && mb_strlen($candidate) > $limit) {
                return $reject(RejectCode::ValueTooLong, $column.' is '.mb_strlen($candidate).' characters, limit '.$limit);
            }
        }

        $pseId = null;
        $rawPseId = $this->value($row, 'pse_id');

        if ($rawPseId !== null) {
            if (preg_match('/^\d+$/', $rawPseId) !== 1 || (int) $rawPseId < 1) {
                return $reject(RejectCode::InvalidPseId, $rawPseId);
            }

            $pseId = (int) $rawPseId;
        }

        $attributePairs = $this->pairListParser->parse($this->value($row, 'attribute_combination'));

        if ($attributePairs === null) {
            return $reject(RejectCode::InvalidPairs, 'attribute_combination');
        }

        $featurePairs = $this->pairListParser->parse($this->value($row, 'feature_values'));

        if ($featurePairs === null) {
            return $reject(RejectCode::InvalidPairs, 'feature_values');
        }

        $currencyCode = $this->value($row, 'currency_code');

        if ($currencyCode !== null && preg_match('/^[A-Za-z]{3}$/', $currencyCode) !== 1) {
            return $reject(RejectCode::InvalidCurrencyCode, $currencyCode);
        }

        $currencyCode = $currencyCode === null ? null : strtoupper($currencyCode);
        $targetPrice = null;
        $rawTargetPrice = $this->value($row, 'target_price');

        if ($rawTargetPrice !== null) {
            if (preg_match('/^\d+(\.\d{1,6})?$/', $rawTargetPrice) !== 1 || (float) $rawTargetPrice <= 0.0) {
                return $reject(RejectCode::InvalidTargetPrice, $rawTargetPrice);
            }

            if ($currencyCode === null) {
                return $reject(RejectCode::MissingCurrencyWithPrice);
            }

            // DECIMAL columns take a string setter, never a float.
            $targetPrice = number_format((float) $rawTargetPrice, 6, '.', '');
        }

        $priceVatIncluded = $this->flag($row, 'price_vat_included');
        $monitored = $this->flag($row, 'monitored');

        if ($priceVatIncluded === null || $monitored === null) {
            return $reject(RejectCode::InvalidFlag, 'price_vat_included or monitored');
        }

        $locale = $this->value($row, 'locale') ?? 'fr_FR';

        if (preg_match('/^[a-z]{2}_[A-Z]{2}$/', $locale) !== 1) {
            return $reject(RejectCode::InvalidLocale, $locale);
        }

        $competitorDomains = [];

        foreach ($this->splitList($this->value($row, 'competitor_domains')) as $candidate) {
            $domain = $this->domainNormalizer->registrableDomain($candidate);

            if ($domain === null) {
                return $reject(RejectCode::InvalidCompetitorDomain, $candidate);
            }

            $competitorDomains[$domain] = $domain;
        }

        $competitorDomains = array_values($competitorDomains);
        sort($competitorDomains);

        $axisSizeLabel = $this->axisValue($attributePairs, self::SIZE_AXIS_WORDS);
        $sizeLabel = $this->value($row, 'size_label') ?? $axisSizeLabel;
        $colorLabel = $this->value($row, 'color_label') ?? $this->axisValue($attributePairs, self::COLOR_AXIS_WORDS);
        $gtin = $this->gtinNormalizer->normalize($this->value($row, 'ean_code'));
        $mpn = $this->mpnNormalizer->normalize($this->value($row, 'mpn'));
        $brandLabel = $this->value($row, 'brand_title');
        $brandNormalized = $this->brandNormalizer->normalize($brandLabel);
        $size = $this->enrichSizeHint($this->sizeNormalizer->normalize($sizeLabel), $sizeLabel, $axisSizeLabel);
        $pseRef = $this->value($row, 'pse_ref');
        $manufacturerRef = $this->value($row, 'manufacturer_ref');
        $notes = $this->value($row, 'notes');
        $notes = $notes === null ? null : mb_substr($notes, 0, self::NOTES_MAX_LENGTH);

        $checksum = $this->checksum(
            $productRef,
            // An unusable GTIN still takes part in the checksum through its
            // folded raw form, so correcting a typo counts as a change.
            $gtin->value ?? $this->textNormalizer->normalize($this->value($row, 'ean_code')),
            $mpn,
            $manufacturerRef,
            $brandNormalized,
            $attributePairs,
            $size->canonical,
            $this->textNormalizer->normalize($colorLabel),
            $featurePairs,
            $currencyCode,
            $targetPrice,
            $priceVatIncluded,
            $monitored,
            $locale,
            $notes,
        );

        return new ReferenceRow(
            lineNumber: $lineNumber,
            productRef: $productRef,
            pseId: $pseId,
            pseRef: $pseRef,
            gtin: $gtin,
            mpn: $mpn,
            manufacturerRef: $manufacturerRef,
            brandLabel: $brandLabel,
            brandNormalized: $brandNormalized,
            attributePairs: $attributePairs,
            size: $size,
            colorLabel: $colorLabel,
            featurePairs: $featurePairs,
            currencyCode: $currencyCode,
            targetPrice: $targetPrice,
            priceVatIncluded: $priceVatIncluded,
            monitored: $monitored,
            competitorDomains: $competitorDomains,
            locale: $locale,
            notes: $notes,
            checksum: $checksum,
        );
    }

    /**
     * A file often states the size twice: size_label as "57" and the attribute
     * axis as "57 (M)". The explicit column wins, but dropping the letter would
     * throw away a hint that settles a comparison for free instead of paying
     * for a Jev variant judgment. The hint is only taken when both notations
     * designate the same size.
     */
    private function enrichSizeHint(NormalizedSize $size, ?string $usedLabel, ?string $axisLabel): NormalizedSize
    {
        if ($size->alphaHint !== null || $axisLabel === null || $axisLabel === $usedLabel) {
            return $size;
        }

        $fromAxis = $this->sizeNormalizer->normalize($axisLabel);

        if ($fromAxis->alphaHint === null || $fromAxis->canonical !== $size->canonical) {
            return $size;
        }

        return new NormalizedSize($size->raw, $size->family, $size->canonical, $fromAxis->alphaHint);
    }

    /**
     * @param array<string, string> $row
     */
    private function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $row
     */
    private function value(array $row, string $column): ?string
    {
        $value = trim($row[$column] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $row
     */
    private function flag(array $row, string $column): ?int
    {
        $value = $this->value($row, $column);

        return match ($value) {
            null, '1' => 1,
            '0' => 0,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function splitList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param array<string, string> $pairs
     * @param list<string>          $axisNames first words that designate the axis
     */
    private function axisValue(array $pairs, array $axisNames): ?string
    {
        foreach ($pairs as $name => $value) {
            $firstWord = explode(' ', $this->textNormalizer->normalize($name))[0];

            if (\in_array($firstWord, $axisNames, true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Identifies the CONTENT of the identity, not how the line reached it.
     *
     * pse_id and pse_ref are addressing keys and competitor_domains is a
     * seeding instruction: none of them is an attribute of the monitored
     * variant. Keeping them out means two files that designate the same
     * variant through different keys, with the same data, count as unchanged -
     * which is what makes the export/import round trip verifiable - and that
     * editing the competitor list does not mark every identity as modified.
     *
     * @param array<string, string> $attributePairs
     * @param array<string, string> $featurePairs
     */
    private function checksum(
        string $productRef,
        ?string $gtin,
        ?string $mpn,
        ?string $manufacturerRef,
        ?string $brandNormalized,
        array $attributePairs,
        ?string $sizeCanonical,
        ?string $colorNormalized,
        array $featurePairs,
        ?string $currencyCode,
        ?string $targetPrice,
        int $priceVatIncluded,
        int $monitored,
        string $locale,
        ?string $notes,
    ): string {
        $parts = [
            $productRef,
            (string) $gtin,
            (string) $mpn,
            (string) $manufacturerRef,
            (string) $brandNormalized,
            $this->flatten($attributePairs),
            (string) $sizeCanonical,
            (string) $colorNormalized,
            $this->flatten($featurePairs),
            (string) $currencyCode,
            (string) $targetPrice,
            (string) $priceVatIncluded,
            (string) $monitored,
            $locale,
            (string) $notes,
        ];

        return sha1(implode("\x1f", $parts));
    }

    /**
     * @param array<string, string> $pairs
     */
    private function flatten(array $pairs): string
    {
        $flat = [];

        foreach ($pairs as $name => $value) {
            $flat[] = $this->textNormalizer->normalize($name).':'.$this->textNormalizer->normalize($value);
        }

        return implode(',', $flat);
    }
}
