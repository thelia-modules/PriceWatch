<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

final readonly class ImportOptions
{
    public function __construct(
        public bool $dryRun = false,
        public ?string $locale = null,
        public bool $deactivateMissing = false,
    ) {
    }
}
