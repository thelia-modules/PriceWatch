<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRunQuery;

/**
 * Passes whose process died without closing their row: killed, or caught by
 * a cache clear that removed the code under them (seen on 2026-10-09, pass
 * #3023). Called by a command once it holds its pass's lock: any row of that
 * pass still "started" then belongs to a process that no longer exists.
 */
final readonly class AbandonedRuns
{
    public const MESSAGE = 'Interrupted: the process stopped before the end of the pass (killed, or the cache was cleared while it ran).';

    public function close(string $pass): int
    {
        $abandoned = PwCollectRunQuery::create()->filterByPassName($pass)->filterByStatus('started')->find();

        foreach ($abandoned as $run) {
            $run->setStatus('failed')
                ->setFinishedAt($run->getUpdatedAt() ?? new \DateTime())
                ->setErrorsJson(json_encode([self::MESSAGE], \JSON_UNESCAPED_UNICODE))
                ->save();
        }

        return \count($abandoned);
    }
}
