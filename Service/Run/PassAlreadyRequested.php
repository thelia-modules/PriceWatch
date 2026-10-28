<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

/**
 * A pass already waits for the scheduled task, or runs because of it.
 */
final class PassAlreadyRequested extends PassAlreadyRunning
{
    public function __construct()
    {
        parent::__construct('A pass is already waiting for the scheduled task.');
    }
}
