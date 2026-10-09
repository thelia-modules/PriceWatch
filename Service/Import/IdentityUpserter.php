<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Writes one identity row per sale element.
 *
 * The module never writes to the catalogue. When the file brings a GTIN the
 * catalogue does not have, it is stored here with gtin_source = import and
 * product_sale_elements.ean_code is left alone: enriching the catalogue stays
 * a human act, offered in the back-office.
 */
final readonly class IdentityUpserter
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    private const SIZE_LABEL_LENGTH = 32;
    private const COLOR_LABEL_LENGTH = 48;

    public function __construct(
        private GtinNormalizer $gtinNormalizer,
        private TextNormalizer $textNormalizer,
    ) {
    }

    public function upsert(ReferenceRow $row, SaleElementResolution $resolution, ?int $batchId): string
    {
        $saleElementId = $resolution->saleElementId;
        $productId = $resolution->productId;

        if ($saleElementId === null || $productId === null) {
            throw new \LogicException('Only an attached resolution can be upserted.');
        }

        $existing = PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElementId)->findOne();

        // Nothing in the line changed: no UPDATE at all, so updated_at keeps
        // telling the truth about when the data last moved.
        if ($existing !== null && $existing->getSourceChecksum() === $row->checksum) {
            return self::UNCHANGED;
        }

        $identity = $existing ?? new PwProductIdentity();
        [$gtin, $gtinSource] = $this->resolveGtin($row, $saleElementId);

        $identity
            ->setProductSaleElementsId($saleElementId)
            ->setProductId($productId)
            ->setGtinRaw($row->gtin->raw)
            ->setGtin($gtin?->value)
            ->setGtinValid($gtin !== null && $gtin->checkDigitValid ? 1 : 0)
            ->setGtinSource($gtinSource)
            ->setMpnNormalized($row->mpn)
            ->setManufacturerRef($row->manufacturerRef)
            ->setBrandLabel($row->brandLabel)
            ->setBrandNormalized($row->brandNormalized)
            ->setSizeLabel($this->clamp($row->size->raw, self::SIZE_LABEL_LENGTH))
            ->setSizeNormalized($row->size->canonical)
            ->setColorLabel($this->clamp($row->colorLabel, self::COLOR_LABEL_LENGTH))
            ->setColorNormalized($this->normalizedOrNull($row->colorLabel))
            ->setVariantAxisJson($this->encode($row->attributePairs))
            ->setFeatureDigest($this->encode($row->featurePairs))
            ->setTargetPrice($row->targetPrice)
            // Left null when the file gave none: defaulting it to EUR would
            // invent a currency the merchant never stated, and the export
            // would then write it back and report the line as modified.
            ->setCurrencyCode($row->currencyCode)
            ->setPriceVatIncluded($row->priceVatIncluded)
            ->setMonitored($row->monitored)
            ->setLocale($row->locale)
            ->setNotes($row->notes)
            ->setSourceChecksum($row->checksum)
            ->setImportBatchId($batchId);

        $identity->save();

        return $existing === null ? self::CREATED : self::UPDATED;
    }

    /**
     * The file wins when it carries a usable GTIN; otherwise the catalogue's
     * own ean_code is normalised and used, so an identity always reflects the
     * best GTIN known about the variant.
     *
     * @return array{0: ?\PriceWatch\Service\Normalizer\NormalizedGtin, 1: ?string}
     */
    private function resolveGtin(ReferenceRow $row, int $saleElementId): array
    {
        if ($row->gtin->isUsable()) {
            return [$row->gtin, 'import'];
        }

        $saleElement = ProductSaleElementsQuery::create()->findPk($saleElementId);
        $fromCatalogue = $this->gtinNormalizer->normalize($saleElement?->getEanCode());

        if ($fromCatalogue->isUsable()) {
            return [$fromCatalogue, 'catalog'];
        }

        // Keep whatever the file said, flagged invalid, so the back-office can
        // show the merchant a GTIN to fix rather than an empty cell.
        return [$row->gtin->value !== null ? $row->gtin : null, $row->gtin->value !== null ? 'import' : null];
    }

    /**
     * @param array<string, string> $pairs
     */
    private function encode(array $pairs): ?string
    {
        return $pairs === [] ? null : json_encode($pairs, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    private function normalizedOrNull(?string $raw): ?string
    {
        $normalized = $this->textNormalizer->normalize($raw);

        return $normalized === '' ? null : $normalized;
    }

    private function clamp(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
