<?php

declare(strict_types=1);

namespace PriceWatch\Service\Usage;

use PriceWatch\Model\PwCollectRun;
use Thelia\Domain\Ai\AiResponse;

/**
 * Writes what a call to the model consumed into the same ledger as
 * the collect and judge passes (pw_collect_run), so that one table answers
 * "what did this module cost, and on what".
 */
final readonly class UsageLedger
{
    public function recordModelCall(string $pass, AiResponse $answer): PwCollectRun
    {
        $now = new \DateTime();
        $run = (new PwCollectRun())
            ->setPassName($pass)
            ->setStatus('success')
            ->setStartedAt($now)
            ->setFinishedAt($now)
            ->setLlmModel(mb_substr($answer->model, 0, 100))
            ->setLlmPromptTokens($answer->tokenUsage->inputTokens)
            ->setLlmCompletionTokens($answer->tokenUsage->outputTokens)
            ->setLlmCostUsd($answer->cost);
        $run->save();

        return $run;
    }
}
