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
 * the reasons why, the ones with the most reasons first.
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
        $sales = $this->repository->bestSellers($now->modify('-'.self::SALES_DAYS.' days'), self::SHOWN);
        $dearer = $this->repository->weWereDearer(self::SHOWN);
        $stale = $this->repository->stale($now->modify('-'.self::STALE_DAYS.' days'), self::SHOWN);

        $productIds = array_values(array_unique([...array_keys($sales), ...array_keys($dearer), ...array_keys($stale)]));
        $products = $this->repository->products($productIds, $locale);
        $ranked = self::rank($productIds, $sales, $dearer, $stale);

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
        $now ??= new \DateTimeImmutable();

        return min(self::SHOWN, \count(array_unique([
            ...array_keys($this->repository->bestSellers($now->modify('-'.self::SALES_DAYS.' days'), self::SHOWN)),
            ...array_keys($this->repository->weWereDearer(self::SHOWN)),
            ...array_keys($this->repository->stale($now->modify('-'.self::STALE_DAYS.' days'), self::SHOWN)),
        ])));
    }

    /**
     * The most reasons first; then what sells most, then where we were
     * dearest, then what was collected longest ago.
     *
     * @param list<int>                                     $productIds
     * @param array<int, array{quantity: int, revenue: float}>   $sales
     * @param array<int, array{gapPct: float, competitors: int}> $dearer
     * @param array<int, \DateTimeImmutable>                $stale
     *
     * @return list<int>
     */
    public static function rank(array $productIds, array $sales, array $dearer, array $stale): array
    {
        usort($productIds, static fn (int $a, int $b): int => [
            (int) isset($sales[$b]) + (int) isset($dearer[$b]) + (int) isset($stale[$b]),
            $sales[$b]['revenue'] ?? 0.0,
            $dearer[$a]['gapPct'] ?? 0.0,
            isset($stale[$a]) ? $stale[$a]->getTimestamp() : \PHP_INT_MAX,
            $a,
        ] <=> [
            (int) isset($sales[$a]) + (int) isset($dearer[$a]) + (int) isset($stale[$a]),
            $sales[$a]['revenue'] ?? 0.0,
            $dearer[$b]['gapPct'] ?? 0.0,
            isset($stale[$b]) ? $stale[$b]->getTimestamp() : \PHP_INT_MAX,
            $b,
        ]);

        return $productIds;
    }
}
