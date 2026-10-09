<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeCombinationQuery;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Attaches a reference line to exactly one sale element.
 *
 * Thelia 3 has no reliable functional key for a sale element: pse_ref is
 * indexed but not unique, ean_code is neither indexed nor unique nor
 * validated, and the merchant filling the file does not know pse_id. Hence a
 * cascade, and an explicit refusal rather than an arbitrary attachment.
 *
 * The search is always scoped to the product named by product_ref, which is
 * mandatory. That keeps every rung bounded to a handful of rows, and it turns
 * "this EAN belongs to another product" into a visible inconsistency instead
 * of a silent cross-product attachment.
 *
 * An ambiguous rung does not stop the cascade: a shared EAN does not prevent
 * the attribute combination from settling the line. The remembered reason is
 * only reported if no rung succeeds.
 */
final readonly class SaleElementResolver
{
    public function __construct(
        private GtinNormalizer $gtinNormalizer,
        private TextNormalizer $textNormalizer,
    ) {
    }

    public function resolve(ReferenceRow $row): SaleElementResolution
    {
        $product = ProductQuery::create()->filterByRef($row->productRef)->findOne();

        if ($product === null) {
            return SaleElementResolution::failed(ResolutionCode::UnknownProductRef);
        }

        $productId = (int) $product->getId();

        if ($row->pseId !== null) {
            return $this->byExplicitId($row->pseId, $productId);
        }

        $saleElements = ProductSaleElementsQuery::create()
            ->filterByProductId($productId)
            ->orderById()
            ->find()
            ->getData();

        if ($saleElements === []) {
            return SaleElementResolution::failed(ResolutionCode::ProductHasNoSaleElement);
        }

        $rememberedFailure = null;

        foreach ([
            [ResolutionMethod::Gtin, fn (): array => $this->matchingGtin($row, $saleElements), ResolutionCode::GtinShared],
            [ResolutionMethod::Attributes, fn (): array => $this->matchingAttributes($row, $saleElements), ResolutionCode::AmbiguousPse],
            [ResolutionMethod::PseRef, fn (): array => $this->matchingRef($row, $saleElements), ResolutionCode::AmbiguousPseRef],
            [ResolutionMethod::OnlySaleElement, static fn (): array => \count($saleElements) === 1 ? $saleElements : [], ResolutionCode::AmbiguousPse],
        ] as [$method, $candidates, $ambiguityCode]) {
            $matches = $candidates();

            if (\count($matches) === 1) {
                return SaleElementResolution::attached((int) $matches[0]->getId(), $productId, $method);
            }

            if (\count($matches) > 1) {
                $rememberedFailure ??= $ambiguityCode;
            }
        }

        if ($rememberedFailure === null && $row->attributePairs !== [] && $this->attributeLookupIncomplete($row, $saleElements)) {
            $rememberedFailure = ResolutionCode::AttributeUnresolved;
        }

        return SaleElementResolution::failed($rememberedFailure ?? ResolutionCode::AmbiguousPse);
    }

    private function byExplicitId(int $saleElementId, int $productId): SaleElementResolution
    {
        $saleElement = ProductSaleElementsQuery::create()->findPk($saleElementId);

        if ($saleElement === null) {
            return SaleElementResolution::failed(ResolutionCode::PseIdNotFound);
        }

        if ((int) $saleElement->getProductId() !== $productId) {
            return SaleElementResolution::failed(ResolutionCode::PseIdMismatch);
        }

        return SaleElementResolution::attached($saleElementId, $productId, ResolutionMethod::PseId);
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<ProductSaleElements>
     */
    private function matchingGtin(ReferenceRow $row, array $saleElements): array
    {
        if (!$row->gtin->isUsable()) {
            return [];
        }

        $matches = [];

        foreach ($saleElements as $saleElement) {
            if ($this->gtinNormalizer->normalize($saleElement->getEanCode())->value === $row->gtin->value) {
                $matches[] = $saleElement;
            }
        }

        return $matches;
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<ProductSaleElements>
     */
    private function matchingRef(ReferenceRow $row, array $saleElements): array
    {
        if ($row->pseRef === null) {
            return [];
        }

        return array_values(array_filter(
            $saleElements,
            static fn (ProductSaleElements $saleElement): bool => $saleElement->getRef() === $row->pseRef,
        ));
    }

    /**
     * The file states variants by label ("Taille:L"). Only the attributes
     * actually used by this product's sale elements are looked up, so the
     * query stays bounded and a label from another product never matches.
     *
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<ProductSaleElements>
     */
    private function matchingAttributes(ReferenceRow $row, array $saleElements): array
    {
        if ($row->attributePairs === []) {
            return [];
        }

        $wanted = $this->wantedCombination($row, $saleElements);

        if ($wanted === null) {
            return [];
        }

        $matches = [];

        foreach ($this->combinationsBySaleElement($saleElements) as $saleElementId => $combination) {
            if ($combination === $wanted) {
                $matches[] = $saleElementId;
            }
        }

        return array_values(array_filter(
            $saleElements,
            static fn (ProductSaleElements $saleElement): bool => \in_array((int) $saleElement->getId(), $matches, true),
        ));
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<string>|null null when a label cannot be resolved at all
     */
    private function wantedCombination(ReferenceRow $row, array $saleElements): ?array
    {
        $attributeIds = $this->labelToId(AttributeI18nQuery::create(), $this->usedAttributeIds($saleElements), $row->locale);
        $valueIds = $this->labelToId(AttributeAvI18nQuery::create(), $this->usedAttributeAvIds($saleElements), $row->locale);

        $wanted = [];

        foreach ($row->attributePairs as $name => $value) {
            $attributeId = $attributeIds[$this->textNormalizer->normalize($name)] ?? null;
            $valueId = $valueIds[$this->textNormalizer->normalize($value)] ?? null;

            if ($attributeId === null || $valueId === null) {
                return null;
            }

            $wanted[] = $attributeId.':'.$valueId;
        }

        sort($wanted);

        return $wanted;
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     */
    private function attributeLookupIncomplete(ReferenceRow $row, array $saleElements): bool
    {
        return $this->wantedCombination($row, $saleElements) === null;
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return array<int, list<string>>
     */
    private function combinationsBySaleElement(array $saleElements): array
    {
        $ids = array_map(static fn (ProductSaleElements $pse): int => (int) $pse->getId(), $saleElements);

        if ($ids === []) {
            return [];
        }

        $combinations = [];

        foreach ($ids as $id) {
            $combinations[$id] = [];
        }

        $rows = AttributeCombinationQuery::create()
            ->filterByProductSaleElementsId($ids, Criteria::IN)
            ->find();

        foreach ($rows as $combination) {
            $combinations[(int) $combination->getProductSaleElementsId()][]
                = $combination->getAttributeId().':'.$combination->getAttributeAvId();
        }

        foreach ($combinations as &$values) {
            sort($values);
        }

        return $combinations;
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<int>
     */
    private function usedAttributeIds(array $saleElements): array
    {
        return $this->distinctCombinationColumn($saleElements, 'attribute');
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<int>
     */
    private function usedAttributeAvIds(array $saleElements): array
    {
        return $this->distinctCombinationColumn($saleElements, 'attribute_av');
    }

    /**
     * @param list<ProductSaleElements> $saleElements
     *
     * @return list<int>
     */
    private function distinctCombinationColumn(array $saleElements, string $which): array
    {
        $ids = [];

        foreach ($this->combinationsBySaleElement($saleElements) as $combination) {
            foreach ($combination as $pair) {
                [$attributeId, $valueId] = explode(':', $pair);
                $ids[] = (int) ($which === 'attribute' ? $attributeId : $valueId);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, int>
     */
    private function labelToId(AttributeI18nQuery|AttributeAvI18nQuery $query, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $map = [];

        foreach ($query->filterById($ids, Criteria::IN)->filterByLocale($locale)->find() as $translation) {
            $map[$this->textNormalizer->normalize($translation->getTitle())] = (int) $translation->getId();
        }

        return $map;
    }
}
