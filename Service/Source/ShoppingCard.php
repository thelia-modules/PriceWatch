<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

/**
 * One google_shopping_serp item: a product card Google matched to the query.
 * Its product_id is what the sellers endpoint takes.
 */
final readonly class ShoppingCard
{
    public function __construct(
        public string $productId,
        public string $title,
        public ?string $price,
        public ?string $oldPrice,
        public ?string $currency,
        public ?string $seller,
        public ?string $domain,
        public int $rank,
    ) {
    }
}
