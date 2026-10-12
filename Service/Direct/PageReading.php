<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

final readonly class PageReading
{
    public function __construct(
        public PageOutcome $outcome,
        public ?ProductPage $page = null,
        public ?string $detail = null,
    ) {
    }
}
