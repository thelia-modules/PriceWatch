<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

final readonly class SaleElementResolution
{
    private function __construct(
        public ?int $saleElementId,
        public ?int $productId,
        public ?ResolutionMethod $method,
        public ?ResolutionCode $failure,
    ) {
    }

    public static function attached(int $saleElementId, int $productId, ResolutionMethod $method): self
    {
        return new self($saleElementId, $productId, $method, null);
    }

    public static function failed(ResolutionCode $code): self
    {
        return new self(null, null, null, $code);
    }

    public function isAttached(): bool
    {
        return $this->saleElementId !== null;
    }
}
