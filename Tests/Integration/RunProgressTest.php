<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Service\Matching\OfferJudge;
use PriceWatch\Service\Run\AbandonedRuns;
use PriceWatch\Service\Run\BackgroundRunner;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\PassStep;
use PriceWatch\Service\Run\RunProgress;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Source\ShoppingCollector;
use PriceWatch\Service\Usage\UsageRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The progress bar reads what the passes write on their own row, and only
 * while a pass lock is held.
 */
final class RunProgressTest extends PriceWatchIntegrationTestCase
{
    private LockFactory $locks;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->locks = new LockFactory(new InMemoryStore());
        $this->marker = sys_get_temp_dir().'/pricewatch-test-'.bin2hex(random_bytes(4)).'.started';
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
        }

        parent::tearDown();
    }

    public function testAPassJustClickedShowsBeforeItTakesItsLock(): void
    {
        touch($this->marker, time() - 2);

        $progress = $this->progress()->present();

        self::assertTrue($progress['running'], 'the page after the click is drawn before the process takes its lock');
        self::assertSame([null, 1, 0], [$progress['step'], $progress['position'], $progress['percent']]);

        $ended = $this->passRow(ShoppingCollector::PASS, PassStep::Recording, 1, 1);
        $ended->setStatus('success')->save();

        self::assertFalse($this->progress()->present()['running'], 'already started and ended since the click');
    }

    public function testThePercentCountsTheStepsAlreadyDone(): void
    {
        self::assertSame(0, PassStep::TitleSearch->percent(0, 4));
        self::assertSame(12, PassStep::TitleSearch->percent(2, 4), 'half of the first of four steps');
        self::assertSame(31, PassStep::Sellers->percent(1, 4));
        self::assertSame(50, PassStep::Recording->percent(0, 0), 'nothing to count yet: the step has just begun');
        self::assertSame(100, PassStep::Judging->percent(3, 3));
        self::assertSame(100, PassStep::Judging->percent(9, 3), 'never past the end');
    }

    public function testARunningPassShowsItsStepAndCounts(): void
    {
        $run = $this->passRow(ShoppingCollector::PASS, PassStep::Sellers, 1, 3);
        $held = $this->locks->createLock('pricewatch.collect');
        $held->acquire();

        $progress = $this->progress()->present();

        self::assertTrue($progress['running']);
        self::assertSame('sellers', $progress['step']);
        self::assertSame([2, 4], [$progress['position'], $progress['steps']]);
        self::assertSame([1, 3], [$progress['done'], $progress['total']]);
        self::assertSame(33, $progress['percent']);
        self::assertSame($run->getStartedAt()?->getTimestamp(), $progress['startedAt']);
    }

    public function testTheProductsOfTheCollectStayListedWhileJevChecks(): void
    {
        $collect = $this->passRow(ShoppingCollector::PASS, PassStep::Recording, 2, 2);
        $collect->setStatus('success')->setProgressItems((string) json_encode([['id' => 42, 'title' => 'Casque Shark', 'sizes' => ['M'], 'status' => 'done', 'offers' => 3]]))->save();
        $this->passRow(OfferJudge::PASS, PassStep::Judging, 0, 3);
        $held = $this->locks->createLock('pricewatch.judge');
        $held->acquire();

        $items = $this->progress()->present()['items'];

        self::assertSame([42], array_column($items['items'], 'id'));
        self::assertSame(3, $items['items'][0]['offers']);
    }

    public function testNoOlderListWhileThePassHasNoRowYet(): void
    {
        $older = $this->passRow(ShoppingCollector::PASS, PassStep::Recording, 1, 1);
        $older->setStatus('success')->setProgressItems((string) json_encode([['id' => 7, 'title' => 'Old', 'sizes' => [], 'status' => 'done', 'offers' => 1]]))->save();
        // Lock held, but every row of this test's passes closed: no current row.
        \PriceWatch\Model\PwCollectRunQuery::create()->filterByStatus('started')->update(['Status' => 'failed']);
        $held = $this->locks->createLock('pricewatch.collect');
        $held->acquire();

        self::assertSame([], $this->progress()->present()['items']['items'], 'never the products of an older pass');
    }

    public function testNothingRunsWithoutALockEvenIfARowSaysStarted(): void
    {
        $this->passRow(ShoppingCollector::PASS, PassStep::TitleSearch, 0, 2);

        self::assertFalse($this->progress()->present()['running'], 'a row left "started" by a dead process is not a running pass');
    }

    public function testAJudgePassThatHasNotCountedYetIsAlreadyJudging(): void
    {
        $run = $this->passRow(OfferJudge::PASS, null, 0, 0);

        $progress = RunProgress::of($run);

        self::assertSame(['judging', 4, 75], [$progress['step'], $progress['position'], $progress['percent']]);
    }

    public function testACollectPassStillChoosingItsVariantsStartsAtZero(): void
    {
        $progress = RunProgress::of($this->passRow(ShoppingCollector::PASS, null, 0, 0));

        self::assertSame([null, 1, 0], [$progress['step'], $progress['position'], $progress['percent']]);
    }

    public function testRecordingSavesOnlyWhatChanged(): void
    {
        $run = $this->passRow(ShoppingCollector::PASS, PassStep::TitleSearch, 0, 2);

        PassStep::TitleSearch->record($run, 1, 2);
        $run->reload();

        self::assertSame([1, 2], [$run->getProgressDone(), $run->getProgressTotal()], 'written to the row the back office reads');
        PassStep::TitleSearch->record($run, 1, 2);
        self::assertFalse($run->isModified());
    }

    public function testAbandonedPassesOfThisPassAreClosedAsFailed(): void
    {
        $collect = $this->passRow(ShoppingCollector::PASS, PassStep::Sellers, 0, 1);
        $judge = $this->passRow(OfferJudge::PASS, PassStep::Judging, 0, 1);

        self::assertGreaterThanOrEqual(1, (new AbandonedRuns())->close(ShoppingCollector::PASS));

        $collect->reload();
        $judge->reload();
        self::assertSame('failed', $collect->getStatus());
        self::assertNotNull($collect->getFinishedAt());
        self::assertStringContainsString('Interrupted', (string) $collect->getErrorsJson());
        self::assertSame('started', $judge->getStatus(), 'another pass is none of its business');
    }

    public function testTheLastPassErrorsAreShown(): void
    {
        $run = $this->passRow(ShoppingCollector::PASS, null, 0, 0);
        $run->setStatus('partial')
            ->setStartedAt(new \DateTime('2037-01-01 00:00:00'))
            ->setErrorsJson((string) json_encode(['search for identity 12: Internal SE Server Error.', 'a', 'b', 'c']))
            ->save();

        $status = (new RunStatus($this->progress(), new UsageRepository()))->present();

        self::assertSame('partial', $status['collect']['status'] ?? null);
        self::assertSame(['search for identity 12: Internal SE Server Error.', 'a', 'b'], $status['collect']['errors'], 'the first three');
    }

    private function passRow(string $pass, ?PassStep $step, int $done, int $total): PwCollectRun
    {
        $run = (new PwCollectRun())
            ->setPassName($pass)
            ->setStatus('started')
            ->setStartedAt(new \DateTime())
            ->setProgressStep($step?->value)
            ->setProgressDone($done)
            ->setProgressTotal($total);
        $run->save();

        return $run;
    }

    private function progress(): RunProgress
    {
        $runner = new class implements BackgroundRunner {
            public function start(array $commands, string $logFile): void
            {
            }
        };

        return new RunProgress(new ManualRun($runner, $this->locks, '/tmp', $this->marker));
    }
}
