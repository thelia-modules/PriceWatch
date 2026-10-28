<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

class PassAlreadyRunning extends \RuntimeException
{
    public function __construct(string $message = 'A price watch pass is already running.')
    {
        parent::__construct($message);
    }
}
