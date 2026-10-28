<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\PassClaims;
use PriceWatch\Service\Run\RunPass;
use PriceWatch\Service\Run\RunPassHandler;
use PriceWatch\Service\Run\StepRunner;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RunPassHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];
    private ?string $failOn = null;
    private PassClaims $claims;
    private string $directory;

    protected function setUp(): void
    {
        $this->claims = new PassClaims(new InMemoryConfigStore(), new LockFactory(new InMemoryStore()));
        $this->directory = sys_get_temp_dir().'/pw-handler-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/var/log', 0o777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/var/log/*') ?: []);
        rmdir($this->directory.'/var/log');
        rmdir($this->directory.'/var');
        rmdir($this->directory);
    }

    public function testTheStepsRunInOrderAndTheirOutputGoesToTheLog(): void
    {
        $this->handler()(new RunPass('a', 4, ['X']));

        self::assertSame(['pricewatch:direct', 'pricewatch:collect', 'pricewatch:direct', 'pricewatch:judge'], $this->ran);
        $log = (string) file_get_contents($this->directory.'/var/log/pricewatch-manual.log');
        self::assertStringContainsString('$ pricewatch:collect', $log);
        self::assertStringContainsString('ran pricewatch:judge', $log);
    }

    public function testAMessageDeliveredTwiceRunsOnce(): void
    {
        $this->handler()(new RunPass('a', 4));
        $this->handler()(new RunPass('a', 4));

        self::assertCount(4, $this->ran, 'the second delivery paid for nothing');
    }

    public function testAMessageDeliveredWhileItsWorkerStillRunsIsLeftAlone(): void
    {
        $this->claims->claim('a');

        $this->handler()(new RunPass('a', 4));

        self::assertSame([], $this->ran);
    }

    public function testAFailedStepDoesNotStopTheNextOnesAndThePassCanBeReplayed(): void
    {
        $this->failOn = 'pricewatch:collect';

        try {
            $this->handler()(new RunPass('a', 4));
            self::fail('the pass must be reported failed');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('boom', $failure->getMessage());
        }

        self::assertSame(['pricewatch:direct', 'pricewatch:collect', 'pricewatch:direct', 'pricewatch:judge'], $this->ran, 'the judge still ran');

        $this->failOn = null;
        $this->ran = [];
        $this->handler()(new RunPass('a', 4));

        self::assertCount(4, $this->ran, 'replayed from the failed jobs: it runs again');
    }

    private function handler(): RunPassHandler
    {
        $steps = new class($this->ran, $this->failOn) implements StepRunner {
            /** @param list<string> $ran */
            public function __construct(private array &$ran, private ?string $failOn)
            {
            }

            public function run(string $command, array $input, OutputInterface $output): int
            {
                $this->ran[] = $command;
                $output->writeln('ran '.$command);

                if ($command === $this->failOn) {
                    throw new \RuntimeException('boom');
                }

                return 0;
            }
        };

        return new RunPassHandler($this->claims, $steps, $this->directory);
    }
}
