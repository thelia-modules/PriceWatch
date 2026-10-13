<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\GtinNormalizer;

/**
 * schema.org Product and ProductGroup in JSON-LD, the structured data Google
 * asks merchants for. Seen live (2026-10-12) on Dafy: a ProductGroup whose
 * hasVariant lists every size, each with its EAN-13 in `sku`, its price and
 * its availability.
 */
final readonly class JsonLdExtractor implements PageExtractor
{
    private const PRODUCT_TYPES = ['Product', 'ProductGroup', 'IndividualProduct', 'ProductModel'];

    public function __construct(private GtinNormalizer $gtin)
    {
    }

    public function name(): string
    {
        return 'jsonld';
    }

    public function extract(string $html, string $url): ?ProductPage
    {
        $products = [];

        foreach (self::blocks($html) as $block) {
            $this->collectProducts($block, $products);
        }

        $main = self::mainProduct($products, $url);

        if ($main === null) {
            return null;
        }

        $variants = [];

        foreach (self::isType($main, 'ProductGroup') ? self::list($main['hasVariant'] ?? []) : [$main] as $product) {
            if (\is_array($product) && ($variant = $this->variant($product, $main)) !== null) {
                $variants[] = $variant;
            }
        }

        return $variants === [] ? null : new ProductPage($this->name(), PageValues::text($main['name'] ?? null), null, $variants);
    }

    /**
     * @return list<mixed>
     */
    private static function blocks(string $html): array
    {
        preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches);
        $blocks = [];

        foreach ($matches[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), \ENT_QUOTES | \ENT_HTML5), true);

            if (\is_array($data)) {
                $blocks[] = $data;
            }
        }

        return $blocks;
    }

    /**
     * Top-level products only: the variants of a ProductGroup are its own.
     *
     * @param list<array<array-key, mixed>> $products
     */
    private function collectProducts(mixed $node, array &$products): void
    {
        if (!\is_array($node)) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                $this->collectProducts($item, $products);
            }

            return;
        }

        if (array_intersect(self::types($node), self::PRODUCT_TYPES) !== []) {
            $products[] = $node;

            return;
        }

        $this->collectProducts($node['@graph'] ?? null, $products);
    }

    /**
     * The product of this page: the one that names this page, or the only
     * one, provided it names no other page. A redirect to another product
     * must never pass for this one.
     *
     * @param list<array<array-key, mixed>> $products
     *
     * @return array<array-key, mixed>|null
     */
    private static function mainProduct(array $products, string $url): ?array
    {
        $named = array_values(array_filter($products, static fn (array $product): bool => PageValues::samePage(self::declaredUrl($product), $url)));

        if (\count($named) === 1) {
            return $named[0];
        }

        if (\count($products) === 1 && self::declaredUrl($products[0]) === null) {
            return $products[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function declaredUrl(array $product): ?string
    {
        foreach ([$product['url'] ?? null, ...array_map(static fn (mixed $offer): mixed => \is_array($offer) ? ($offer['url'] ?? null) : null, self::list($product['offers'] ?? []))] as $url) {
            if (\is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed> $group
     */
    private function variant(array $product, array $group): ?PageVariant
    {
        $offers = array_values(array_filter(self::list($product['offers'] ?? []), \is_array(...)));

        // A price range is no price; several offers at several prices for one
        // variant cannot be told apart.
        $prices = [];
        $offer = null;

        foreach ($offers as $candidate) {
            if (self::isType($candidate, 'AggregateOffer')) {
                return null;
            }

            $price = PageValues::price($candidate['price'] ?? null);

            if ($price !== null) {
                $prices[$price] = true;
                $offer ??= $candidate;
            }
        }

        if ($offer === null || \count($prices) !== 1) {
            return null;
        }

        return new PageVariant(
            price: (string) array_key_first($prices),
            currency: \is_string($offer['priceCurrency'] ?? null) ? strtoupper(trim($offer['priceCurrency'])) : null,
            size: PageValues::text($product['size'] ?? null),
            colour: PageValues::text($product['color'] ?? $group['color'] ?? null),
            gtin: PageValues::gtin($this->gtin, $product['gtin13'] ?? null, $product['gtin14'] ?? null, $product['gtin12'] ?? null, $product['gtin8'] ?? null, $product['gtin'] ?? null, $product['sku'] ?? null),
            inStock: self::inStock($offer['availability'] ?? null),
            reference: PageValues::text($product['sku'] ?? null),
        );
    }

    private static function inStock(mixed $availability): ?bool
    {
        if (!\is_string($availability)) {
            return null;
        }

        return match (true) {
            preg_match('/(InStock|InStoreOnly|LimitedAvailability|OnlineOnly)$/i', $availability) === 1 => true,
            preg_match('/(OutOfStock|SoldOut|Discontinued)$/i', $availability) === 1 => false,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function types(array $node): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $type): ?string => \is_string($type) ? preg_replace('#^.*[/:]#', '', $type) : null,
            self::list($node['@type'] ?? []),
        )));
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function isType(array $node, string $type): bool
    {
        return \in_array($type, self::types($node), true);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if (!\is_array($value)) {
            return $value === null ? [] : [$value];
        }

        return array_is_list($value) ? $value : [$value];
    }
}
