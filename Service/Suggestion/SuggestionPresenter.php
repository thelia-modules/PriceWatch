<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use PriceWatch\Service\Comparator\CatalogThumbnails;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Run\ManualRun;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The comparator's suggestions tab: products worth collecting now, each with
 * the reasons why, the highest score first (SuggestionScore).
 */
final readonly class SuggestionPresenter
{
    /** The tab's value in the comparator's view parameter. */
    public const VIEW = 'suggestions';

    public const SALES_DAYS = 90;
    public const STALE_DAYS = 14;
    public const SHOWN = 50;

    /** A click starts one pass: this many products at most. */
    public const MAX_SELECTED = 20;

    public function __construct(
        private SuggestionRepository $repository,
        private ComparatorRepository $comparator,
        private CatalogThumbnails $thumbnails,
        private UrlGeneratorInterface $urlGenerator,
        private GtinNormalizer $gtin,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(string $locale, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        ['sales' => $sales, 'dearer' => $dearer, 'stale' => $stale, 'signals' => $signals] = $this->candidates($now);

        $scores = self::score($sales, $dearer, $stale, $signals, $now);
        $products = $this->repository->products(array_keys($scores), $locale);
        $ranked = self::rank($scores, $sales);

        $ranked = \array_slice(array_values(array_filter($ranked, static fn (int $productId): bool => isset($products[$productId]))), 0, self::SHOWN);
        $eanCodes = $this->repository->eanCodes(array_values(array_filter($ranked, static fn (int $productId): bool => $products[$productId]['watched'] === 0)));
        $pictures = $this->comparator->cardImages($ranked);
        $pictures += $this->thumbnails->urls(array_values(array_diff($ranked, array_keys($pictures))));

        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $money->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);
        $usd = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $usd->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);

        $rows = [];

        foreach ($ranked as $productId) {
            $product = $products[$productId];
            // Not watched yet: added when collected, and only its variants
            // with a valid EAN of their own will be searched.
            $variants = $product['watched'] > 0 ? $product['collectable'] : self::searchableVariants($this->gtin, $eanCodes[$productId] ?? []);
            $reasons = [];

            if (isset($sales[$productId])) {
                $reasons[] = ['key' => 'sales', 'quantity' => $sales[$productId]['quantity'], 'revenue' => $money->formatCurrency($sales[$productId]['revenue'], 'EUR')];
            }

            if (isset($dearer[$productId])) {
                $reasons[] = ['key' => 'dearer', 'gap' => $percent->format(abs($dearer[$productId]['gapPct']) / 100), 'competitors' => $dearer[$productId]['competitors']];
            }

            if (isset($stale[$productId])) {
                $reasons[] = ['key' => 'stale', 'days' => (int) $stale[$productId]->diff($now)->days];
            }

            $discount = $signals[$productId]['discountPct'];

            if ($discount !== null && $discount >= SuggestionScore::PROMO_FROM_PCT) {
                $reasons[] = ['key' => 'promo', 'discount' => $percent->format($discount / 100)];
            }

            if ($signals[$productId]['isNew']) {
                $reasons[] = ['key' => 'new'];
            }

            $rows[] = [
                'id' => $productId,
                'title' => $product['title'],
                'ref' => $product['ref'],
                'image' => $pictures[$productId] ?? null,
                'editUrl' => $this->urlGenerator->generate('admin.products.update', ['product_id' => $productId]),
                'watched' => $product['watched'] > 0,
                'variants' => $variants,
                'cost' => $usd->formatCurrency(ManualRun::worstCaseUsd($variants), 'USD'),
                'reasons' => $reasons,
                'score' => (int) round($scores[$productId]->total()),
                'scoreParts' => array_map(static fn (float $points): int => (int) round($points), $scores[$productId]->parts()),
            ];
        }

        // What can be collected first: a product without a searchable
        // variant stays listed, for its EAN codes to be fixed, at the end.
        usort($rows, static fn (array $a, array $b): int => (int) ($a['variants'] === 0) <=> (int) ($b['variants'] === 0));

        return $rows;
    }

    /**
     * What the collect pass will search once the product is watched: the
     * variants whose EAN is a valid GTIN no other variant of it carries.
     *
     * @param list<string> $eanCodes
     */
    public static function searchableVariants(GtinNormalizer $gtin, array $eanCodes): int
    {
        $valid = [];

        foreach ($eanCodes as $code) {
            $normalized = $gtin->normalize($code);

            if ($normalized->value !== null && $normalized->checkDigitValid) {
                $valid[] = $normalized->value;
            }
        }

        return \count(array_filter(array_count_values($valid), static fn (int $count): bool => $count === 1));
    }

    /**
     * For the tab's badge, without building the rows.
     */
    public function count(?\DateTimeImmutable $now = null): int
    {
        return min(self::SHOWN, \count($this->candidates($now ?? new \DateTimeImmutable())['signals']));
    }

    /**
     * Every product one reason puts forward, kept only if we can sell it:
     * collecting the price of a hidden or out of stock product is money lost.
     *
     * @return array{
     *     sales: array<int, array{quantity: int, revenue: float}>,
     *     dearer: array<int, array{gapPct: float, competitors: int}>,
     *     stale: array<int, \DateTimeImmutable>,
     *     signals: array<int, array{discountPct: float|null, isNew: bool, stockValue: float}>,
     * }
     */
    private function candidates(\DateTimeImmutable $now): array
    {
        $sales = $this->repository->bestSellers($now->modify('-'.self::SALES_DAYS.' days'), self::SHOWN);
        $dearer = $this->repository->weWereDearer(self::SHOWN);
        $stale = $this->repository->stale($now->modify('-'.self::STALE_DAYS.' days'), self::SHOWN);
        $promoted = $this->repository->onPromotion(SuggestionScore::PROMO_FROM_PCT, self::SHOWN);

        return [
            'sales' => $sales,
            'dearer' => $dearer,
            'stale' => $stale,
            'signals' => $this->repository->catalogSignals(array_values(array_unique([...array_keys($sales), ...array_keys($dearer), ...array_keys($stale), ...$promoted]))),
        ];
    }

    /**
     * Revenue and stock are measured against the largest among the products
     * we can sell, so the score tells apart what the list shows.
     *
     * @param array<int, array{quantity: int, revenue: float}>                              $sales
     * @param array<int, array{gapPct: float, competitors: int}>                            $dearer
     * @param array<int, \DateTimeImmutable>                                                $stale
     * @param array<int, array{discountPct: float|null, isNew: bool, stockValue: float}> $signals
     *
     * @return array<int, SuggestionScore> product id => score, for the products in $signals only
     */
    public static function score(array $sales, array $dearer, array $stale, array $signals, \DateTimeImmutable $now): array
    {
        $bestRevenue = max([0.0, ...array_map(static fn (int $productId): float => $sales[$productId]['revenue'] ?? 0.0, array_keys($signals))]);
        $largestStock = max([0.0, ...array_column($signals, 'stockValue')]);
        $scores = [];

        foreach ($signals as $productId => $signal) {
            $scores[$productId] = SuggestionScore::of(
                $bestRevenue > 0 ? ($sales[$productId]['revenue'] ?? 0.0) / $bestRevenue : 0.0,
                $dearer[$productId]['gapPct'] ?? null,
                $signal['discountPct'],
                isset($stale[$productId]) ? (int) $stale[$productId]->diff($now)->days : null,
                $signal['isNew'],
                $largestStock > 0 ? $signal['stockValue'] / $largestStock : 0.0,
            );
        }

        return $scores;
    }

    /**
     * The highest score first; on a tie, what sells most.
     *
     * @param array<int, SuggestionScore>                        $scores
     * @param array<int, array{quantity: int, revenue: float}> $sales
     *
     * @return list<int>
     */
    public static function rank(array $scores, array $sales): array
    {
        $productIds = array_keys($scores);

        usort($productIds, static fn (int $a, int $b): int => [$scores[$b]->total(), $sales[$b]['revenue'] ?? 0.0, $a]
            <=> [$scores[$a]->total(), $sales[$a]['revenue'] ?? 0.0, $b]);

        return $productIds;
    }
}
