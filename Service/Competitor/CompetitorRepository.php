<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorQuery;

final readonly class CompetitorRepository
{
    /**
     * @return list<PwCompetitor>
     */
    public function byStatus(CompetitorStatus $status): array
    {
        return PwCompetitorQuery::create()
            ->filterByStatus($status->value)
            ->orderByDomain()
            ->find()
            ->getData();
    }

    public function find(int $id): ?PwCompetitor
    {
        return PwCompetitorQuery::create()->findPk($id);
    }

    public function findByDomain(string $domain): ?PwCompetitor
    {
        return PwCompetitorQuery::create()->filterByDomain($domain)->findOne();
    }

    /**
     * @return array<string, CompetitorStatus> every known domain, whatever its list
     */
    public function knownDomains(): array
    {
        $known = [];

        foreach (PwCompetitorQuery::create()->find() as $competitor) {
            $known[(string) $competitor->getDomain()] = CompetitorStatus::tryFrom((string) $competitor->getStatus()) ?? CompetitorStatus::Proposed;
        }

        return $known;
    }

    /**
     * @param array<string, mixed>|null $evidence
     */
    public function create(
        string $domain,
        CompetitorStatus $status,
        string $source,
        ?string $displayName = null,
        ?array $evidence = null,
        ?string $decidedBy = null,
    ): PwCompetitor {
        $competitor = (new PwCompetitor())
            ->setDomain($domain)
            ->setDisplayName($displayName === null ? null : mb_substr($displayName, 0, 190))
            ->setStatus($status->value)
            ->setDiscoverySource($source)
            ->setDiscoveryEvidenceJson($evidence === null ? null : json_encode($evidence, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        if ($decidedBy !== null) {
            $competitor->setValidatedBy(mb_substr($decidedBy, 0, 100))->setValidatedAt(new \DateTime());
        }

        $competitor->save();

        return $competitor;
    }

    public function changeStatus(PwCompetitor $competitor, CompetitorStatus $status, string $decidedBy): void
    {
        $competitor
            ->setStatus($status->value)
            ->setValidatedBy(mb_substr($decidedBy, 0, 100))
            ->setValidatedAt(new \DateTime())
            ->save();
    }

    public function delete(PwCompetitor $competitor): void
    {
        $competitor->delete();
    }
}
