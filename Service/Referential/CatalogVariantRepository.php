<?php

declare(strict_types=1);

namespace PriceWatch\Service\Referential;

use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\BrandI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Reads a product's variants from the catalogue, in the shape of the
 * reference file's lines, so that a product added from its page goes through
 * the very checks a file import applies.
 */
final readonly class CatalogVariantRepository
{
    /**
     * @return list<array<string, string>> one reference-file line per variant, keyed by column
     */
    public function referenceLines(int $productId, string $locale): array
    {
        $product = ProductQuery::create()->findPk($productId);

        if ($product === null) {
            return [];
        }

        $brand = $product->getBrandId() === null ? '' : (string) BrandI18nQuery::create()
            ->filterById($product->getBrandId())
            ->filterByLocale($locale)
            ->findOne()?->getTitle();

        $saleElements = ProductSaleElementsQuery::create()->filterByProductId($productId)->orderById()->find();
        $combinations = $this->combinations(array_map(static fn ($pse): int => (int) $pse->getId(), $saleElements->getData()), $locale);
        $lines = [];

        foreach ($saleElements as $saleElement) {
            $lines[] = [
                'product_ref' => (string) $product->getRef(),
                'pse_id' => (string) $saleElement->getId(),
                'pse_ref' => (string) $saleElement->getRef(),
                'ean_code' => (string) $saleElement->getEanCode(),
                'brand_title' => $brand,
                'attribute_combination' => $combinations[(int) $saleElement->getId()] ?? '',
                'monitored' => '1',
                'locale' => $locale,
            ];
        }

        return $lines;
    }

    public function productExists(int $productId): bool
    {
        return ProductQuery::create()->filterById($productId)->count() > 0;
    }

    /**
     * Variants a collect pass would actually search: watched, with a valid
     * GTIN no other variant shares.
     */
    public function collectableVariantCount(int $productId): int
    {
        return PwProductIdentityQuery::create()
            ->filterByProductId($productId)
            ->filterByMonitored(1)
            ->filterByGtinValid(1)
            ->filterByGtinShared(0)
            ->count();
    }

    public function productRef(int $productId): ?string
    {
        return ProductQuery::create()->findPk($productId)?->getRef();
    }

    public function variantCount(int $productId): int
    {
        return ProductSaleElementsQuery::create()->filterByProductId($productId)->count();
    }

    /**
     * @return array<int, PwProductIdentity> sale element id => identity
     */
    public function identitiesOf(int $productId): array
    {
        $identities = [];

        foreach (PwProductIdentityQuery::create()->filterByProductId($productId)->find() as $identity) {
            $identities[(int) $identity->getProductSaleElementsId()] = $identity;
        }

        return $identities;
    }

    /**
     * @param list<int> $saleElementIds
     *
     * @return array<int, string> sale element id => "Attribute:Value|Attribute:Value"
     */
    private function combinations(array $saleElementIds, string $locale): array
    {
        if ($saleElementIds === []) {
            return [];
        }

        $rows = AttributeCombinationQuery::create()->filterByProductSaleElementsId($saleElementIds, Criteria::IN)->find();
        $attributeIds = [];
        $valueIds = [];

        foreach ($rows as $row) {
            $attributeIds[] = (int) $row->getAttributeId();
            $valueIds[] = (int) $row->getAttributeAvId();
        }

        $attributes = $this->titles(AttributeI18nQuery::create(), $attributeIds, $locale);
        $values = $this->titles(AttributeAvI18nQuery::create(), $valueIds, $locale);
        $pairs = [];

        foreach ($rows as $row) {
            $attribute = $attributes[(int) $row->getAttributeId()] ?? null;
            $value = $values[(int) $row->getAttributeAvId()] ?? null;

            // A pair without a label in this locale is left out rather than
            // made up: the variant is still addressed by its id.
            if ($attribute !== null && $value !== null) {
                $pairs[(int) $row->getProductSaleElementsId()][] = $attribute.':'.$value;
            }
        }

        return array_map(static fn (array $list): string => implode('|', $list), $pairs);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function titles(AttributeI18nQuery|AttributeAvI18nQuery $query, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $titles = [];

        foreach ($query->filterById(array_values(array_unique($ids)), Criteria::IN)->filterByLocale($locale)->find() as $translation) {
            // ":" and "|" separate pairs in the reference format.
            $title = trim(str_replace([':', '|'], ' ', (string) $translation->getTitle()));

            if ($title !== '') {
                $titles[(int) $translation->getId()] = $title;
            }
        }

        return $titles;
    }
}
