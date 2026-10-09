<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

interface CatalogSampleSource
{
    public function sample(string $locale): CatalogSample;
}
