<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * A direct pass that first searches the sitemaps, a collect pass for what is
 * left, a direct pass for the pages Google led to, then a judge pass, started
 * from the back office. The passes go to a worker through the queue of the
 * heavy jobs ({@see MessengerBackgroundRunner}).
 *
 * Manual passes use DataForSEO's priority queue: whoever clicked is waiting
 * for an answer within minutes, not within the 45 minutes of the standard
 * queue. The worst case announced on the button is what the command's own
 * budget guard enforces, so the click can never cost more than it said.
 */
final readonly class ManualRun
{
    /** DataForSEO priority queue, per task (list price read 2026-10-08). */
    public const PRIORITY_COST_PER_TASK = 0.002;

    /** DataForSEO standard queue, per task: the scheduled passes, nobody waiting. */
    public const STANDARD_COST_PER_TASK = 0.001;

    /** A title search, a sellers lookup: Google Shopping is never searched by GTIN. */
    public const TASKS_PER_VARIANT = 2;

    public const LOG_FILE = 'var/log/pricewatch-manual.log';
    public const SCHEDULED_LOG_FILE = 'var/log/pricewatch-scheduled.log';

    /** Same lock names as the commands: a click cannot start a second pass. */
    private const LOCKS = ['pricewatch.collect', 'pricewatch.direct', 'pricewatch.judge'];

    /**
     * The page shown after the click is rendered before the background
     * process takes its lock (observed on 2026-10-09): for this long after a
     * start, the pass counts as starting.
     */
    private const STARTING_SECONDS = 30;

    private string $startMarker;

    public function __construct(
        private BackgroundRunner $runner,
        private LockFactory $lockFactory,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        string $startMarker = '',
    ) {
        $this->startMarker = $startMarker !== '' ? $startMarker : $projectDir.'/var/pricewatch-manual.started';
    }

    public static function worstCaseUsd(int $variants, bool $priority = true): float
    {
        return $variants * self::TASKS_PER_VARIANT * ($priority ? self::PRIORITY_COST_PER_TASK : self::STANDARD_COST_PER_TASK);
    }

    /**
     * @see BackgroundRunner::runsInline()
     */
    public function runsInline(): bool
    {
        return $this->runner->runsInline();
    }

    public function isRunning(): bool
    {
        if ($this->runner->hasPending()) {
            return true;
        }

        foreach (self::LOCKS as $name) {
            $lock = $this->lockFactory->createLock($name);

            if (!$lock->acquire(false)) {
                return true;
            }

            $lock->release();
        }

        return false;
    }

    /**
     * When a pass was started from the back office, if that was a moment ago.
     */
    public function startedAt(): ?int
    {
        $at = is_file($this->startMarker) ? filemtime($this->startMarker) : false;

        return $at !== false && time() - $at < self::STARTING_SECONDS ? $at : null;
    }

    /**
     * @param list<string> $productRefs only these products' variants;
     *                                  otherwise the ones collected longest ago
     * @param bool         $priority    false for the scheduled passes: the
     *                                  standard queue, half the price, nobody waiting
     *
     * @throws PassAlreadyRunning
     */
    public function start(int $variants, array $productRefs = [], bool $priority = true): void
    {
        if ($this->isRunning()) {
            throw new PassAlreadyRunning();
        }

        $this->runner->start(new RunPass(
            bin2hex(random_bytes(8)),
            $variants,
            $productRefs,
            $priority,
            $priority ? self::LOG_FILE : self::SCHEDULED_LOG_FILE,
        ));
        touch($this->startMarker);
    }
}
