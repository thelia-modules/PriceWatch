<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

/**
 * One shops_list item: one merchant's offer on a product card.
 *
 * Prices are kept as strings, as received: they feed DECIMAL columns, whose
 * setters take strings. total = base + tax + shipping per DataForSEO.
 */
final readonly class SellerOffer
{
    public function __construct(
        public ?string $domain,
        public string $sellerName,
        public string $title,
        public ?string $url,
        public ?string $details,
        public ?string $basePrice,
        public ?string $tax,
        public ?string $shippingPrice,
        public ?string $totalPrice,
        public ?string $currency,
        public ?string $condition,
        public ?string $annotation,
        public ?string $availability,
        public int $rank,
    ) {
    }

    /**
     * The price as shown to a shopper before delivery: base plus tax when tax
     * is itemised, otherwise the base price as displayed.
     */
    public function priceExcludingShipping(): ?string
    {
        if ($this->basePrice === null) {
            return null;
        }

        return $this->tax === null
            ? $this->basePrice
            : number_format((float) $this->basePrice + (float) $this->tax, 2, '.', '');
    }
}
