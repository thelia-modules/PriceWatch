<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

/**
 * The product a competitor page sells, read from its structured data.
 */
final readonly class ProductPage
{
    /**
     * @param list<PageVariant> $variants
     */
    public function __construct(
        public string $extractor,
        public ?string $name,
        public ?string $currency,
        public array $variants,
    ) {
    }
}
