<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

/**
 * Finds which whitelisted competitor a Google Shopping seller is.
 *
 * Observed live (2026-10-09, 10 sellers of a real card): domain is empty on
 * every seller, and url is a Google redirect ("file:///goto?url=..."). Only
 * seller_name says who sells: "Fnac.com", "Darty", "E.Leclerc",
 * "Carrefour - Carrefour.fr". So a seller is matched on its name against the
 * first label of the competitor's domain, both folded to letters and digits:
 * "Dafy Moto" and dafy-moto.com meet on "dafymoto".
 *
 * Equality only, never containment: "Moto" must not match every domain with
 * "moto" in it. A name that does not fold to a competitor's label stays
 * unknown and is reported, so a miss is visible rather than a wrong match.
 */
final readonly class SellerMatcher
{
    private const COUNTRY_SUFFIX = '/\s+(fr|france|be|belgique|ch|suisse|eu|europe)$/iu';

    /** @var array<string, int> folded label => competitor id */
    private array $byLabel;

    /** @var array<string, int> registrable domain => competitor id */
    private array $byDomain;

    /**
     * @param array<string, int> $competitors registrable domain => competitor id
     */
    public function __construct(
        array $competitors,
        private DomainNormalizer $domains,
        private TextNormalizer $text,
    ) {
        $byLabel = [];
        $byDomain = [];

        foreach ($competitors as $domain => $id) {
            $byDomain[$domain] = $id;
            $label = $this->fold(explode('.', $domain)[0]);

            // Two watched domains folding to the same label (shop.fr and
            // shop.com) cannot be told apart by name: the first one keeps it.
            if ($label !== '' && !isset($byLabel[$label])) {
                $byLabel[$label] = $id;
            }
        }

        $this->byLabel = $byLabel;
        $this->byDomain = $byDomain;
    }

    public function competitorOf(SellerOffer $offer): ?int
    {
        foreach ([$offer->domain, $offer->url] as $candidate) {
            $domain = $this->domains->registrableDomain($candidate);

            if ($domain !== null && isset($this->byDomain[$domain])) {
                return $this->byDomain[$domain];
            }
        }

        foreach ($this->nameKeys($offer->sellerName) as $key) {
            if (isset($this->byLabel[$key])) {
                return $this->byLabel[$key];
            }
        }

        return null;
    }

    /**
     * The whole name, each " - " part of it, and the first label of any
     * domain written in it.
     *
     * @return list<string>
     */
    public function nameKeys(string $sellerName): array
    {
        $keys = [$this->fold($sellerName)];

        foreach (preg_split('/\s+[-|–]\s+/u', $sellerName) ?: [] as $part) {
            $keys[] = $this->fold($part);
            // Observed live: "Dafy Moto FR", the country store of a chain.
            $keys[] = $this->fold(preg_replace(self::COUNTRY_SUFFIX, '', $part) ?? $part);
        }

        if (preg_match_all('/\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,}\b/', $this->text->foldAccents($sellerName), $matches) > 0) {
            foreach ($matches[0] as $written) {
                $domain = $this->domains->registrableDomain($written);

                if ($domain !== null) {
                    $keys[] = $this->fold(explode('.', $domain)[0]);
                }
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    private function fold(string $value): string
    {
        return str_replace(' ', '', $this->text->normalize($value));
    }
}
