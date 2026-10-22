<?php

declare(strict_types=1);

namespace PriceWatch\Service\Admin;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Settings\PriceWatchSettings;

/**
 * Shapes the competitor lists for the template, so the template only prints.
 */
final readonly class CompetitorPagePresenter
{
    public function __construct(
        private CompetitorRepository $competitors,
        private PriceWatchSettings $settings,
        private SitemapIndex $sitemaps,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $proposed = array_map($this->row(...), $this->competitors->byStatus(CompetitorStatus::Proposed));
        $whitelist = array_map(fn (PwCompetitor $competitor): array => [...$this->row($competitor), 'sitemap' => $this->sitemap((string) $competitor->getDomain())], [
            ...$this->competitors->byStatus(CompetitorStatus::Watched),
            ...$this->competitors->byStatus(CompetitorStatus::Paused),
        ]);
        $blacklist = array_map($this->row(...), $this->competitors->byStatus(CompetitorStatus::Rejected));

        return [
            'proposed' => $proposed,
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'llmReady' => $this->settings->isLlmReady(),
            'llmModel' => $this->settings->llmModel(),
            'webSearch' => $this->settings->webSearchEnabled(),
        ];
    }

    /**
     * Source URLs come from the model's answer and end up in an href. Twig
     * escaping stops markup, not a javascript: URL, so only http(s) survives.
     *
     * @return list<array{url: string, title: string}>
     */
    public function safeSources(mixed $sources): array
    {
        $safe = [];

        foreach (\is_array($sources) ? $sources : [] as $source) {
            $url = \is_array($source) && \is_string($source['url'] ?? null) ? trim($source['url']) : '';

            if (preg_match('#^https?://[^\s"\'<>]+$#i', $url) !== 1) {
                continue;
            }

            $safe[] = ['url' => $url, 'title' => mb_substr(\is_string($source['title'] ?? null) ? $source['title'] : '', 0, 120)];

            if (\count($safe) === 5) {
                break;
            }
        }

        return $safe;
    }

    /**
     * Whether the direct pass can find the competitor's pages: without
     * addresses from its sitemaps only Google Shopping covers it.
     *
     * @return array{problem: ?string, detail: ?string, urls: int, checkedAt: string}|null null before the first direct pass with discovery
     */
    public function sitemap(string $domain): ?array
    {
        $diagnosis = $this->sitemaps->diagnosis($domain);

        return $diagnosis === null ? null : [
            'problem' => $diagnosis['problem'],
            'detail' => $diagnosis['detail'],
            'urls' => $diagnosis['urls'],
            'checkedAt' => $diagnosis['checkedAt']->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PwCompetitor $competitor): array
    {
        $evidence = json_decode((string) $competitor->getDiscoveryEvidenceJson(), true);
        $evidence = \is_array($evidence) ? $evidence : [];
        $offers = PwCompetitorOfferQuery::create()->filterByCompetitorId($competitor->getId())->count();

        return [
            'id' => (int) $competitor->getId(),
            'domain' => (string) $competitor->getDomain(),
            'name' => $competitor->getDisplayName(),
            'status' => (string) $competitor->getStatus(),
            'source' => (string) $competitor->getDiscoverySource(),
            'reason' => \is_string($evidence['reason'] ?? null) ? $evidence['reason'] : null,
            'sources' => $this->safeSources($evidence['sources'] ?? null),
            'model' => \is_string($evidence['model'] ?? null) ? $evidence['model'] : null,
            'decidedBy' => $competitor->getValidatedBy(),
            'decidedAt' => $competitor->getValidatedAt('d/m/Y H:i'),
            'createdAt' => $competitor->getCreatedAt('d/m/Y H:i'),
            'offers' => $offers,
            // Deleting a competitor with offers would cascade into its price
            // history, so only an untouched entry can be deleted outright.
            'deletable' => $offers === 0,
        ];
    }
}
