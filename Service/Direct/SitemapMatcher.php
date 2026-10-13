<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\TextNormalizer;

/**
 * Which pages of a competitor's sitemap may sell one of our products, read
 * from the address alone: the shops name their product pages after brand,
 * model and often colour ("casque-integral-arai-tour-x5-diamond-white").
 *
 * A guess, never a proof: the pages kept are candidates that the direct pass
 * reads, and only a GTIN of ours or Jev confirm. So the rules only throw out
 * what cannot be ours: another brand, another family (gloves for a jacket),
 * a spare part, a model word missing.
 *
 * Measured on 2026-10-13 against the sitemaps of Motoblouz, Dafy and iCasque:
 * the right page was among the first three for most of our 20 products.
 */
final readonly class SitemapMatcher
{
    public const PER_COMPETITOR = 3;

    private const FAMILIES = [
        'helmet' => ['casque', 'casques', 'helmet', 'helmets'],
        'jacket' => ['blouson', 'blousons', 'veste', 'vestes', 'jacket', 'jackets'],
        'gloves' => ['gants', 'gant', 'gloves', 'glove'],
        'trousers' => ['pantalon', 'pantalons', 'pant', 'pants', 'jean', 'jeans'],
        'boots' => ['bottes', 'botte', 'boots', 'baskets', 'chaussures', 'sneakers'],
    ];

    /** Pages of parts and accessories for a product: never the product itself. */
    private const PARTS = ['ecran', 'visiere', 'mousse', 'mousses', 'coiffe', 'joues', 'piece', 'detachee', 'pinlock', 'intercom', 'kit', 'housse', 'sac', 'casquette', 'spoiler', 'support', 'bluetooth'];

    /** Our colour words, with the ones shops use for them in English. */
    private const COLOURS = [
        'noir' => ['black'], 'blanc' => ['white'], 'gris' => ['grey', 'gray'], 'marron' => ['brown'],
        'creme' => ['cream'], 'beige' => [], 'kaki' => ['khaki'], 'rouge' => ['red'], 'bleu' => ['blue'],
        'vert' => ['green'], 'jaune' => ['yellow'], 'orange' => [], 'rose' => ['pink'], 'violet' => ['purple'],
        'brillant' => ['gloss', 'shiny'], 'mat' => ['matt', 'matte'], 'nacre' => ['pearly', 'pearl'],
        'clair' => ['light'], 'fonce' => ['dark'], 'black' => ['noir'], 'white' => ['blanc'], 'gloss' => ['brillant'],
    ];

    /** Words that say nothing about which article it is. */
    private const GENERIC = ['moto', 'vente', 'jet', 'integral', 'modulable', 'cross', 'uni', 'cuir', 'textile', 'leather', 'the', 'and', 'de', 'du', 'la', 'le', 'les', 'des', 'en', 'pour', 'avec', 'et'];

    public function __construct(private TextNormalizer $text)
    {
    }

    /**
     * @param iterable<string> $urls the competitor's sitemap
     *
     * @return list<string> at most PER_COMPETITOR addresses, the likeliest first
     */
    public function match(string $ourTitle, ?string $brand, iterable $urls): array
    {
        $titleTokens = $this->tokens($ourTitle);
        $brandTokens = $this->tokens((string) $brand);
        $family = $this->family($titleTokens);
        $familyWords = $family === null ? [] : self::FAMILIES[$family];
        $colours = $this->colourWords($titleTokens);
        $model = array_values(array_diff($titleTokens, $brandTokens, $familyWords, array_keys(self::COLOURS), self::GENERIC, ['femme', 'homme', 'lady']));

        if ($model === [] || $brandTokens === []) {
            return [];
        }

        // Long names lose a word in the shop's address now and then.
        $required = \count($model) >= 3 ? \count($model) - 1 : \count($model);
        $known = array_merge($titleTokens, $brandTokens, $familyWords, $colours, self::GENERIC, ['femme', 'homme', 'lady']);
        $scored = [];

        foreach ($urls as $url) {
            // Cheap first: the brand must be somewhere in the address.
            if (stripos($url, $brandTokens[0]) === false) {
                continue;
            }

            $slug = $this->slug($url);

            if (array_diff($brandTokens, $slug) !== []
                || array_intersect(self::PARTS, $slug) !== [] && array_intersect(self::PARTS, $titleTokens) === []
                || !$this->sameFamily($family, $slug)) {
                continue;
            }

            $matchedModel = \count(array_intersect($model, $slug));

            if ($matchedModel < $required) {
                continue;
            }

            $score = 10 * $matchedModel
                + 3 * \count(array_intersect($colours, $slug))
                - \count(array_diff($slug, $known));
            $scored[] = [$score, -\strlen($url), $url];
        }

        rsort($scored);

        return \array_slice(array_values(array_unique(array_column($scored, 2))), 0, self::PER_COMPETITOR);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = $this->text->normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /**
     * The last segment of the path, without its extension nor the shop's
     * numeric id.
     *
     * @return list<string>
     */
    private function slug(string $url): array
    {
        $path = rtrim((string) parse_url($url, \PHP_URL_PATH), '/');
        $last = (string) preg_replace('/\.html?$/i', '', substr($path, (int) strrpos($path, '/') + 1));

        return array_values(array_filter($this->tokens($last), static fn (string $token): bool => preg_match('/^\d{4,}$/', $token) !== 1));
    }

    /**
     * @param list<string> $tokens
     */
    private function family(array $tokens): ?string
    {
        foreach (self::FAMILIES as $family => $words) {
            if (array_intersect($words, $tokens) !== []) {
                return $family;
            }
        }

        return null;
    }

    /**
     * An address that names another family and not ours sells something else.
     *
     * @param list<string> $slug
     */
    private function sameFamily(?string $family, array $slug): bool
    {
        if ($family === null) {
            return true;
        }

        $theirs = array_keys(array_filter(self::FAMILIES, static fn (array $words): bool => array_intersect($words, $slug) !== []));

        return $theirs === [] || \in_array($family, $theirs, true);
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string> our colour words and their English equivalents
     */
    private function colourWords(array $tokens): array
    {
        $words = [];

        foreach ($tokens as $token) {
            if (isset(self::COLOURS[$token])) {
                $words = [...$words, $token, ...self::COLOURS[$token]];
            }
        }

        return array_values(array_unique($words));
    }
}
