<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

/**
 * What the model is told about the shop. Facts read from the catalogue,
 * passed as data: the prompt never lets them act as instructions.
 */
final readonly class CatalogSample
{
    /**
     * @param list<string> $brands
     * @param list<string> $categories
     * @param list<string> $productTitles
     */
    public function __construct(
        public ?string $shopUrl,
        public array $brands,
        public array $categories,
        public array $productTitles,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->brands === [] && $this->categories === [] && $this->productTitles === [];
    }
}
