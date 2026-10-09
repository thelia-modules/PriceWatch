<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

/**
 * A language model can name a shop that does not exist, or no longer does.
 * A domain with no address record is not a competitor anyone can visit, so
 * the code drops it before a human ever has to look at it.
 */
final readonly class DnsDomainResolver implements DomainResolver
{
    public function resolves(string $domain): bool
    {
        return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA') || checkdnsrr('www.'.$domain, 'A');
    }
}
