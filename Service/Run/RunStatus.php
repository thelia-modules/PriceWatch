<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Service\Usage\UsageRepository;

/**
 * Where the price watch stands: is a pass running, and how did the last
 * collect and judge passes end. Shown next to the buttons that start one.
 */
final readonly class RunStatus
{
    public function __construct(
        private ManualRun $manualRun,
        private UsageRepository $ledger,
    ) {
    }

    /**
     * @return array{running: bool, collect: ?array<string, mixed>, judge: ?array<string, mixed>}
     */
    public function present(): array
    {
        return [
            'running' => $this->manualRun->isRunning(),
            'collect' => $this->row($this->ledger->latestRun('collect')),
            'judge' => $this->row($this->ledger->latestRun('judge')),
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
        ];
    }
}
