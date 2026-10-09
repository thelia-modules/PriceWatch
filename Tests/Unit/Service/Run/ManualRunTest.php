<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\BackgroundRunner;
use PriceWatch\Service\Run\ManualRun;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ManualRunTest extends TestCase
{
    /** @var list<array{commands: list<list<string>>, log: string}> */
    private array $started = [];
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testAProductPassCollectsItsVariantsThenJudges(): void
    {
        $this->manualRun()->start(4, '1520190076NN');

        self::assertCount(1, $this->started);
        [$collect, $judge] = $this->started[0]['commands'];
        self::assertSame('/var/www/html/Thelia', $collect[1]);
        self::assertSame('pricewatch:collect', $collect[2]);
        self::assertContains('--limit=4', $collect);
        self::assertContains('--priority', $collect, 'whoever clicked waits for an answer in minutes');
        self::assertContains('--product=1520190076NN', $collect);
        self::assertContains('--budget-usd=0.024', $collect, 'the cap is the worst case announced on the button');
        self::assertSame('pricewatch:judge', $judge[2], 'judged right after, so the comparator fills up');
        self::assertSame('/var/www/html/var/log/pricewatch-manual.log', $this->started[0]['log']);
    }

    public function testAPassWithoutProductTakesTheVariantsCollectedLongestAgo(): void
    {
        $this->manualRun()->start(50);

        $collect = $this->started[0]['commands'][0];
        self::assertContains('--limit=50', $collect);
        self::assertEmpty(array_filter($collect, static fn (string $argument): bool => str_starts_with($argument, '--product')));
    }

    public function testTheWorstCaseIsThreePriorityTasksPerVariant(): void
    {
        self::assertEqualsWithDelta(0.06, ManualRun::worstCaseUsd(10), 1e-9);
        self::assertEqualsWithDelta(3.0, ManualRun::worstCaseUsd(500), 1e-9);
    }

    public function testNoSecondPassWhileOneIsRunning(): void
    {
        $collectLock = $this->locks->createLock('pricewatch.collect');
        $collectLock->acquire();

        self::assertTrue($this->manualRun()->isRunning());

        try {
            $this->manualRun()->start(10);
            self::fail('expected a refusal');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->started);

        $collectLock->release();
        $judgeLock = $this->locks->createLock('pricewatch.judge');
        $judgeLock->acquire();
        self::assertTrue($this->manualRun()->isRunning(), 'a judge pass counts too');
        $judgeLock->release();

        self::assertFalse($this->manualRun()->isRunning());
    }

    private function manualRun(): ManualRun
    {
        $runner = new class($this->started) implements BackgroundRunner {
            /** @param list<array{commands: list<list<string>>, log: string}> $started */
            public function __construct(private array &$started)
            {
            }

            public function start(array $commands, string $logFile): void
            {
                $this->started[] = ['commands' => $commands, 'log' => $logFile];
            }
        };

        return new ManualRun($runner, $this->locks, '/var/www/html');
    }
}
