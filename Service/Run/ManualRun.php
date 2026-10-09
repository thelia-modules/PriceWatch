<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * A collect pass followed by a judge pass, started from the back office.
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

    /** A GTIN search, a title search when the GTIN leads nowhere, a sellers lookup. */
    public const TASKS_PER_VARIANT = 3;

    public const LOG_FILE = 'var/log/pricewatch-manual.log';

    /** Same lock names as the commands: a click cannot start a second pass. */
    private const LOCKS = ['pricewatch.collect', 'pricewatch.judge'];

    public function __construct(
        private BackgroundRunner $runner,
        private LockFactory $lockFactory,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
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
     * @param ?string $productRef only this product's variants; otherwise the
     *                            ones collected longest ago
     *
     * @throws \RuntimeException when a pass is already running
     */
    public function start(int $variants, ?string $productRef = null): void
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

        if ($productRef !== null) {
            $collect[] = '--product='.$productRef;
        }

        $this->runner->start(
            [$collect, [$php, $console, 'pricewatch:judge', '--no-ansi']],
            $this->projectDir.'/'.self::LOG_FILE,
        );
    }
}
