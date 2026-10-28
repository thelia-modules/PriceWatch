<?php

declare(strict_types=1);

namespace PriceWatch\Service\Ai;

use Thelia\Domain\Ai\AiActor;
use Thelia\Domain\Ai\AiCostRecorderInterface;
use Thelia\Domain\Ai\AiExternalCost;
use Thelia\Domain\Ai\AiJurisdiction;
use Thelia\Domain\Ai\AiTokenUsage;
use Thelia\Domain\Ai\AiUsageLogStatus;
use Thelia\Domain\Ai\Exception\AiExceptionInterface;

/**
 * What the module pays outside the gateway (Jev, DataForSEO), accounted in the
 * usage log of the core: asked before the call, written after it, so the
 * budget of the usage and the consumption screen count it.
 *
 * Jurisdictions: typesafe.ai says it hosts in the United States; OpenRouter
 * routes outside the EU by default; DataForSEO is an Estonian company with
 * operations in Ukraine, and where it processes is not established. All three
 * are declared outside the EU until the product owner decides otherwise: with
 * the merchant's option "EU only" off, nothing is refused and the screen
 * informs; with it on, these services are refused.
 */
final readonly class ExternalSpend
{
    public const TYPESAFE = 'typesafe';
    public const OPENROUTER = 'openrouter';
    public const DATAFORSEO = 'dataforseo';

    private const PROCESS = 'pricewatch';

    public function __construct(private AiCostRecorderInterface $costs)
    {
    }

    /**
     * @throws SpendRefusedException
     */
    public function authorize(string $usageId, string $provider, float $maximumUsd): void
    {
        try {
            $this->costs->authorize($usageId, $provider, AiJurisdiction::OutsideEuropeanUnion, self::amount($maximumUsd));
        } catch (AiExceptionInterface $refusal) {
            throw new SpendRefusedException($refusal->getMessage(), 0, $refusal);
        }
    }

    public function record(string $usageId, string $provider, string $operation, float $costUsd, ?AiTokenUsage $tokens = null, ?int $latencyMilliseconds = null): void
    {
        $this->costs->record(new AiExternalCost(
            usageId: $usageId,
            provider: $provider,
            operation: $operation,
            cost: self::amount($costUsd),
            tokenUsage: $tokens,
            latencyMilliseconds: $latencyMilliseconds,
            actor: AiActor::system(self::PROCESS),
        ));
    }

    /**
     * The call failed after it was authorized: nothing is billed, and writing the
     * row settles the amount reserved for the call.
     */
    public function fail(string $usageId, string $provider, string $operation): void
    {
        $this->costs->record(new AiExternalCost(
            usageId: $usageId,
            provider: $provider,
            operation: $operation,
            cost: self::amount(0.0),
            status: AiUsageLogStatus::Failed,
            actor: AiActor::system(self::PROCESS),
        ));
    }

    /**
     * Never `(string) 1.2E-7`: the log takes a plain decimal.
     */
    private static function amount(float $usd): string
    {
        return \sprintf('%.8F', max(0.0, $usd));
    }
}
