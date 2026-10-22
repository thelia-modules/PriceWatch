<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

use PriceWatch\Controller\Admin\RunController;
use PriceWatch\Service\Comparator\PricePosition;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Schedule\DueProducts;
use PriceWatch\Service\Schedule\ScheduledProduct;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Schedule\TierAdvisor;
use PriceWatch\Service\Schedule\WatchTier;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes the watched products list for the template, so it only prints.
 */
final readonly class WatchedPresenter
{
    /** One pass per click, as for the suggestions. */
    public const BATCH = SuggestionPresenter::MAX_SELECTED;

    public function __construct(
        private WatchedRepository $repository,
        private RunStatus $runStatus,
        private UrlGeneratorInterface $urlGenerator,
        private ScheduleSettings $schedule,
        private TierAdvisor $tierAdvisor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(WatchedFilters $filters, string $locale, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $counts = $this->repository->counts($filters, $locale, $now);
        $total = $counts[$filters->view->value];
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);
        $proposals = $this->tierAdvisor->proposals($now);
        $due = new DueProducts($this->schedule->allDays());

        return [
            'filters' => $filters,
            'view' => $filters->view->value,
            'rows' => array_map(fn (array $row): array => $this->row($row, $locale, $percent, $now) + $this->tier($row, $proposals, $due, $now), $this->repository->page($filters, $locale, $now)),
            'tiers' => array_map(static fn (WatchTier $tier): string => $tier->value, WatchTier::cases()),
            'scheduleEnabled' => $this->schedule->enabled(),
            'tierChanges' => \count($this->tierAdvisor->changes($now)),
            'staleDays' => LastWatch::STALE_DAYS,
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
            'batches' => $this->batches($locale, $now),
        ];
    }

    /**
     * What the action buttons above the list would do: collect the products
     * never read, or read too long ago, at most a pass's worth at a time;
     * pause the ones the shop no longer sells (asked 2026-10-13).
     *
     * @return array<string, array<string, mixed>>
     */
    private function batches(string $locale, \DateTimeImmutable $now): array
    {
        $batches = [];

        foreach ([WatchedView::Never, WatchedView::Stale] as $view) {
            $batch = $this->repository->toCollect($view, self::BATCH, $now);
            $batches[$view->value] = [
                'total' => $batch['total'],
                'products' => \count($batch['productIds']),
                // Without a variant with a valid EAN, a product cannot be searched: not counted on the button.
                'searchable' => $batch['searchable'],
                'variants' => $batch['variants'],
                'cost' => RunController::usd(ManualRun::worstCaseUsd($batch['variants']), $locale),
            ];
        }

        $batches['offline'] = ['total' => \count($this->repository->offline())];
        $batches['out_of_stock'] = ['total' => \count($this->repository->outOfStock())];

        return $batches;
    }

    /**
     * The tier of a row, the one its sales propose when different, and when
     * the scheduled passes read it next.
     *
     * @param array<string, mixed>  $row
     * @param array<int, WatchTier> $proposals
     *
     * @return array{tier: string, proposedTier: ?string, nextDue: ?string, dueNow: bool}
     */
    private function tier(array $row, array $proposals, DueProducts $due, \DateTimeImmutable $now): array
    {
        $productId = (int) $row['product_id'];
        $tier = WatchTier::fromStored($row['tier'] === null ? null : (string) $row['tier']);
        $proposed = $proposals[$productId] ?? null;
        $lastRead = max(
            $row['last_watched_at'] === null ? null : new \DateTimeImmutable((string) $row['last_watched_at']),
            $row['scheduled_at'] === null ? null : new \DateTimeImmutable((string) $row['scheduled_at']),
        );
        $scheduled = new ScheduledProduct($productId, (string) $row['ref'], $tier, (int) $row['collectable'], $lastRead);
        // Paused or offline products are never taken by a scheduled pass.
        $taken = (int) $row['monitored'] > 0 && $proposed !== null;

        return [
            'tier' => $tier->value,
            'proposedTier' => $proposed !== null && $proposed !== $tier ? $proposed->value : null,
            'nextDue' => $taken ? $due->nextDue($scheduled, $now)?->format('d/m') : null,
            'dueNow' => $taken && $due->isDue($scheduled, $now),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function row(array $row, string $locale, \NumberFormatter $percent, \DateTimeImmutable $now): array
    {
        $productId = (int) $row['product_id'];
        $monitored = (int) $row['monitored'];
        $collectable = (int) $row['collectable'];
        $bestGap = $row['best_gap'] === null ? null : (float) $row['best_gap'];
        $lastWatched = $row['last_watched_at'] === null ? null : new \DateTimeImmutable((string) $row['last_watched_at']);

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
                $lastWatched === null => 'never',
                LastWatch::isStale($lastWatched, $now) => 'stale',
                default => 'collected',
            },
            'lastCollected' => $lastWatched?->format('d/m/Y H:i'),
            'daysAgo' => $lastWatched === null ? null : LastWatch::daysAgo($lastWatched, $now),
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
