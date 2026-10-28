<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

final class PassAlreadyRunning extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A price watch pass is already running.');
    }
}
