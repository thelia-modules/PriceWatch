<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Service\Usage\UsagePresenter;
use PriceWatch\Service\Usage\UsageRepository;
use Psr\Clock\ClockInterface;

/**
 * "Today" is pinned in 2037 (TIMESTAMP ends in 2038), so the periods only hold the runs this test
 * writes, whatever the development database already contains.
 */
final class UsageTest extends PriceWatchIntegrationTestCase
{
    public const TODAY = '2037-06-15 12:00:00';

    public function testEachServiceIsSummedPerPeriod(): void
    {
        $this->ledgerRow('2037-06-15 08:00:00', jevTokens: 967, jevCost: '0.00004061', jevRequests: 1, jevCached: 2);
        $this->ledgerRow('2037-06-15 09:00:00', dataforseoTasks: 25, dataforseoCost: '0.05');
        $this->ledgerRow('2037-06-15 10:00:00', llmModel: 'vendor/model', llmPrompt: 1234, llmCompletion: 567, llmCost: '0.00421');
        // Twenty days back: in the 30 days, not today.
        $this->ledgerRow('2037-05-26 10:00:00', dataforseoTasks: 10, dataforseoCost: '0.02');
        // Forty days back: outside the 30 days.
        $this->ledgerRow('2037-05-06 10:00:00', dataforseoTasks: 100, dataforseoCost: '0.20');

        $repository = new UsageRepository();
        $today = $repository->totals(new \DateTimeImmutable('2037-06-15 00:00:00'));
        $month = $repository->totals(new \DateTimeImmutable('2037-05-16 12:00:00'));

        self::assertSame(967.0, $today['jevInputTokens']);
        self::assertSame(1.0, $today['jevRequests']);
        self::assertSame(2.0, $today['jevCacheHits']);
        self::assertSame(1801.0, $today['llmPromptTokens'] + $today['llmCompletionTokens']);
        self::assertSame(1.0, $today['llmCalls'], 'only runs of the reasoning model count as its calls');
        self::assertSame(25.0, $today['dataforseoTasks']);
        self::assertSame(35.0, $month['dataforseoTasks']);
        self::assertEqualsWithDelta(0.07, $month['dataforseoCostUsd'], 1e-9);
    }

    public function testTheCounterShowsFractionsOfACent(): void
    {
        $this->ledgerRow('2037-06-15 08:00:00', jevTokens: 967, jevCost: '0.00004061', jevRequests: 1);
        $this->ledgerRow('2037-06-15 10:00:00', llmModel: 'vendor/model', llmPrompt: 1234, llmCompletion: 567, llmCost: '0.00421');

        $usage = $this->presenter()->present('en_US');

        $jev = $usage['sources'][0];
        self::assertSame('jev', $jev['key']);
        self::assertSame('estimated', $jev['billing'], 'Jev is computed from tokens, not billed per call');
        self::assertSame('$0.00', $jev['cells']['today']['cost'], 'four decimals at most: 0.00004 rounds to zero');
        self::assertSame('967', $jev['cells']['today']['detail']['tokens']);
        self::assertSame('$0.0042', $usage['sources'][1]['cells']['today']['cost']);
        self::assertSame('1,801', $usage['sources'][1]['cells']['today']['detail']['tokens']);
        self::assertSame('$0.0043', $usage['total']['today'], 'the total adds the unrounded amounts: 0.0000406 + 0.00421');
    }

    private function presenter(): UsagePresenter
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(UsageTest::TODAY);
            }
        };

        return new UsagePresenter(new UsageRepository(), $clock);
    }

    private function ledgerRow(
        string $startedAt,
        int $jevTokens = 0,
        ?string $jevCost = null,
        int $jevRequests = 0,
        int $jevCached = 0,
        int $dataforseoTasks = 0,
        ?string $dataforseoCost = null,
        ?string $llmModel = null,
        int $llmPrompt = 0,
        int $llmCompletion = 0,
        ?string $llmCost = null,
    ): void {
        (new PwCollectRun())
            ->setPassName('usage-test')
            ->setStatus('success')
            ->setStartedAt(new \DateTime($startedAt))
            ->setJevInputTokens($jevTokens)
            ->setJevCostUsd($jevCost)
            ->setJevRequests($jevRequests)
            ->setJevCacheHits($jevCached)
            ->setDataforseoTasks($dataforseoTasks)
            ->setDataforseoCostUsd($dataforseoCost)
            ->setLlmModel($llmModel)
            ->setLlmPromptTokens($llmPrompt)
            ->setLlmCompletionTokens($llmCompletion)
            ->setLlmCostUsd($llmCost)
            ->save();
    }
}
