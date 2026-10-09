<?php

declare(strict_types=1);

namespace PriceWatch\Service\Usage;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Service\Llm\LlmAnswer;

/**
 * Writes what a call to the reasoning model consumed into the same ledger as
 * the collect and judge passes (pw_collect_run), so that one table answers
 * "what did this module cost, and on what".
 */
final readonly class UsageLedger
{
    public function recordModelCall(string $pass, LlmAnswer $answer): PwCollectRun
    {
        $now = new \DateTime();
        $run = (new PwCollectRun())
            ->setPassName($pass)
            ->setStatus('success')
            ->setStartedAt($now)
            ->setFinishedAt($now)
            ->setLlmModel(mb_substr($answer->model, 0, 100))
            ->setLlmPromptTokens($answer->promptTokens)
            ->setLlmCompletionTokens($answer->completionTokens)
            ->setLlmCostUsd($answer->costUsd === null ? null : number_format($answer->costUsd, 8, '.', ''));
        $run->save();

        return $run;
    }
}
