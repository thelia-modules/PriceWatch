<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Controller\Admin\RunController;
use PriceWatch\Service\Run\ManualRun;

/**
 * The scheduled passes card of the settings page.
 */
final readonly class SchedulePresenter
{
    public const MONTH_DAYS = 30;

    /** What the cron line calls, every hour; the hours are checked by the command. */
    public const CRON_LINE = '0 * * * * cd <root> && php bin/console pricewatch:scheduled --no-interaction --quiet';

    public function __construct(
        private ScheduleSettings $settings,
        private ScheduleRepository $repository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(string $locale, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $products = $this->repository->watched();
        $due = new DueProducts($this->settings->allDays());
        $lastPass = $this->settings->lastPass();
        $byTier = [];

        foreach (WatchTier::cases() as $tier) {
            $byTier[$tier->value] = ['days' => $this->settings->days($tier), 'products' => 0];
        }

        $monthlyUsd = 0.0;

        foreach ($products as $product) {
            ++$byTier[$product->tier->value]['products'];
            // Worst case: every variant searched, none riding on another size.
            $monthlyUsd += ManualRun::worstCaseUsd($product->collectable, false) * self::MONTH_DAYS / $this->settings->days($product->tier);
        }

        $dueNow = $due->due($products, $now);

        return [
            'enabled' => $this->settings->enabled(),
            'tiers' => $byTier,
            'startHour' => $this->settings->startHour(),
            'endHour' => $this->settings->endHour(),
            'maxVariants' => $this->settings->maxVariants(),
            'maxVariantsLimit' => ScheduleSettings::MAX_VARIANTS_LIMIT,
            'maxDays' => ScheduleSettings::MAX_DAYS,
            'dueNow' => \count($dueNow),
            'nextBatch' => \count($due->batch($products, $now, $this->settings->maxVariants())),
            'monthlyCost' => RunController::usd($monthlyUsd, $locale),
            'lastPass' => $lastPass === null ? null : [
                'at' => (new \DateTimeImmutable($lastPass['at']))->format('d/m/Y H:i'),
                'products' => $lastPass['products'],
                'variants' => $lastPass['variants'],
                'cost' => RunController::usd($lastPass['costUsd'], $locale),
            ],
            'cronLine' => self::CRON_LINE,
        ];
    }
}
