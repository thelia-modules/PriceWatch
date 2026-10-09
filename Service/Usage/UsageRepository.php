<?php

declare(strict_types=1);

namespace PriceWatch\Service\Usage;

use PriceWatch\Model\Map\PwCollectRunTableMap;
use PriceWatch\Model\PwCollectRunQuery;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * What the paid services consumed, summed from the pass ledger
 * (pw_collect_run): every collect, judge and suggestion pass writes its own
 * counts there.
 */
final readonly class UsageRepository
{
    private const SUMS = [
        'jevRequests' => PwCollectRunTableMap::COL_JEV_REQUESTS,
        'jevCacheHits' => PwCollectRunTableMap::COL_JEV_CACHE_HITS,
        'jevInputTokens' => PwCollectRunTableMap::COL_JEV_INPUT_TOKENS,
        'jevCostUsd' => PwCollectRunTableMap::COL_JEV_COST_USD,
        'dataforseoTasks' => PwCollectRunTableMap::COL_DATAFORSEO_TASKS,
        'dataforseoCostUsd' => PwCollectRunTableMap::COL_DATAFORSEO_COST_USD,
        'llmPromptTokens' => PwCollectRunTableMap::COL_LLM_PROMPT_TOKENS,
        'llmCompletionTokens' => PwCollectRunTableMap::COL_LLM_COMPLETION_TOKENS,
        'llmCostUsd' => PwCollectRunTableMap::COL_LLM_COST_USD,
    ];

    /**
     * @return array<string, float> each sum of SUMS, plus llmCalls
     */
    public function totals(?\DateTimeInterface $since = null): array
    {
        $query = PwCollectRunQuery::create();

        if ($since !== null) {
            $query->filterByStartedAt($since, Criteria::GREATER_EQUAL);
        }

        foreach (self::SUMS as $alias => $column) {
            $query->withColumn('COALESCE(SUM('.$column.'), 0)', $alias);
        }

        $query->withColumn('SUM('.PwCollectRunTableMap::COL_LLM_MODEL.' IS NOT NULL)', 'llmCalls');
        $row = $query->select([...array_keys(self::SUMS), 'llmCalls'])->findOne();

        $totals = [];

        foreach ([...array_keys(self::SUMS), 'llmCalls'] as $alias) {
            $totals[$alias] = (float) ($row[$alias] ?? 0);
        }

        return $totals;
    }
}
