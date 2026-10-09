<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

interface DomainResolver
{
    public function resolves(string $domain): bool;
}
