<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * Why a well-formed line still failed to designate exactly one sale element.
 */
enum ResolutionCode: string
{
    case UnknownProductRef = 'UNKNOWN_PRODUCT_REF';
    case PseIdNotFound = 'PSE_ID_NOT_FOUND';
    case PseIdMismatch = 'PSE_ID_MISMATCH';
    case ProductHasNoSaleElement = 'PRODUCT_HAS_NO_SALE_ELEMENT';
    case GtinShared = 'GTIN_SHARED';
    case AttributeUnresolved = 'ATTRIBUTE_UNRESOLVED';
    case AmbiguousPseRef = 'AMBIGUOUS_PSE_REF';
    case AmbiguousPse = 'AMBIGUOUS_PSE';

    public function message(): string
    {
        return match ($this) {
            self::UnknownProductRef => 'No product carries this product_ref. The module never creates catalogue entries.',
            self::PseIdNotFound => 'No sale element carries this pse_id.',
            self::PseIdMismatch => 'This pse_id belongs to another product than the one given in product_ref.',
            self::ProductHasNoSaleElement => 'The product exists but has no sale element at all.',
            self::GtinShared => 'This EAN is carried by several sale elements of the product, so it cannot designate one.',
            self::AttributeUnresolved => 'At least one attribute name or value does not match this product\'s variants.',
            self::AmbiguousPseRef => 'Several sale elements of this product carry this pse_ref.',
            self::AmbiguousPse => 'The line does not narrow the product down to a single sale element.',
        };
    }
}
