<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * A collect pass waits on DataForSEO for a minute or more: far too long for a
 * web request. The commands are handed to a detached shell (nohup, &), so the
 * request returns at once and the pass carries on after it. Every argument is
 * escaped: nothing typed in the back office reaches the shell as code.
 */
final readonly class ShellBackgroundRunner implements BackgroundRunner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function start(array $commands, string $logFile): void
    {
        $chain = implode('; ', array_map(
            static fn (array $arguments): string => implode(' ', array_map('escapeshellarg', $arguments)),
            $commands,
        ));

        $process = Process::fromShellCommandline(
            'nohup sh -c '.escapeshellarg($chain).' >> '.escapeshellarg($logFile).' 2>&1 &',
            $this->projectDir,
        );
        $process->run();
    }
}
