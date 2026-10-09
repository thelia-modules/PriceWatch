<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorQuery;

/**
 * Turns the competitor_domains column into competitor rows.
 *
 * Always in status proposed, never watched: a domain only starts being
 * collected once a human has validated it in the back-office. A domain already
 * rejected is left rejected, so an import cannot resurrect a decision someone
 * took on purpose.
 */
final readonly class CompetitorSeeder
{
    public const STATUS_PROPOSED = 'proposed';

    /**
     * @param list<string> $domains
     *
     * @return int how many competitors this call created
     */
    public function seed(array $domains): int
    {
        $created = 0;

        foreach ($domains as $domain) {
            if (PwCompetitorQuery::create()->filterByDomain($domain)->count() > 0) {
                continue;
            }

            (new PwCompetitor())
                ->setDomain($domain)
                ->setStatus(self::STATUS_PROPOSED)
                ->setDiscoverySource('import')
                ->save();

            ++$created;
        }

        return $created;
    }
}
