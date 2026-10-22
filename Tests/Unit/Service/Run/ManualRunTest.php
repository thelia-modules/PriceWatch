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
    private string $marker;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->marker = sys_get_temp_dir().'/pricewatch-test-'.bin2hex(random_bytes(4)).'.started';
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
        }
    }

    public function testAPassJustStartedCountsAsStartingForAMoment(): void
    {
        self::assertNull($this->manualRun()->startedAt());

        $this->manualRun()->start(10);

        self::assertEqualsWithDelta(time(), $this->manualRun()->startedAt(), 2, 'the page after the click is drawn before the process takes its lock');

        touch($this->marker, time() - 31);
        self::assertNull($this->manualRun()->startedAt(), 'long enough ago, only the lock tells');
    }

    public function testAProductPassSearchesTheSitemapsThenGoogleThenReadsAndJudges(): void
    {
        $this->manualRun()->start(4, ['1520190076NN']);

        self::assertCount(1, $this->started);
        [$sitemaps, $collect, $direct, $judge] = $this->started[0]['commands'];
        self::assertSame(['pricewatch:direct', '--discover'], [$sitemaps[2], $sitemaps[3]], 'free first: the sitemaps and the known pages');
        self::assertContains('--product=1520190076NN', $sitemaps);
        self::assertSame('/var/www/html/Thelia', $collect[1]);
        self::assertSame('pricewatch:collect', $collect[2]);
        self::assertContains('--limit=4', $collect);
        self::assertContains('--priority', $collect, 'whoever clicked waits for an answer in minutes');
        self::assertContains('--product=1520190076NN', $collect);
        self::assertContains('--budget-usd=0.016', $collect, 'the cap is the worst case announced on the button');
        self::assertSame(['pricewatch:direct', '--candidates'], [$direct[2], $direct[3]], 'the pages Google led to, read straight before the checks');
        self::assertContains('--product=1520190076NN', $direct);
        self::assertSame('pricewatch:judge', $judge[2], 'judged right after, so the comparator fills up');
        self::assertSame('/var/www/html/var/log/pricewatch-manual.log', $this->started[0]['log']);
    }

    public function testAPassCanTargetSeveralProducts(): void
    {
        $this->manualRun()->start(6, ['1520190076NN', '1SAA340']);

        $collect = $this->started[0]['commands'][1];
        self::assertContains('--product=1520190076NN', $collect);
        self::assertContains('--product=1SAA340', $collect, 'one option per product, each passed as its own argument');
        self::assertContains('--limit=6', $collect);
    }

    public function testAPassWithoutProductTakesTheVariantsCollectedLongestAgo(): void
    {
        $this->manualRun()->start(50);

        $collect = $this->started[0]['commands'][1];
        self::assertContains('--limit=50', $collect);
        self::assertEmpty(array_filter($collect, static fn (string $argument): bool => str_starts_with($argument, '--product')));
    }

    public function testTheWorstCaseIsTwoPriorityTasksPerVariant(): void
    {
        self::assertEqualsWithDelta(0.04, ManualRun::worstCaseUsd(10), 1e-9);
        self::assertEqualsWithDelta(2.0, ManualRun::worstCaseUsd(500), 1e-9);
        self::assertEqualsWithDelta(0.02, ManualRun::worstCaseUsd(10, false), 1e-9, 'the standard queue costs half');
    }

    public function testAScheduledPassUsesTheStandardQueueAndItsOwnLog(): void
    {
        $this->manualRun()->start(10, ['1SAA340'], false);

        $collect = $this->started[0]['commands'][1];
        self::assertNotContains('--priority', $collect, 'nobody waits for a scheduled pass');
        self::assertContains('--max-wait=3000', $collect, 'the standard queue takes up to 45 minutes');
        self::assertContains('--budget-usd=0.020', $collect, 'the cap follows the queue price');
        self::assertSame('/var/www/html/var/log/pricewatch-scheduled.log', $this->started[0]['log']);
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
        $directLock = $this->locks->createLock('pricewatch.direct');
        $directLock->acquire();
        self::assertTrue($this->manualRun()->isRunning(), 'between the two, the direct pass counts too');
        $directLock->release();

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

        return new ManualRun($runner, $this->locks, '/var/www/html', $this->marker);
    }
}
