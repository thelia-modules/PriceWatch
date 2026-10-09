<?php

declare(strict_types=1);

namespace PriceWatch\Service\Usage;

use Psr\Clock\ClockInterface;

/**
 * The token and cost counter: per paid service, today, over 30 days and
 * since the start. Amounts are in USD, the currency all three bill in.
 *
 * Jev's cost is computed from its input tokens at the list price (output is
 * free); DataForSEO's and the reasoning model's are the amounts the services
 * themselves returned. The screen says which is which.
 */
final readonly class UsagePresenter
{
    public const PERIODS = ['today', 'month', 'all'];

    public function __construct(
        private UsageRepository $repository,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * @return array{periods: list<string>, sources: list<array<string, mixed>>, total: array<string, string>}
     */
    public function present(string $locale): array
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $totals = [
            'today' => $this->repository->totals($now->setTime(0, 0)),
            'month' => $this->repository->totals($now->modify('-30 days')),
            'all' => $this->repository->totals(),
        ];

        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        // Most passes cost fractions of a cent: two decimals would show 0.00.
        $money->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 4);
        $money->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $count = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $usd = static fn (float $value): string => (string) $money->formatCurrency($value, 'USD');
        $n = static fn (float $value): string => (string) $count->format($value);

        $sources = [
            [
                'key' => 'jev',
                'billing' => 'estimated',
                'cells' => array_map(static fn (array $t): array => [
                    'cost' => $usd($t['jevCostUsd']),
                    'detail' => ['tokens' => $n($t['jevInputTokens']), 'requests' => $n($t['jevRequests']), 'cached' => $n($t['jevCacheHits'])],
                ], $totals),
            ],
            [
                'key' => 'llm',
                'billing' => 'billed',
                'cells' => array_map(static fn (array $t): array => [
                    'cost' => $usd($t['llmCostUsd']),
                    'detail' => ['tokens' => $n($t['llmPromptTokens'] + $t['llmCompletionTokens']), 'requests' => $n($t['llmCalls'])],
                ], $totals),
            ],
            [
                'key' => 'dataforseo',
                'billing' => 'billed',
                'cells' => array_map(static fn (array $t): array => [
                    'cost' => $usd($t['dataforseoCostUsd']),
                    'detail' => ['tasks' => $n($t['dataforseoTasks'])],
                ], $totals),
            ],
        ];

        return [
            'periods' => self::PERIODS,
            'sources' => $sources,
            'total' => array_map(static fn (array $t): string => $usd($t['jevCostUsd'] + $t['llmCostUsd'] + $t['dataforseoCostUsd']), $totals),
        ];
    }
}
