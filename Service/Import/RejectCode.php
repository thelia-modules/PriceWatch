<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * Format-level rejections, decided without touching the database.
 *
 * Resolution-level codes (UNKNOWN_PRODUCT_REF, GTIN_SHARED, AMBIGUOUS_PSE and
 * friends) belong to the resolver, not here: a line can be perfectly formed and
 * still fail to designate exactly one sale element.
 */
enum RejectCode: string
{
    case EmptyRow = 'EMPTY_ROW';
    case MissingProductRef = 'MISSING_PRODUCT_REF';
    case InvalidPseId = 'INVALID_PSE_ID';
    case InvalidTargetPrice = 'INVALID_TARGET_PRICE';
    case MissingCurrencyWithPrice = 'MISSING_CURRENCY_WITH_PRICE';
    case InvalidCurrencyCode = 'INVALID_CURRENCY_CODE';
    case InvalidFlag = 'INVALID_FLAG';
    case InvalidLocale = 'INVALID_LOCALE';
    case InvalidPairs = 'INVALID_PAIRS';
    case InvalidCompetitorDomain = 'INVALID_COMPETITOR_DOMAIN';
    case ValueTooLong = 'VALUE_TOO_LONG';

    public function message(): string
    {
        return match ($this) {
            self::EmptyRow => 'The line carries no value at all.',
            self::MissingProductRef => 'product_ref is required and must not be empty.',
            self::InvalidPseId => 'pse_id must be a positive integer.',
            self::InvalidTargetPrice => 'target_price must be a number greater than zero, written with a dot.',
            self::MissingCurrencyWithPrice => 'currency_code is required as soon as target_price is given.',
            self::InvalidCurrencyCode => 'currency_code must be three letters.',
            self::InvalidFlag => 'The flag must be 0 or 1.',
            self::InvalidLocale => 'locale must look like fr_FR.',
            self::InvalidPairs => 'Pairs must be written Name:Value and separated by a pipe.',
            self::InvalidCompetitorDomain => 'Each competitor domain must be a resolvable domain name.',
            self::ValueTooLong => 'An identifying value is longer than its column allows and must not be silently truncated.',
        };
    }
}
