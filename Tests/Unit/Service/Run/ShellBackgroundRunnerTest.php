<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\ShellBackgroundRunner;

/**
 * Runs real, harmless commands in the background.
 */
final class ShellBackgroundRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pw-runner-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testCommandsRunInOrderAfterTheCallReturns(): void
    {
        $log = $this->directory.'/run.log';

        (new ShellBackgroundRunner($this->directory))->start([['echo', 'first'], ['echo', 'second']], $log);

        self::assertSame("first\nsecond\n", $this->waitFor($log, "second\n"));
    }

    public function testAnArgumentIsNeverRunAsShellCode(): void
    {
        $log = $this->directory.'/run.log';
        $witness = $this->directory.'/witness';

        (new ShellBackgroundRunner($this->directory))->start([['echo', "x'; touch ".$witness.' #'], ['echo', 'done']], $log);

        self::assertStringContainsString("x'; touch", $this->waitFor($log, "done\n"), 'printed as text');
        self::assertFileDoesNotExist($witness, 'and never executed');
    }

    private function waitFor(string $log, string $ending): string
    {
        for ($i = 0; $i < 50; ++$i) {
            $content = is_file($log) ? (string) file_get_contents($log) : '';

            if (str_ends_with($content, $ending)) {
                return $content;
            }

            usleep(100_000);
        }

        self::fail('the background commands did not finish within 5 s');
    }
}
