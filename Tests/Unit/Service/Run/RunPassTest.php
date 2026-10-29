<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\RunPass;

final class RunPassTest extends TestCase
{
    public function testAPassSearchesTheSitemapsThenGoogleThenReadsAndJudges(): void
    {
        $steps = (new RunPass('abc', 4, ['1520190076NN']))->steps();

        self::assertSame(['pricewatch:direct', 'pricewatch:collect', 'pricewatch:direct', 'pricewatch:judge'], array_column($steps, 'command'));
        [$sitemaps, $collect, $direct, $judge] = array_column($steps, 'input');
        self::assertTrue($sitemaps['--discover'], 'free first: the sitemaps and the known pages');
        self::assertSame(['1520190076NN'], $sitemaps['--product']);
        self::assertSame('4', $collect['--limit']);
        self::assertTrue($collect['--priority'], 'whoever clicked waits for an answer in minutes');
        self::assertSame('600', $collect['--max-wait']);
        self::assertSame('0.016', $collect['--budget-usd'], 'the cap is the worst case announced on the button');
        self::assertSame(['1520190076NN'], $collect['--product']);
        self::assertTrue($direct['--candidates'], 'the pages Google led to, read straight before the checks');
        self::assertSame(['1520190076NN'], $direct['--product']);
        self::assertSame([], $judge, 'judged right after, so the comparator fills up');
    }

    public function testASeveralProductPassKeepsEachRefAsItsOwnValue(): void
    {
        $collect = (new RunPass('abc', 6, ['A', '1SAA340']))->steps()[1]['input'];

        self::assertSame(['A', '1SAA340'], $collect['--product']);
    }

    public function testAPassWithoutProductTakesTheVariantsCollectedLongestAgo(): void
    {
        foreach ((new RunPass('abc', 50))->steps() as $step) {
            self::assertArrayNotHasKey('--product', $step['input']);
        }
    }

    public function testAScheduledPassUsesTheStandardQueue(): void
    {
        $collect = (new RunPass('abc', 10, ['1SAA340'], false))->steps()[1]['input'];

        self::assertArrayNotHasKey('--priority', $collect, 'nobody waits for a scheduled pass');
        self::assertSame('3000', $collect['--max-wait'], 'the standard queue takes up to 45 minutes');
        self::assertSame('0.020', $collect['--budget-usd'], 'the cap follows the queue price');
    }

    public function testTheMessageCarriesOnlyPlainValues(): void
    {
        $pass = new RunPass('abc', 4, ['A']);

        self::assertSame(
            ['passId' => 'abc', 'variants' => 4, 'productRefs' => ['A'], 'priority' => true, 'logFile' => 'var/log/pricewatch-manual.log'],
            get_object_vars($pass),
            'what a queue writer can set: values, never a command',
        );
    }
}
