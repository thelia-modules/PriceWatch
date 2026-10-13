<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Writes the referential back out in the very format the import reads.
 *
 * Two deliberate differences from a hand-written file:
 *  - pse_id is always filled, since this comes from the database and needs no
 *    guessing;
 *  - competitor_domains is left empty, because a competitor is not attached to
 *    a variant in this model: the column is a seeding instruction on the way
 *    in, and there is nothing per-variant to write back.
 *
 * Neither breaks the round trip: both columns are addressing and seeding, and
 * are deliberately absent from the row checksum.
 */
final readonly class ReferenceExporter
{
    public function write(string $path, bool $monitoredOnly = false): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot write the export to "%s".', $path));
        }

        $written = 0;

        try {
            fwrite($handle, ReferenceFileFormat::header()."\n");

            foreach ($this->rows($monitoredOnly) as $row) {
                fputcsv(
                    $handle,
                    array_map(static fn (string $column): string => $row[$column], ReferenceFileFormat::COLUMNS),
                    CatalogCsvReader::DELIMITER,
                    CatalogCsvReader::ENCLOSURE,
                    CatalogCsvReader::ESCAPE,
                );
                ++$written;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * @return \Generator<int, array<string, string>>
     */
    public function rows(bool $monitoredOnly = false): \Generator
    {
        $query = PwProductIdentityQuery::create()
            ->orderByProductId()
            ->orderByProductSaleElementsId();

        if ($monitoredOnly) {
            $query->filterByMonitored(1);
        }

        $identities = $query->find()->getData();

        if ($identities === []) {
            return;
        }

        $productRefs = $this->productRefs($identities);
        $saleElementRefs = $this->saleElementRefs($identities);

        foreach ($identities as $identity) {
            $productId = (int) $identity->getProductId();
            $saleElementId = (int) $identity->getProductSaleElementsId();

            yield [
                'product_ref' => $productRefs[$productId] ?? '',
                'pse_id' => (string) $saleElementId,
                'pse_ref' => $saleElementRefs[$saleElementId] ?? '',
                'ean_code' => (string) ($identity->getGtin() ?? $identity->getGtinRaw() ?? ''),
                'mpn' => (string) $identity->getMpnNormalized(),
                'manufacturer_ref' => (string) $identity->getManufacturerRef(),
                'brand_title' => (string) $identity->getBrandLabel(),
                'attribute_combination' => $this->pairs($identity->getVariantAxisJson()),
                'size_label' => (string) $identity->getSizeLabel(),
                'color_label' => (string) $identity->getColorLabel(),
                'feature_values' => $this->pairs($identity->getFeatureDigest()),
                'currency_code' => (string) $identity->getCurrencyCode(),
                'target_price' => (string) $identity->getTargetPrice(),
                'price_vat_included' => (string) ($identity->getPriceVatIncluded() ?? 1),
                'monitored' => (string) ($identity->getMonitored() ?? 1),
                'competitor_domains' => '',
                'locale' => (string) $identity->getLocale(),
                'notes' => (string) $identity->getNotes(),
            ];
        }
    }

    /**
     * @param list<PwProductIdentity> $identities
     *
     * @return array<int, string>
     */
    private function productRefs(array $identities): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (PwProductIdentity $identity): int => (int) $identity->getProductId(),
            $identities,
        )));

        $refs = [];

        foreach (ProductQuery::create()->filterById($ids, Criteria::IN)->find() as $product) {
            $refs[(int) $product->getId()] = (string) $product->getRef();
        }

        return $refs;
    }

    /**
     * @param list<PwProductIdentity> $identities
     *
     * @return array<int, string>
     */
    private function saleElementRefs(array $identities): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (PwProductIdentity $identity): int => (int) $identity->getProductSaleElementsId(),
            $identities,
        )));

        $refs = [];

        foreach (ProductSaleElementsQuery::create()->filterById($ids, Criteria::IN)->find() as $saleElement) {
            $refs[(int) $saleElement->getId()] = (string) $saleElement->getRef();
        }

        return $refs;
    }

    private function pairs(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return '';
        }

        $pairs = [];

        foreach ($decoded as $name => $value) {
            $pairs[] = $name.':'.$value;
        }

        return implode('|', $pairs);
    }
}
