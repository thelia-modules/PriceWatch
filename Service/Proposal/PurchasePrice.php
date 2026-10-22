<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

/**
 * The purchase price excluding tax typed in the purchase tab of the product
 * (ClassicRide module, product_sale_elements_purchase.purchase) or written
 * by the Bihr prices pass. The column is text: 5,585 rows of the base write
 * it with a decimal comma, 27,051 with a dot (measured 2026-10-20).
 */
final class PurchasePrice
{
    /**
     * Null when absent, unreadable or not above zero: no margin can be
     * reckoned from it.
     */
    public static function parse(?string $raw): ?float
    {
        $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim((string) $raw));

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        $price = (float) $value;

        return $price > 0.0 ? $price : null;
    }
}
