<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A direct pass that first searches the sitemaps, a collect pass for what is
 * left, a direct pass for the pages Google led to, then a judge pass, started
 * from the back office.
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

    /** A title search, a sellers lookup: Google Shopping is never searched by GTIN. */
    public const TASKS_PER_VARIANT = 2;

    public const LOG_FILE = 'var/log/pricewatch-manual.log';

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

    public static function worstCaseUsd(int $variants): float
    {
        return $variants * self::TASKS_PER_VARIANT * self::PRIORITY_COST_PER_TASK;
    }

    public function isRunning(): bool
    {
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
     *
     * @throws \RuntimeException when a pass is already running
     */
    public function start(int $variants, array $productRefs = []): void
    {
        if ($this->isRunning()) {
            throw new \RuntimeException('A price watch pass is already running.');
        }

        $php = (new PhpExecutableFinder())->find(false) ?: 'php';

        // Asked from PHP-FPM, the finder can return the FPM binary itself.
        if (str_contains(basename($php), 'fpm')) {
            $php = 'php';
        }
        $console = $this->projectDir.'/Thelia';
        $collect = [
            $php, $console, 'pricewatch:collect',
            '--limit='.max(1, $variants),
            '--priority',
            '--max-wait=600',
            '--budget-usd='.number_format(self::worstCaseUsd(max(1, $variants)), 3, '.', ''),
            '--no-ansi',
        ];

        // Free first: the sitemaps and the known pages, so Google is only
        // asked for the products no page covers; then the pages Google led
        // to, before the judge pass.
        $sitemaps = [$php, $console, 'pricewatch:direct', '--discover', '--no-ansi'];
        $direct = [$php, $console, 'pricewatch:direct', '--candidates', '--no-ansi'];

        foreach ($productRefs as $productRef) {
            $sitemaps[] = '--product='.$productRef;
            $collect[] = '--product='.$productRef;
            $direct[] = '--product='.$productRef;
        }

        $this->runner->start(
            [$sitemaps, $collect, $direct, [$php, $console, 'pricewatch:judge', '--no-ansi']],
            $this->projectDir.'/'.self::LOG_FILE,
        );
        touch($this->startMarker);
    }
}
