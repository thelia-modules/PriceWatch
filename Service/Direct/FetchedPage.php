<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

final readonly class FetchedPage
{
    public function __construct(
        /** Where the page ended up after redirects: the address its data must name. */
        public string $url,
        public int $status,
        public ?string $html,
        public ?PageOutcome $failure = null,
        public ?string $error = null,
    ) {
    }
}
