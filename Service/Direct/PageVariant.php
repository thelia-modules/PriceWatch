<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

/**
 * One variant of a competitor page as its structured data state it. Prices are
 * including tax, two decimals, as strings: they feed DECIMAL columns.
 */
final readonly class PageVariant
{
    public function __construct(
        public string $price,
        public ?string $currency = null,
        public ?string $size = null,
        public ?string $colour = null,
        /** 14 digits with a valid check digit, or null. */
        public ?string $gtin = null,
        /** The struck-through price, when the page shows one above the price. */
        public ?string $listPrice = null,
        public ?bool $inStock = null,
        public ?string $reference = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
