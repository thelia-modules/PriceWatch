<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use PriceWatch\Service\Llm\LlmException;
use PriceWatch\Service\Llm\OpenRouterClient;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;

/**
 * Asks the reasoning model for competitor candidates, then lets the code
 * decide what is kept.
 *
 * The model suggests; it never decides. Every candidate lands as proposed and
 * waits for a human. The code throws out what it can rule out on its own: a
 * malformed domain, our own shop, a domain already on either list, and a
 * domain that does not resolve. A blacklisted domain is never proposed again,
 * whatever the model says.
 */
final readonly class CompetitorDiscovery
{
    public const SOURCE = 'llm';
    public const DEFAULT_MAXIMUM = 15;

    public function __construct(
        private OpenRouterClient $client,
        private PriceWatchSettings $settings,
        private CatalogSampleSource $catalog,
        private CompetitorRepository $competitors,
        private DomainNormalizer $domainNormalizer,
        private DomainResolver $resolver,
        private DiscoveryPrompt $prompt,
        private DiscoveryResponseParser $parser,
    ) {
    }

    /**
     * @throws LlmException
     */
    public function suggest(string $locale, int $maximum = self::DEFAULT_MAXIMUM): DiscoveryReport
    {
        $sample = $this->catalog->sample($locale);
        $known = $this->competitors->knownDomains();
        $ownDomain = $this->domainNormalizer->registrableDomain($sample->shopUrl);

        $answer = $this->client->complete(
            $this->prompt->messages(
                $sample,
                $this->settings->shopDescription(),
                array_values(array_filter([...array_keys($known), $ownDomain])),
                $maximum,
            ),
            DiscoveryPrompt::WEB_SEARCH_PROMPT,
        );

        $candidates = $this->parser->parse($answer->content) ?? throw LlmException::malformed('no competitor list in the answer');

        $report = new DiscoveryReport();
        $report->model = $answer->model;
        $report->promptTokens = $answer->promptTokens;
        $report->completionTokens = $answer->completionTokens;
        $report->citations = \count($answer->citations);

        $seenThisRun = [];

        foreach ($candidates as $candidate) {
            if (\count($report->proposed) >= $maximum) {
                break;
            }

            $domain = $this->domainNormalizer->registrableDomain($candidate['domain']);

            if ($domain === null || $domain === $ownDomain) {
                $report->invalid[] = $candidate['domain'];
                continue;
            }

            if (isset($seenThisRun[$domain])) {
                continue;
            }

            $seenThisRun[$domain] = true;

            if (isset($known[$domain])) {
                if ($known[$domain] === CompetitorStatus::Rejected) {
                    $report->blacklisted[] = $domain;
                } else {
                    $report->alreadyKnown[] = $domain;
                }

                continue;
            }

            if (!$this->resolver->resolves($domain)) {
                $report->unresolved[] = $domain;
                continue;
            }

            $this->competitors->create(
                domain: $domain,
                status: CompetitorStatus::Proposed,
                source: self::SOURCE,
                displayName: $candidate['name'],
                evidence: [
                    'reason' => $candidate['reason'],
                    'model' => $answer->model,
                    'web_search' => $this->settings->webSearchEnabled(),
                    'sources' => \array_slice($answer->citations, 0, 10),
                    'suggested_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ],
            );
            $report->proposed[] = $domain;
        }

        return $report;
    }
}
