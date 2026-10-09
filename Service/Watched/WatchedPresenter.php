<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

use PriceWatch\Controller\Admin\RunController;
use PriceWatch\Service\Comparator\PricePosition;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\RunStatus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes the watched products list for the template, so it only prints.
 */
final readonly class WatchedPresenter
{
    public function __construct(
        private WatchedRepository $repository,
        private RunStatus $runStatus,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(WatchedFilters $filters, string $locale): array
    {
        $counts = $this->repository->counts($filters, $locale);
        $total = $counts[$filters->view->value];
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);

        return [
            'filters' => $filters,
            'view' => $filters->view->value,
            'rows' => array_map(fn (array $row): array => $this->row($row, $locale, $percent), $this->repository->page($filters, $locale)),
            'counts' => $counts,
            'run' => $this->runStatus->present(),
            'tabs' => array_map(fn (WatchedView $view): array => [
                'view' => $view->value,
                'count' => $counts[$view->value],
                'url' => $this->urlGenerator->generate('pricewatch_watched', $filters->withView($view)->toQueryParams()),
                'active' => $view === $filters->view,
            ], WatchedView::cases()),
            'sorts' => array_map(fn (string $sort): array => [
                'sort' => $sort,
                'url' => $this->urlGenerator->generate('pricewatch_watched', $filters->withSort($sort)->toQueryParams()),
                'active' => $sort === $filters->sort,
            ], WatchedFilters::SORTS),
            'pagination' => [
                'page' => $filters->page,
                'pages' => max(1, (int) ceil($total / WatchedFilters::PAGE_SIZE)),
                'previous' => $filters->page > 1 ? $this->urlGenerator->generate('pricewatch_watched', $filters->withPage($filters->page - 1)->toQueryParams()) : null,
                'next' => $filters->offset() + WatchedFilters::PAGE_SIZE < $total ? $this->urlGenerator->generate('pricewatch_watched', $filters->withPage($filters->page + 1)->toQueryParams()) : null,
            ],
            'returnQuery' => http_build_query($filters->toQueryParams()),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function row(array $row, string $locale, \NumberFormatter $percent): array
    {
        $productId = (int) $row['product_id'];
        $monitored = (int) $row['monitored'];
        $collectable = (int) $row['collectable'];
        $bestGap = $row['best_gap'] === null ? null : (float) $row['best_gap'];
        $lastCollected = $row['last_collected_at'] === null ? null : new \DateTimeImmutable((string) $row['last_collected_at']);

        return [
            'productId' => $productId,
            'title' => (string) $row['title'],
            'ref' => (string) $row['ref'],
            'variants' => (int) $row['variants'],
            'monitored' => $monitored,
            'collectable' => $collectable,
            'status' => match (true) {
                $monitored === 0 => 'paused',
                $collectable === 0 => 'not_collectable',
                $lastCollected === null => 'never',
                default => 'collected',
            },
            'lastCollected' => $lastCollected?->format('d/m/Y H:i'),
            'compared' => (int) $row['compared'],
            'review' => (int) $row['review'],
            'gap' => $bestGap === null ? null : ($bestGap > 0 ? '+' : '').$percent->format($bestGap / 100),
            'position' => PricePosition::fromGapPct($bestGap)->value,
            'collectCost' => $collectable > 0 ? RunController::usd(ManualRun::worstCaseUsd($collectable), $locale) : null,
            'productUrl' => $this->urlGenerator->generate('admin.products.update', ['product_id' => $productId, 'current_tab' => 'modules']),
            'comparatorUrl' => $this->urlGenerator->generate('pricewatch_comparator', ['q' => (string) $row['ref']]),
        ];
    }
}
