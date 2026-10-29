<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use PriceWatch\Service\Competitor\DomainResolver;

final readonly class FixedDomainResolver implements DomainResolver
{
    /**
     * @param list<string> $unresolved
     */
    public function __construct(private array $unresolved = [])
    {
    }

    public function resolves(string $domain): bool
    {
        return !\in_array($domain, $this->unresolved, true);
    }
}
