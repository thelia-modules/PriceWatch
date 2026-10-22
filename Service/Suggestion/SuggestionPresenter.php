<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use PriceWatch\Service\Comparator\CatalogThumbnails;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Settings\PriceWatchSettings;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The comparator's suggestions tab: products worth adding to the watch now,
 * never one already watched, each with the reasons why, the highest score
 * first (SuggestionScore), family by family (SuggestionQuota): ranked across
 * the shop, helmets took 47 lines out of 50 (measured 2026-10-12).
 */
final readonly class SuggestionPresenter
{
    /** The tab's value in the comparator's view parameter. */
    public const VIEW = 'suggestions';

    public const SALES_DAYS = 90;
    /** The best sellers of the month and of the year, rolling: asked 2026-10-20. */
    public const MONTH_DAYS = 30;
    public const YEAR_DAYS = 365;
    /** One rule for "old reading" across the back office. */
    public const STALE_DAYS = \PriceWatch\Service\Watched\LastWatch::STALE_DAYS;
    public const SHOWN = 50;

    /** A click starts one pass: this many products at most. */
    public const MAX_SELECTED = 20;

    /** Lines a family gets at least, however little it sells. */
    public const MIN_PER_FAMILY = 3;

    public function __construct(
        private SuggestionRepository $repository,
        private ComparatorRepository $comparator,
        private CatalogThumbnails $thumbnails,
        private UrlGeneratorInterface $urlGenerator,
        private GtinNormalizer $gtin,
        private PriceWatchSettings $settings,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(string $locale, ?\DateTimeImmutable $now = null, ?int $family = null): array
    {
        return $this->screen($locale, $now, $family)['rows'];
    }

    /**
     * The rows, and the families the filter offers: those with a candidate,
     * the best selling first, each with the lines it gets.
     *
     * @return array{rows: list<array<string, mixed>>, families: list<array{id: int, title: string, lines: int}>, family: ?int}
     */
    public function screen(string $locale, ?\DateTimeImmutable $now = null, ?int $family = null): array
    {
        $now ??= new \DateTimeImmutable();
        ['sales' => $sales, 'month' => $month, 'year' => $year, 'signals' => $signals, 'families' => $familyOf] = $this->candidates($now);

        $scores = self::score($sales, $signals, $month, $year);
        $products = $this->repository->products(array_keys($scores), $locale);
        $ranked = array_values(array_filter(self::rank($scores, $sales), static fn (int $productId): bool => isset($products[$productId])));
        $quota = self::quota($ranked, $familyOf, $this->repository->familyRevenue($now->modify('-'.self::SALES_DAYS.' days'), $this->settings->excludedFamilies()));
        $titles = $this->repository->familyTitles($locale);
        $family = $family !== null && isset($quota[$family]) ? $family : null;

        $ranked = self::lines($ranked, $familyOf, $family === null ? $quota : [$family => self::SHOWN]);
        $eanCodes = $this->repository->eanCodes($ranked);
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
            // Added to the watch when collected: only its variants with a
            // valid EAN of their own will be searched.
            $variants = self::searchableVariants($this->gtin, $eanCodes[$productId] ?? []);
            $reasons = [];

            if (isset($sales[$productId])) {
                $reasons[] = ['key' => 'sales', 'quantity' => $sales[$productId]['quantity'], 'revenue' => $money->formatCurrency($sales[$productId]['revenue'], 'EUR')];
            }

            foreach (['month' => $month, 'year' => $year] as $key => $sellers) {
                if (isset($sellers[$productId])) {
                    $reasons[] = ['key' => $key, 'rank' => $sellers[$productId]['rank'], 'quantity' => $sellers[$productId]['quantity'], 'revenue' => $money->formatCurrency($sellers[$productId]['revenue'], 'EUR')];
                }
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
                'familyId' => $familyOf[$productId],
                'family' => $titles[$familyOf[$productId]] ?? '',
                'title' => $product['title'],
                'ref' => $product['ref'],
                'image' => $pictures[$productId] ?? null,
                'editUrl' => $this->urlGenerator->generate('admin.products.update', ['product_id' => $productId]),
                'variants' => $variants,
                'cost' => $usd->formatCurrency(ManualRun::worstCaseUsd($variants), 'USD'),
                'reasons' => $reasons,
                'score' => (int) round($scores[$productId]->total()),
                'scoreParts' => array_map(static fn (float $points): int => (int) round($points), $scores[$productId]->parts()),
            ];
        }

        // The highest score first, whatever the family: the quota chose which
        // products are listed, the filter narrows to one family. What can be
        // collected first: a product without a searchable variant stays
        // listed, for its EAN codes to be fixed, at the end.
        usort($rows, static fn (array $a, array $b): int => (int) ($a['variants'] === 0) <=> (int) ($b['variants'] === 0));

        return [
            'rows' => $rows,
            'families' => array_map(static fn (int $id, int $lines): array => ['id' => $id, 'title' => $titles[$id] ?? '#'.$id, 'lines' => $lines], array_keys($quota), $quota),
            'family' => $family,
        ];
    }

    /**
     * @param list<int>        $ranked   product ids, the highest score first
     * @param array<int, int>  $familyOf product id => family id
     * @param array<int, float> $revenue family id => revenue
     *
     * @return array<int, int> family id => lines, the best selling family first
     */
    private static function quota(array $ranked, array $familyOf, array $revenue): array
    {
        $candidates = [];

        foreach ($ranked as $productId) {
            $candidates[$familyOf[$productId]] = ($candidates[$familyOf[$productId]] ?? 0) + 1;
        }

        return SuggestionQuota::allocate($revenue, $candidates, self::SHOWN, self::MIN_PER_FAMILY);
    }

    /**
     * The best of each family, as many as its quota.
     *
     * @param list<int>       $ranked
     * @param array<int, int> $familyOf
     * @param array<int, int> $quota
     *
     * @return list<int>
     */
    public static function lines(array $ranked, array $familyOf, array $quota): array
    {
        $taken = [];
        $lines = [];

        foreach ($ranked as $productId) {
            $family = $familyOf[$productId];

            if (($taken[$family] ?? 0) < ($quota[$family] ?? 0)) {
                $taken[$family] = ($taken[$family] ?? 0) + 1;
                $lines[] = $productId;
            }
        }

        return $lines;
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
        return min(self::SHOWN, \count($this->candidates($now ?? new \DateTimeImmutable())['families']));
    }

    /**
     * Every product not watched yet one reason puts forward, kept only if we
     * can sell it (collecting the price of a hidden or out of stock product
     * is money lost) and if its family is one to suggest from. Best sellers
     * (over 90 days, the month and the year) and promotions are read family
     * by family, so every family has candidates.
     *
     * @return array{
     *     sales: array<int, array{quantity: int, revenue: float, rank: int}>,
     *     month: array<int, array{quantity: int, revenue: float, rank: int}>,
     *     year: array<int, array{quantity: int, revenue: float, rank: int}>,
     *     signals: array<int, array{discountPct: float|null, isNew: bool, stockValue: float}>,
     *     families: array<int, int>,
     * }
     */
    private function candidates(\DateTimeImmutable $now): array
    {
        $excluded = $this->settings->excludedFamilies();
        $sales = $this->repository->bestSellersByFamily($now->modify('-'.self::SALES_DAYS.' days'), self::SHOWN, $excluded);
        $month = $this->repository->bestSellersByFamily($now->modify('-'.self::MONTH_DAYS.' days'), self::SHOWN, $excluded);
        $year = $this->repository->bestSellersByFamily($now->modify('-'.self::YEAR_DAYS.' days'), self::SHOWN, $excluded);
        $promoted = $this->repository->onPromotionByFamily(SuggestionScore::PROMO_FROM_PCT, self::SHOWN, $excluded);
        $signals = $this->repository->catalogSignals(array_values(array_unique([...array_keys($sales), ...array_keys($month), ...array_keys($year), ...$promoted])));
        $families = $this->repository->families(array_keys($signals), $excluded);

        return [
            'sales' => $sales,
            'month' => $month,
            'year' => $year,
            'signals' => array_intersect_key($signals, $families),
            'families' => $families,
        ];
    }

    /**
     * Revenue and stock are measured against the largest among the products
     * we can sell, so the score tells apart what the list shows. Revenue is
     * measured period by period, the best share counts: the best seller of
     * the year that did not sell this season still takes the full weight.
     *
     * @param array<int, array{quantity: int, revenue: float}>                              $sales over 90 days
     * @param array<int, array{discountPct: float|null, isNew: bool, stockValue: float}> $signals
     * @param array<int, array{quantity: int, revenue: float}>                              $month
     * @param array<int, array{quantity: int, revenue: float}>                              $year
     *
     * @return array<int, SuggestionScore> product id => score, for the products in $signals only
     */
    public static function score(array $sales, array $signals, array $month = [], array $year = []): array
    {
        $periods = [$sales, $month, $year];
        $bestRevenues = array_map(static fn (array $sellers): float => max([0.0, ...array_map(static fn (int $productId): float => $sellers[$productId]['revenue'] ?? 0.0, array_keys($signals))]), $periods);
        $largestStock = max([0.0, ...array_column($signals, 'stockValue')]);
        $scores = [];

        foreach ($signals as $productId => $signal) {
            $revenueShare = 0.0;

            foreach ($periods as $index => $sellers) {
                if ($bestRevenues[$index] > 0) {
                    $revenueShare = max($revenueShare, ($sellers[$productId]['revenue'] ?? 0.0) / $bestRevenues[$index]);
                }
            }

            $scores[$productId] = SuggestionScore::of(
                $revenueShare,
                $signal['discountPct'],
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
