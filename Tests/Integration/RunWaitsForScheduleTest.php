<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\PassRequests;
use PriceWatch\Service\Run\RunProgress;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Schedule\SchedulePresenter;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Usage\UsageRepository;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use PriceWatch\Tests\Doubles\RecordingBackgroundRunner;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Without a worker, the screen says that a pass waits for the scheduled task,
 * and when that task does not run, how to get it.
 */
final class RunWaitsForScheduleTest extends PriceWatchIntegrationTestCase
{
    private RecordingBackgroundRunner $runner;
    private PassRequests $requests;
    private ScheduleSettings $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new RecordingBackgroundRunner(inline: true);
        $this->requests = new PassRequests(new InMemoryConfigStore(), new LockFactory(new InMemoryStore()));
        $this->schedule = new ScheduleSettings(new InMemoryConfigStore());
    }

    public function testWithoutAWorkerAndWithoutTheTaskTheScreenGivesTheCronLine(): void
    {
        $status = $this->runStatus()->present()['schedule'];

        self::assertTrue($status['inline']);
        self::assertTrue($status['taskMissing']);
        self::assertSame(SchedulePresenter::CRON_LINE, $status['cronLine']);

        $html = $this->render();
        self::assertStringContainsString('data-testid="pw-run-cron-missing"', $html);
        self::assertStringContainsString(htmlspecialchars(SchedulePresenter::CRON_LINE), $html, 'the exact line to copy');
    }

    public function testOnceTheTaskRunsTheCronWarningGoesAndTheRequestShows(): void
    {
        $this->schedule->recordTick(new \DateTimeImmutable('-10 minutes'));
        $this->manualRun()->start(10);

        $status = $this->runStatus()->present()['schedule'];

        self::assertFalse($status['taskMissing']);
        self::assertSame([PassRequests::PENDING, 10], [$status['request']['state'] ?? null, $status['request']['variants'] ?? null]);

        $html = $this->render();
        self::assertStringNotContainsString('pw-run-cron-missing', $html);
        self::assertStringContainsString('data-testid="pw-run-waits"', $html);
        self::assertStringContainsString('data-state="pending"', $html);
    }

    public function testWithAWorkerThereIsNothingToWaitFor(): void
    {
        $this->runner->inline = false;

        $html = $this->render();

        self::assertFalse($this->runStatus()->present()['schedule']['inline']);
        self::assertStringNotContainsString('pw-run-waits', $html);
        self::assertStringNotContainsString('pw-run-cron-missing', $html);
    }

    private function manualRun(): ManualRun
    {
        return new ManualRun($this->runner, new LockFactory(new InMemoryStore()), '/tmp', sys_get_temp_dir().'/pw-waits-'.bin2hex(random_bytes(4)), $this->requests);
    }

    private function runStatus(): RunStatus
    {
        return new RunStatus(new RunProgress($this->manualRun()), new UsageRepository(), $this->manualRun(), $this->requests, $this->schedule);
    }

    private function render(): string
    {
        // The page's own template, with the two things it asks of the back
        // office reduced to their text: the translator and the router.
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'PriceWatchModule');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $id): string => $id));
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => '/'.$name));

        return $twig->render('@PriceWatchModule/backOffice/default-twig/price-watch/_run.html.twig', ['run' => $this->runStatus()->present(), 'batches' => [], 'csrfToken' => 'x']);
    }
}
