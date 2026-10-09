<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

/**
 * Starts console commands that outlive the web request which asked for them.
 */
interface BackgroundRunner
{
    /**
     * @param list<list<string>> $commands run one after the other, each as an argument list
     */
    public function start(array $commands, string $logFile): void;
}
