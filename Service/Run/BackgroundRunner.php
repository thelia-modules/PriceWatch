<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

/**
 * Hands a pass to whatever runs it after the web request has returned.
 */
interface BackgroundRunner
{
    public function start(RunPass $pass): void;

    /**
     * True while a pass asked for waits on the queue for a worker.
     */
    public function hasPending(): bool;

    /**
     * True when no worker takes the queue: the pass would run at once, inside
     * the call that asks for it. Fine for a command, never for a web request.
     */
    public function runsInline(): bool;
}
