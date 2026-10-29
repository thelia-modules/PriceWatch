<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use Thelia\Domain\Ai\AiCostRecorderInterface;
use Thelia\Domain\Ai\AiExternalCost;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;

/**
 * The cost journal of the core, as a test sees it: what was asked before each
 * paid call, what was written after, and a budget that can be reached.
 */
final class RecordingCostRecorder implements AiCostRecorderInterface
{
    /** @var list<array{usage: string, provider: string, jurisdiction: AiJurisdiction, maximum: string, currency: string}> */
    public array $authorized = [];

    /** @var list<AiExternalCost> */
    public array $recorded = [];

    /** @var list<string> everything that happened, in order: authorize:<provider>, record:<provider> */
    public array $events = [];

    public function __construct(public bool $budgetReached = false)
    {
    }

    public function authorize(string $usageId, string $provider, AiJurisdiction $jurisdiction, string $maximumCost = '0', string $currency = 'USD'): void
    {
        $this->events[] = 'authorize:'.$provider;

        if ($this->budgetReached) {
            throw new AiBudgetExceededException($usageId);
        }

        $this->authorized[] = ['usage' => $usageId, 'provider' => $provider, 'jurisdiction' => $jurisdiction, 'maximum' => $maximumCost, 'currency' => $currency];
    }

    public function record(AiExternalCost $cost): int
    {
        $this->events[] = 'record:'.$cost->provider;
        $this->recorded[] = $cost;

        return \count($this->recorded);
    }
}
