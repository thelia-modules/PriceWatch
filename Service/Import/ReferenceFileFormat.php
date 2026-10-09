<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * The one ordered list of columns, shared by the reader, the exporter and the
 * documentation. One format in and out is what makes the import verifiable:
 * export then import --dry-run must attach every line and refuse none.
 */
final readonly class ReferenceFileFormat
{
    public const COLUMNS = [
        'product_ref',
        'pse_id',
        'pse_ref',
        'ean_code',
        'mpn',
        'manufacturer_ref',
        'brand_title',
        'attribute_combination',
        'size_label',
        'color_label',
        'feature_values',
        'currency_code',
        'target_price',
        'price_vat_included',
        'monitored',
        'competitor_domains',
        'locale',
        'notes',
    ];

    public static function header(): string
    {
        return implode(CatalogCsvReader::DELIMITER, self::COLUMNS);
    }
}
