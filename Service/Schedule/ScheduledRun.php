<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\PassAlreadyRunning;
use PriceWatch\Service\Run\PassRequests;

/**
 * What one call of the cron line does (pricewatch:scheduled): first the pass
 * asked for in the back office when no worker takes the queue; then nothing when
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
        private ?PassRequests $requests = null,
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

        $this->settings->recordTick($now);

        // A pass asked for in the back office comes first, scheduling on or
        // off: someone is waiting for it.
        if (!$dryRun && ($requested = $this->runRequested($now)) !== null) {
            return $requested;
        }

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
        } catch (PassAlreadyRunning) {
            return $nothing('running');
        }

        // Marked once started: whatever comes of it, the product is not taken
        // again before tomorrow. Its period runs from a real reading: a
        // Google search made (found or not) or a page read with a price.
        $this->repository->markScheduled(array_map(static fn (ScheduledProduct $product): int => $product->productId, $batch), $now);
        $this->settings->recordPass($now, \count($batch), $variants, $costUsd);

        return $planned;
    }

    /**
     * @return array{status: string, products: list<ScheduledProduct>, variants: int, costUsd: float}|null
     */
    private function runRequested(\DateTimeImmutable $now): ?array
    {
        if ($this->requests === null || $this->isRunning($now)) {
            return null;
        }

        $request = $this->requests->take();

        if ($request === null) {
            return null;
        }

        try {
            $this->manualRun->startNow($request['variants'], $request['productRefs'], $request['priority'], $request['id']);
        } catch (PassAlreadyRunning) {
            $this->requests->release($request['id']);

            return null;
        } catch (\Throwable $error) {
            $this->requests->fail($request['id']);

            throw $error;
        }

        $this->requests->finish($request['id']);

        return ['status' => 'requested', 'products' => [], 'variants' => $request['variants'], 'costUsd' => ManualRun::worstCaseUsd($request['variants'], $request['priority'])];
    }

    private function isRunning(\DateTimeImmutable $now): bool
    {
        return $this->manualRun->isRunning()
            || $this->manualRun->startedAt() !== null
            || $this->repository->hasOpenRun($now->modify('-'.self::OPEN_RUN_HOURS.' hours'));
    }
}
