<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use PriceWatch\Service\Run\StepRunner;
use Symfony\Component\Console\Output\OutputInterface;

final class RecordingStepRunner implements StepRunner
{
    /** @var list<string> */
    public array $ran = [];

    public function __construct(public ?string $failOn = null)
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
}
