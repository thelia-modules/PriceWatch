<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\GtinNormalizer;

/**
 * The state a Nuxt shop embeds in `__NUXT_DATA__`: a flat array where every
 * value refers to others by index. Seen live (2026-10-12) on Motoblouz: the
 * page product carries its sizes in `skus`, each with its selling price, its
 * struck-through price and its stock, while the JSON-LD gives one price and
 * no size.
 *
 * The same state also holds the recommended products, with the same shape
 * (32 of the 37 SKUs of that page): only the SKUs of the object whose `url`
 * is this page are read.
 */
final readonly class NuxtStateExtractor implements PageExtractor
{
    /** devalue wrappers that only box their value. */
    private const WRAPPERS = ['Reactive', 'ShallowReactive', 'Ref', 'ShallowRef'];

    private const MAX_HOPS = 8;

    public function __construct(private GtinNormalizer $gtin)
    {
    }

    public function name(): string
    {
        return 'nuxt';
    }

    public function extract(string $html, string $url): ?ProductPage
    {
        if (preg_match('#<script[^>]+id=["\']__NUXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $match) !== 1) {
            return null;
        }

        $state = json_decode($match[1], true);

        if (!\is_array($state) || !array_is_list($state)) {
            return null;
        }

        $owners = [];

        foreach ($state as $node) {
            if (!self::isObject($node) || !isset($node['url']) || !PageValues::samePage(self::string($this->value($state, $node['url'])), $url)) {
                continue;
            }

            foreach ($node as $field) {
                $skus = $this->value($state, $field);

                if (\is_array($skus) && array_is_list($skus) && $skus !== [] && $this->areSkus($state, $skus)) {
                    $owners[serialize($skus)] = [$node, $skus];
                }
            }
        }

        // Two objects of this page with different SKU lists: which is the
        // product cannot be told.
        if (\count($owners) !== 1) {
            return null;
        }

        [$owner, $skus] = array_values($owners)[0];
        $variants = [];

        foreach ($skus as $reference) {
            if (($variant = $this->variant($state, $reference)) !== null) {
                $variants[] = $variant;
            }
        }

        return $variants === [] ? null : new ProductPage($this->name(), self::string($this->value($state, $owner['name'] ?? null)), null, $variants);
    }

    /**
     * @param list<mixed> $state
     * @param list<mixed> $skus
     */
    private function areSkus(array $state, array $skus): bool
    {
        foreach ($skus as $reference) {
            if ($this->path($state, $reference, ['price', 'sellPrice', 'inclTax']) === null || !self::isObject($this->path($state, $reference, ['attributes']))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<mixed> $state
     */
    private function variant(array $state, mixed $reference): ?PageVariant
    {
        if ($this->path($state, $reference, ['forSale']) === false) {
            return null;
        }

        $price = PageValues::price($this->path($state, $reference, ['price', 'sellPrice', 'inclTax']));

        if ($price === null) {
            return null;
        }

        $listPrice = PageValues::price($this->path($state, $reference, ['price', 'publicPrice', 'inclTax']));
        $stock = $this->path($state, $reference, ['stocks', 'WEB', 'stock']);

        return new PageVariant(
            price: $price,
            size: PageValues::text($this->path($state, $reference, ['attributes', 'size'])),
            colour: PageValues::text($this->path($state, $reference, ['attributes', 'color'])),
            gtin: PageValues::gtin(
                $this->gtin,
                $this->path($state, $reference, ['ean']),
                $this->path($state, $reference, ['gtin']),
                $this->path($state, $reference, ['barcode']),
                $this->path($state, $reference, ['supplierReference']),
            ),
            listPrice: $listPrice !== null && (float) $listPrice > (float) $price ? $listPrice : null,
            inStock: \is_bool($stock) ? $stock : null,
            reference: self::string($this->path($state, $reference, ['reference'])),
        );
    }

    /**
     * @param list<mixed>  $state
     * @param list<string> $keys
     */
    private function path(array $state, mixed $reference, array $keys): mixed
    {
        $value = $this->value($state, $reference);

        foreach ($keys as $key) {
            if (!self::isObject($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $this->value($state, $value[$key]);
        }

        return $value;
    }

    /**
     * The value an index refers to, unboxed from reactive wrappers. Negative
     * indexes are devalue's undefined, holes and infinities: no value.
     *
     * @param list<mixed> $state
     */
    private function value(array $state, mixed $reference): mixed
    {
        for ($hops = 0; $hops < self::MAX_HOPS; ++$hops) {
            if (!\is_int($reference) || $reference < 0 || !\array_key_exists($reference, $state)) {
                return null;
            }

            $value = $state[$reference];

            if (\is_array($value) && array_is_list($value) && \count($value) === 2 && \in_array($value[0], self::WRAPPERS, true)) {
                $reference = $value[1];
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return \is_array($value) && $value !== [] && !array_is_list($value);
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
