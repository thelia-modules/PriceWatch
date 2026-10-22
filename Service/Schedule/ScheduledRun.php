<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Run\ManualRun;

/**
 * What one call of the cron line does (pricewatch:scheduled): nothing when
 * scheduling is off, outside its hours, while a pass runs or when nothing is
 * due; otherwise the same chain as the back-office buttons on the due
 * products, in DataForSEO's standard queue.
 */
final readonly class ScheduledRun
{
    /** Beyond this, a pass row still open is a pass that died. */
    private const OPEN_RUN_HOURS = 3;

    public function __construct(
        private ScheduleSettings $settings,
        private ScheduleRepository $repository,
        private ManualRun $manualRun,
        private ComparatorRepository $comparator,
    ) {
    }

    public function dueProducts(): DueProducts
    {
        return new DueProducts($this->settings->allDays());
    }

    /**
     * @return array{status: string, products: list<ScheduledProduct>, variants: int, costUsd: float}
     */
    public function tick(\DateTimeImmutable $now, bool $ignoreHours = false, bool $dryRun = false): array
    {
        $nothing = static fn (string $status): array => ['status' => $status, 'products' => [], 'variants' => 0, 'costUsd' => 0.0];

        if (!$this->settings->enabled()) {
            return $nothing('disabled');
        }

        if (!$ignoreHours && !$this->settings->isWithinHours($now)) {
            return $nothing('outside_hours');
        }

        if ($this->comparator->competitors() === []) {
            return $nothing('no_competitor');
        }

        if ($this->isRunning($now)) {
            return $nothing('running');
        }

        $batch = $this->dueProducts()->batch($this->repository->watched(), $now, $this->settings->maxVariants());

        if ($batch === []) {
            return $nothing('nothing_due');
        }

        $variants = array_sum(array_map(static fn (ScheduledProduct $product): int => $product->collectable, $batch));
        $costUsd = ManualRun::worstCaseUsd($variants, false);
        $planned = ['status' => $dryRun ? 'dry_run' : 'started', 'products' => $batch, 'variants' => $variants, 'costUsd' => $costUsd];

        if ($dryRun) {
            return $planned;
        }

        try {
            $this->manualRun->start($variants, array_map(static fn (ScheduledProduct $product): string => $product->ref, $batch), false);
        } catch (\RuntimeException) {
            return $nothing('running');
        }

        // Marked once started: a product Google finds nothing for waits for
        // its next period instead of being paid for again every hour.
        $this->repository->markScheduled(array_map(static fn (ScheduledProduct $product): int => $product->productId, $batch), $now);
        $this->settings->recordPass($now, \count($batch), $variants, $costUsd);

        return $planned;
    }

    private function isRunning(\DateTimeImmutable $now): bool
    {
        return $this->manualRun->isRunning()
            || $this->manualRun->startedAt() !== null
            || $this->repository->hasOpenRun($now->modify('-'.self::OPEN_RUN_HOURS.' hours'));
    }
}
