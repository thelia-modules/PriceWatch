<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

interface PageExtractor
{
    public function name(): string;

    /**
     * The product of the page at $url, or null when this extractor finds none
     * that is certainly this page's.
     */
    public function extract(string $html, string $url): ?ProductPage;
}
