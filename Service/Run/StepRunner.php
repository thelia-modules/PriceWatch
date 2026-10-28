<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs one step of a pass: a console command of the module, in this process.
 */
interface StepRunner
{
    /**
     * @param array<string, mixed> $input options of the command, as ArrayInput takes them
     *
     * @return int the exit code of the command
     */
    public function run(string $command, array $input, OutputInterface $output): int;
}
