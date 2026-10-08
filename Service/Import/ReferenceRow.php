<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Service\Normalizer\NormalizedGtin;
use PriceWatch\Service\Normalizer\NormalizedSize;

final readonly class ReferenceRow
{
    /**
     * @param array<string, string> $attributePairs
     * @param array<string, string> $featurePairs
     * @param list<string>          $competitorDomains
     */
    public function __construct(
        public int $lineNumber,
        public string $productRef,
        public ?int $pseId,
        public ?string $pseRef,
        public NormalizedGtin $gtin,
        public ?string $mpn,
        public ?string $manufacturerRef,
        public ?string $brandLabel,
        public ?string $brandNormalized,
        public array $attributePairs,
        public NormalizedSize $size,
        public ?string $colorLabel,
        public array $featurePairs,
        public ?string $currencyCode,
        public ?string $targetPrice,
        public int $priceVatIncluded,
        public int $monitored,
        public array $competitorDomains,
        public string $locale,
        public ?string $notes,
        public string $checksum,
    ) {
    }
}
