<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Service\Schedule\SchedulePresenter;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Usage\UsageRepository;

/**
 * Where the price watch stands: is a pass running, and how did the last
 * collect and judge passes end. Shown next to the buttons that start one.
 */
final readonly class RunStatus
{
    public function __construct(
        private RunProgress $progress,
        private UsageRepository $ledger,
        private ?ManualRun $manualRun = null,
        private ?PassRequests $requests = null,
        private ?ScheduleSettings $schedule = null,
    ) {
    }

    /**
     * @return array{running: bool, progress: array<string, mixed>, collect: ?array<string, mixed>, judge: ?array<string, mixed>, schedule: array{inline: bool, request: ?array{state: string, at: string, variants: int}, taskMissing: bool, cronLine: string}}
     */
    public function present(): array
    {
        $progress = $this->progress->present();

        return [
            'running' => $progress['running'],
            'progress' => $progress,
            'collect' => $this->row($this->ledger->latestRun('collect')),
            'judge' => $this->row($this->ledger->latestRun('judge')),
            'schedule' => $this->waitingForTheSchedule(),
        ];
    }

    /**
     * Without a worker, a pass asked for here waits for the scheduled task:
     * what the screen says about that, and how to get the task if it does not run.
     *
     * @return array{inline: bool, request: ?array{state: string, at: string, variants: int}, taskMissing: bool, cronLine: string}
     */
    private function waitingForTheSchedule(): array
    {
        $request = $this->requests?->current();

        return [
            'inline' => $this->manualRun?->runsInline() ?? false,
            'request' => $request === null ? null : ['state' => $request['state'], 'at' => (new \DateTimeImmutable('@'.$request['at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i'), 'variants' => $request['variants']],
            'taskMissing' => $this->schedule?->taskIsMissing(new \DateTimeImmutable()) ?? false,
            'cronLine' => SchedulePresenter::CRON_LINE,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(?PwCollectRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'at' => $run->getStartedAt()?->format('d/m/Y H:i'),
            'status' => (string) $run->getStatus(),
            'targets' => (int) $run->getTargetsPlanned(),
            'recorded' => (int) $run->getQuotesWritten(),
            'seen' => (int) $run->getOffersSeen(),
            'matched' => (int) $run->getOffersMatched(),
            'review' => (int) $run->getOffersReview(),
            'rejected' => (int) $run->getOffersRejected(),
            // Shown when a pass did not end well, with where to read the rest.
            'errors' => \array_slice(array_values(array_filter((array) json_decode((string) $run->getErrorsJson(), true), 'is_string')), 0, 3),
        ];
    }
}
