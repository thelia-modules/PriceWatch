<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\SizeNormalizer;

/**
 * Which of our variants a competitor page prices, by proof only:
 *
 * - one of our GTINs on the page: that variant, wherever the page comes from;
 * - otherwise, on a page a human or a GTIN confirmed as our product, a size
 *   stated once, in a single colour, on a page that lists no GTIN at all.
 *   A page that lists GTINs but not ours sells something else.
 *
 * Anything else matches nothing: Jev or a human decide, never this class.
 */
final readonly class DirectMatcher
{
    public const BY_GTIN = 'ean';
    public const BY_SIZE = 'size';

    public function __construct(private SizeNormalizer $sizes)
    {
    }

    /**
     * @param array<int, array{gtin: ?string, size: ?string}> $ours identity id => valid 14-digit GTIN and size label
     *
     * @return array<int, array{variant: PageVariant, method: string}> identity id => match
     */
    public function match(ProductPage $page, bool $confirmed, array $ours): array
    {
        $byGtin = [];

        foreach ($page->variants as $variant) {
            if ($variant->gtin !== null) {
                $byGtin[$variant->gtin] = $variant;
            }
        }

        $matches = [];

        foreach ($ours as $identityId => $identity) {
            if ($identity['gtin'] !== null && isset($byGtin[$identity['gtin']])) {
                $matches[$identityId] = ['variant' => $byGtin[$identity['gtin']], 'method' => self::BY_GTIN];
            }
        }

        if (!$confirmed || $byGtin !== [] || !$this->singleColour($page)) {
            return $matches;
        }

        foreach ($ours as $identityId => $identity) {
            $ourSize = $this->sizes->normalize($identity['size']);
            $same = array_values(array_filter($page->variants, fn (PageVariant $variant): bool => $variant->size !== null && $ourSize->matches($this->sizes->normalize($variant->size))));

            if (\count($same) === 1) {
                $matches[$identityId] = ['variant' => $same[0], 'method' => self::BY_SIZE];
            }
        }

        ksort($matches);

        return $matches;
    }

    private function singleColour(ProductPage $page): bool
    {
        $colours = [];

        foreach ($page->variants as $variant) {
            if ($variant->colour !== null) {
                $colours[mb_strtolower($variant->colour)] = true;
            }
        }

        return \count($colours) <= 1;
    }
}
