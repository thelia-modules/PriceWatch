<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

/**
 * Reads the prices of a competitor page, or nothing. Every extractor that
 * finds this page's product is heard; the one that tells sizes apart is kept,
 * and the others must agree with it on the price. What is not certain is not
 * read: a guessed price is worse than no price.
 */
final readonly class PageReader
{
    private const CURRENCY = 'EUR';

    /** @var list<PageExtractor> the most detailed first */
    private array $extractors;

    public function __construct(NuxtStateExtractor $nuxt, JsonLdExtractor $jsonLd)
    {
        $this->extractors = [$nuxt, $jsonLd];
    }

    public function read(string $html, string $url): PageReading
    {
        $pages = [];

        foreach ($this->extractors as $extractor) {
            $page = $extractor->extract($html, $url);

            if ($page !== null && $page->variants !== []) {
                $pages[] = $page;
            }
        }

        if ($pages === []) {
            return new PageReading(PageOutcome::NoProduct);
        }

        $best = self::mostDetailed($pages);
        $prices = array_flip(array_map(static fn (PageVariant $variant): string => $variant->price, $best->variants));

        foreach ($pages as $page) {
            foreach ($page->variants as $variant) {
                if (!isset($prices[$variant->price])) {
                    return new PageReading(PageOutcome::Contradictory, null, \sprintf('%s says %s, %s does not', $page->extractor, $variant->price, $best->extractor));
                }
            }
        }

        $currencies = [];

        foreach ($pages as $page) {
            foreach ($page->variants as $variant) {
                if ($variant->currency !== null) {
                    $currencies[$variant->currency] = true;
                }
            }
        }

        if (array_keys($currencies) !== [self::CURRENCY]) {
            return new PageReading(PageOutcome::NoCurrency, null, $currencies === [] ? 'no currency stated' : implode(', ', array_keys($currencies)));
        }

        $variants = self::unambiguous($best->variants);

        if ($variants === []) {
            return new PageReading(PageOutcome::Contradictory, null, 'every variant is stated at two prices');
        }

        return new PageReading(PageOutcome::Read, new ProductPage(
            $best->extractor,
            $best->name ?? $pages[\count($pages) - 1]->name,
            self::CURRENCY,
            $variants,
        ));
    }

    /**
     * @param non-empty-list<ProductPage> $pages
     */
    private static function mostDetailed(array $pages): ProductPage
    {
        foreach ($pages as $page) {
            foreach ($page->variants as $variant) {
                if ($variant->size !== null || $variant->gtin !== null) {
                    return $page;
                }
            }
        }

        return $pages[0];
    }

    /**
     * One variant stated twice at two prices cannot be read; stated twice at
     * one price, it is kept once.
     *
     * @param list<PageVariant> $variants
     *
     * @return list<PageVariant>
     */
    private static function unambiguous(array $variants): array
    {
        $byKey = [];

        foreach ($variants as $variant) {
            $key = $variant->gtin ?? mb_strtolower(($variant->size ?? '').'|'.($variant->colour ?? ''));
            $byKey[$key][] = $variant;
        }

        $kept = [];

        foreach ($byKey as $same) {
            $prices = array_unique(array_map(static fn (PageVariant $variant): string => $variant->price, $same));

            if (\count($prices) === 1) {
                $kept[] = $same[0];
            }
        }

        return $kept;
    }
}
