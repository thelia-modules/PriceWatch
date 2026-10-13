<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCollectRunQuery;
use PriceWatch\Service\Direct\DirectCollector;
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

    /** Beyond this, the list says how many more. */
    public const ITEMS_SHOWN = 12;

    /**
     * @return array{running: bool, step: ?string, position: int, steps: int, done: int, total: int, percent: int, startedAt: ?int, items: array{items: list<array<string, mixed>>, more: int, counts: array<string, int>}}
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
            ->filterByPassName([ShoppingCollector::PASS, DirectCollector::PASS, OfferJudge::PASS], Criteria::IN)
            ->filterByStatus('started')
            ->filterByDryRun(0)
            ->orderById(Criteria::DESC)
            ->findOne();

        // The direct and judge passes follow a collect pass: its products stay listed. No
        // row yet: nothing to list, never the products of an older pass.
        $collect = $run === null || $run->getPassName() === ShoppingCollector::PASS ? $run : PwCollectRunQuery::create()
            ->filterByPassName(ShoppingCollector::PASS)
            ->filterByDryRun(0)
            ->orderById(Criteria::DESC)
            ->findOne();

        return ['running' => true, 'items' => PassItems::forDisplay($collect?->getProgressItems(), self::ITEMS_SHOWN)] + self::of($run);
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
            // A pass that has not counted yet is already at its own step.
            ?? match ($run?->getPassName()) {
                OfferJudge::PASS => PassStep::Judging,
                DirectCollector::PASS => PassStep::DirectPages,
                default => null,
            };
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
            'items' => PassItems::forDisplay(null, 0),
        ];
    }
}
