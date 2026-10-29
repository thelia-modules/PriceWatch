<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the steps of a pass one after the other, in the worker, as the
 * commands do from the console. A step that stops on an error does not stop
 * the next one (the sitemaps failing must not keep the judge from running);
 * the pass is then reported failed at the end, so it lands in the failed jobs
 * and can be replayed from there.
 */
#[AsMessageHandler]
final readonly class RunPassHandler
{
    public function __construct(
        private PassClaims $claims,
        private StepRunner $steps,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(RunPass $pass): void
    {
        if (!$this->claims->claim($pass->passId)) {
            return;
        }

        $output = new BufferedOutput();
        $failure = null;

        foreach ($pass->steps() as $step) {
            $output->writeln('$ '.$step['command']);

            try {
                $this->steps->run($step['command'], $step['input'], $output);
            } catch (\Throwable $error) {
                $failure ??= $error;
                $output->writeln(\sprintf('Stopped by %s: %s', $error::class, $error->getMessage()));
            }
        }

        $this->log($pass, $output->fetch());

        if ($failure !== null) {
            $this->claims->release($pass->passId);

            throw new \RuntimeException('A step of the price watch pass failed: '.$failure->getMessage(), 0, $failure);
        }

        $this->claims->finish($pass->passId);
    }

    private function log(RunPass $pass, string $text): void
    {
        $file = $this->projectDir.'/'.$pass->logFile;

        if (!is_dir(\dirname($file))) {
            return;
        }

        file_put_contents($file, $text, \FILE_APPEND | \LOCK_EX);
    }
}
