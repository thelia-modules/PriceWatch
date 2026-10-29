<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\PassAlreadyRequested;
use PriceWatch\Service\Run\PassAlreadyRunning;
use PriceWatch\Service\Run\PassRequests;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use PriceWatch\Tests\Doubles\RecordingBackgroundRunner;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ManualRunTest extends TestCase
{
    private RecordingBackgroundRunner $runner;
    private LockFactory $locks;
    private PassRequests $requests;
    private string $marker;

    protected function setUp(): void
    {
        $this->runner = new RecordingBackgroundRunner();
        $this->requests = new PassRequests(new InMemoryConfigStore(), new LockFactory(new InMemoryStore()));
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

        self::assertEqualsWithDelta(time(), $this->manualRun()->startedAt(), 2, 'the page after the click is drawn before the worker takes its lock');

        touch($this->marker, time() - 31);
        self::assertNull($this->manualRun()->startedAt(), 'long enough ago, only the lock tells');
    }

    public function testAProductPassIsAskedForWithItsProductsAndItsPriority(): void
    {
        $this->manualRun()->start(4, ['1520190076NN']);

        self::assertCount(1, $this->runner->passes);
        $pass = $this->runner->passes[0];
        self::assertSame(4, $pass->variants);
        self::assertSame(['1520190076NN'], $pass->productRefs);
        self::assertTrue($pass->priority, 'whoever clicked waits for an answer in minutes');
        self::assertSame('var/log/pricewatch-manual.log', $pass->logFile);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $pass->passId);
    }

    public function testTwoRequestsAreTwoDistinctPasses(): void
    {
        $this->manualRun()->start(4);
        touch($this->marker, time() - 31);
        $this->manualRun()->start(4);

        self::assertNotSame($this->runner->passes[0]->passId, $this->runner->passes[1]->passId);
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

        self::assertFalse($this->runner->passes[0]->priority, 'nobody waits for a scheduled pass');
        self::assertSame('var/log/pricewatch-scheduled.log', $this->runner->passes[0]->logFile);
    }

    public function testNoSecondPassWhileOneIsRunning(): void
    {
        $collectLock = $this->locks->createLock('pricewatch.collect');
        $collectLock->acquire();

        self::assertTrue($this->manualRun()->isRunning());

        try {
            $this->manualRun()->start(10);
            self::fail('expected a refusal');
        } catch (PassAlreadyRunning) {
        }

        self::assertSame([], $this->runner->passes);

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

    public function testAPassWaitingForAWorkerCountsAsRunning(): void
    {
        $this->runner->pending = true;

        self::assertTrue($this->manualRun()->isRunning(), 'a second click while the first still waits would pay twice');

        $this->expectException(PassAlreadyRunning::class);
        $this->manualRun()->start(10);
    }

    public function testWithoutAWorkerTheClickIsWrittenDownAndNotRun(): void
    {
        $this->runner->inline = true;

        $this->manualRun()->start(10, ['1SAA340']);

        self::assertSame([], $this->runner->passes, 'a pass run here would hold the web request for minutes');
        $request = $this->requests->current();
        self::assertSame([PassRequests::PENDING, 10, ['1SAA340'], true], [$request['state'] ?? null, $request['variants'] ?? null, $request['productRefs'] ?? null, $request['priority'] ?? null]);
    }

    public function testWithAWorkerTheClickGoesToTheQueueAtOnce(): void
    {
        $this->manualRun()->start(10);

        self::assertCount(1, $this->runner->passes);
        self::assertNull($this->requests->current());
    }

    public function testAnotherClickWhileARequestWaitsIsRefused(): void
    {
        $this->runner->inline = true;
        $this->manualRun()->start(10);

        $this->expectException(PassAlreadyRequested::class);
        $this->manualRun()->start(20);
    }

    public function testTheScheduledTaskRunsAtOnceWhateverTheQueue(): void
    {
        $this->runner->inline = true;

        $this->manualRun()->startNow(10, [], false, 'the-request');

        self::assertSame(['the-request'], array_map(static fn ($pass): string => $pass->passId, $this->runner->passes));
        self::assertNull($this->requests->current());
    }

    private function manualRun(): ManualRun
    {
        return new ManualRun($this->runner, $this->locks, '/var/www/html', $this->marker, $this->requests);
    }
}
