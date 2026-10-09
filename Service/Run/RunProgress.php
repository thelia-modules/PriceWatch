<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCollectRunQuery;
use PriceWatch\Service\Matching\OfferJudge;
use PriceWatch\Service\Source\ShoppingCollector;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Where the running pass stands, read from its own row: the passes report
 * their step and their done/total there as they go.
 *
 * Running means a pass lock is held, as for the start buttons: a row left
 * "started" by a dead process never shows as running.
 */
final readonly class RunProgress
{
    public function __construct(
        private ManualRun $manualRun,
    ) {
    }

    /**
     * @return array{running: bool, step: ?string, position: int, steps: int, done: int, total: int, percent: int, startedAt: ?int}
     */
    public function present(): array
    {
        if (!$this->manualRun->isRunning()) {
            $startedAt = $this->manualRun->startedAt();

            // Just clicked: the background process has not taken its lock
            // yet. Running, unless a pass has already started and ended since.
            if ($startedAt === null || $this->collectStartedSince($startedAt)) {
                return self::of(null) + ['running' => false];
            }

            return ['running' => true, 'startedAt' => $startedAt] + self::of(null);
        }

        $run = PwCollectRunQuery::create()
            ->filterByPassName([ShoppingCollector::PASS, OfferJudge::PASS], Criteria::IN)
            ->filterByStatus('started')
            ->filterByDryRun(0)
            ->orderById(Criteria::DESC)
            ->findOne();

        return ['running' => true] + self::of($run);
    }

    private function collectStartedSince(int $timestamp): bool
    {
        return PwCollectRunQuery::create()
            ->filterByPassName(ShoppingCollector::PASS)
            ->filterByStartedAt((new \DateTime())->setTimestamp($timestamp), Criteria::GREATER_EQUAL)
            ->count() > 0;
    }

    /**
     * @return array{step: ?string, position: int, steps: int, done: int, total: int, percent: int, startedAt: ?int}
     */
    public static function of(?PwCollectRun $run): array
    {
        $step = PassStep::tryFrom((string) $run?->getProgressStep())
            // A judge pass that has not counted its offers yet is still judging.
            ?? ($run?->getPassName() === OfferJudge::PASS ? PassStep::Judging : null);
        $done = (int) $run?->getProgressDone();
        $total = (int) $run?->getProgressTotal();

        return [
            // No step yet: the collect pass is choosing its variants.
            'step' => $step?->value,
            'position' => $step?->position() ?? 1,
            'steps' => \count(PassStep::cases()),
            'done' => $done,
            'total' => $total,
            'percent' => $step?->percent($done, $total) ?? 0,
            'startedAt' => $run?->getStartedAt()?->getTimestamp(),
        ];
    }
}
