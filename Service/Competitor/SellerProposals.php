<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Source\SellerOffer;
use Thelia\Model\ConfigQuery;

/**
 * Turns the sellers Google Shopping showed for our products into competitor
 * proposals. Nothing is asked of anyone: the sellers are already in the answer
 * a collect pass paid for.
 *
 * Google gives a seller's name, and the site only sometimes (a written domain
 * such as "Fnac.com", or a URL that is not Google's own redirect). A seller
 * without an identifiable site is left alone: guessing a domain from a name
 * would invent shops. A proposal waits for a human like any other, and a
 * domain on any list, the blacklist included, is never proposed again.
 */
final readonly class SellerProposals
{
    public const SOURCE = 'shopping';

    public function __construct(
        private CompetitorRepository $competitors,
        private DomainNormalizer $domains,
        private TextNormalizer $text,
        private DomainResolver $resolver,
    ) {
    }

    public function domainOf(SellerOffer $offer): ?string
    {
        foreach ([$offer->domain, $offer->url] as $candidate) {
            $domain = $this->domains->registrableDomain($candidate);

            if ($domain !== null && !$this->isGoogle($domain)) {
                return $domain;
            }
        }

        if (preg_match_all('/\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,}\b/', strtolower($this->text->foldAccents($offer->sellerName)), $matches) > 0) {
            foreach ($matches[0] as $written) {
                $domain = $this->domains->registrableDomain($written);

                if ($domain !== null && !$this->isGoogle($domain)) {
                    return $domain;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, array{name: string, offers: int}> $sellers by registrable domain
     */
    public function propose(array $sellers): DiscoveryReport
    {
        $report = new DiscoveryReport();
        $known = $this->competitors->knownDomains();
        $ownDomain = $this->domains->registrableDomain(trim((string) ConfigQuery::read('url_site', '')));

        foreach ($sellers as $domain => $seen) {
            $domain = (string) $domain;

            if ($domain === $ownDomain) {
                $report->invalid[] = $domain;
                continue;
            }

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
                displayName: $seen['name'],
                evidence: [
                    'reason' => 'Seller shown by Google Shopping',
                    'offers' => $seen['offers'],
                    'suggested_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
                ],
            );
            $report->proposed[] = $domain;
        }

        return $report;
    }

    private function isGoogle(string $domain): bool
    {
        return $domain === 'google.com' || str_starts_with($domain, 'google.') || str_ends_with($domain, '.google.com') || $domain === 'gstatic.com';
    }
}
