<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Asked 2026-10-12: the back office talks of checks and reliability, never
 * of the engine behind them. Its name stays in the code and the console.
 */
final class NoEngineNameTest extends TestCase
{
    private const MODULE = __DIR__.'/../../..';

    public function testNoTranslationNamesTheEngine(): void
    {
        foreach (['fr_FR', 'en_US'] as $locale) {
            $catalogue = require self::MODULE.'/I18n/'.$locale.'.php';
            self::assertIsArray($catalogue);

            foreach ($catalogue as $key => $text) {
                self::assertStringNotContainsString('Jev', (string) $text, $locale.': '.$key);
            }
        }
    }

    public function testNoTemplateTextNamesTheEngine(): void
    {
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE.'/templates', \FilesystemIterator::SKIP_DOTS));

        foreach ($templates as $file) {
            if (!str_ends_with((string) $file, '.twig')) {
                continue;
            }

            // Comments are for developers; variable names never print.
            $printed = preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents((string) $file)) ?? '';
            $printed = preg_replace('/\b[a-zA-Z_]*jev[a-zA-Z_]*\b/', '', $printed) ?? '';

            self::assertStringNotContainsString('Jev', $printed, (string) $file);
        }
    }
}
