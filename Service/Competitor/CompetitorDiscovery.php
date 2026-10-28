<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Usage\UsageLedger;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiCapability;
use Thelia\Domain\Ai\AiGatewayInterface;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AsAiUsage;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;

/**
 * Asks the reasoning model for competitor candidates, then lets the code
 * decide what is kept.
 *
 * The model suggests; it never decides. Every candidate lands as proposed and
 * waits for a human. The code throws out what it can rule out on its own: a
 * malformed domain, our own shop, a domain already on either list, and a
 * domain that does not resolve. A blacklisted domain is never proposed again,
 * whatever the model says.
 *
 * The model is reached through the gateway of the core, which holds the
 * connection, the key, the budget and the log. It has no web access: the
 * suggestions come from what it learned in training, which is dated and can
 * invent shops, hence the DNS check and the human decision. The merchant turns
 * the function on in the module settings (off by default).
 */
#[AsAiUsage(
    id: DiscoveryPrompt::USAGE,
    purpose: 'Suggests direct competitor shops from a sample of the catalogue, for an administrator to accept or refuse.',
    audience: AiAudience::Admin,
    tier: AiTier::Standard,
    capabilities: [AiCapability::StructuredOutput],
)]
final readonly class CompetitorDiscovery
{
    public const SOURCE = 'llm';
    public const PASS = 'suggest';
    public const DEFAULT_MAXIMUM = 15;

    public function __construct(
        private AiGatewayInterface $gateway,
        private PriceWatchSettings $settings,
        private CatalogSampleSource $catalog,
        private CompetitorRepository $competitors,
        private DomainNormalizer $domainNormalizer,
        private DomainResolver $resolver,
        private DiscoveryPrompt $prompt,
        private DiscoveryResponseParser $parser,
        private UsageLedger $ledger,
    ) {
    }

    /**
     * @throws DiscoveryException
     */
    public function suggest(string $locale, int $maximum = self::DEFAULT_MAXIMUM): DiscoveryReport
    {
        if (!$this->settings->discoveryEnabled()) {
            throw DiscoveryException::disabled();
        }

        $sample = $this->catalog->sample($locale);
        $known = $this->competitors->knownDomains();
        $ownDomain = $this->domainNormalizer->registrableDomain($sample->shopUrl);

        try {
            $answer = $this->gateway->complete($this->prompt->request(
                $sample,
                $this->settings->shopDescription(),
                array_values(array_filter([...array_keys($known), $ownDomain])),
                $maximum,
            ));
        } catch (AiExceptionInterface $refusal) {
            throw DiscoveryException::refused($refusal);
        }

        // Paid as soon as answered, whatever the answer turns out to be worth.
        $this->ledger->recordModelCall(self::PASS, $answer);

        $candidates = $this->parser->parse($answer->text) ?? throw DiscoveryException::malformed('no competitor list in the answer');

        $report = new DiscoveryReport();
        $report->model = $answer->model;
        $report->promptTokens = $answer->tokenUsage->inputTokens;
        $report->completionTokens = $answer->tokenUsage->outputTokens;

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
                    'suggested_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ],
            );
            $report->proposed[] = $domain;
        }

        return $report;
    }
}
