<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A language model is reached through the gateway of the core, which holds the
 * connection, the key, the budget and the log: no source file of the module
 * calls a chat or model-listing endpoint of a provider itself. Jev (a typed
 * judgment endpoint) and DataForSEO are not language models and keep their
 * own clients.
 */
final class NoDirectModelCallTest extends TestCase
{
    private const PATTERN = '#chat/completions|/v1/messages|/v1/responses|api\.openai\.com|api\.anthropic\.com|api\.mistral\.ai|openrouter\.ai/api/v1/(models|chat)#';

    public function testNoSourceFileCallsAModelProvider(): void
    {
        $offenders = [];
        $root = \dirname(__DIR__, 2);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getRealPath() === __FILE__ || str_contains($file->getPathname(), '/Tests/') || str_contains($file->getPathname(), '/Model/') || str_contains($file->getPathname(), '/vendor/')) {
                continue;
            }

            if (preg_match(self::PATTERN, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders);
    }
}
