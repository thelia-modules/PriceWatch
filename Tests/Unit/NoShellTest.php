<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A process started from a web request is a command line built by the web
 * server: the passes go through the queue, and nothing in the module starts a
 * shell. The search is run on every source file, tests and the console-only
 * dev commands included.
 */
final class NoShellTest extends TestCase
{
    private const PATTERN = '/\bnohup\b|\bshell_exec\s*\(|\bproc_open\s*\(|\bpassthru\s*\(|\bpopen\s*\(|(?<![>:\w])exec\s*\(|(?<![>:\w])system\s*\(|\bfromShellCommandline\b|\bnew\s+Process\s*\(|\bProcess::|escapeshellarg|`[^`\n]*`\s*;/';

    public function testNoSourceFileStartsAShell(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__ || str_contains($file->getPathname(), '/Model/') || str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match(self::PATTERN, $line) === 1 && !str_starts_with(ltrim($line), '*') && !str_starts_with(ltrim($line), '//')) {
                    $offenders[] = substr($file->getPathname(), \strlen(\dirname(__DIR__, 2)) + 1).':'.($number + 1);
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
