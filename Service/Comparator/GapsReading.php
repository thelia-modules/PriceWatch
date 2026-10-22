<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Suggestion\SuggestionPresenter;

/**
 * A new reading of the gaps list, as filtered on the screen (asked
 * 2026-10-16): the products read longest ago first, a pass's worth at a
 * time, as the other batch buttons. Unlike a suggestion, nothing is added
 * to the watch or resumed: a paused product stays paused.
 */
final readonly class GapsReading
{
    public const MAX_PRODUCTS = SuggestionPresenter::MAX_SELECTED;

    public function __construct(
        private ComparatorRepository $comparator,
        private CatalogVariantRepository $catalog,
        private ManualRun $manualRun,
    ) {
    }

    /**
     * What a click would read, and how many products the list holds.
     *
     * @return array{products: list<int>, variants: int, total: int}
     */
    public function batch(ComparatorFilters $filters): array
    {
        $filters = $filters->withView(ComparatorView::Gaps);
        $productIds = $this->comparator->gapProductsReadLongestAgo($filters, self::MAX_PRODUCTS);
        $counts = $this->catalog->collectableVariantCounts($productIds);

        return [
            // In the order read, oldest first: the counts come back by id.
            'products' => array_values(array_filter($productIds, static fn (int $productId): bool => isset($counts[$productId]))),
            'variants' => array_sum($counts),
            'total' => $this->comparator->productCount($filters),
        ];
    }

    /**
     * @return array{products: int, variants: int} what the pass covers; nothing started when products is 0
     *
     * @throws \RuntimeException when a pass is already running
     */
    public function start(ComparatorFilters $filters): array
    {
        $batch = $this->batch($filters);
        $refs = array_values(array_filter(array_map($this->catalog->productRef(...), $batch['products'])));

        if ($refs !== []) {
            $this->manualRun->start($batch['variants'], $refs);
        }

        return ['products' => \count($refs), 'variants' => $batch['variants']];
    }
}
