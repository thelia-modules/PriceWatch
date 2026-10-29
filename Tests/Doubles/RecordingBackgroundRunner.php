<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use PriceWatch\Service\Run\BackgroundRunner;
use PriceWatch\Service\Run\RunPass;

final class RecordingBackgroundRunner implements BackgroundRunner
{
    /** @var list<RunPass> */
    public array $passes = [];

    public function __construct(public bool $pending = false, public bool $inline = false)
    {
    }

    public function start(RunPass $pass): void
    {
        $this->passes[] = $pass;
    }

    public function hasPending(): bool
    {
        return $this->pending;
    }

    public function runsInline(): bool
    {
        return $this->inline;
    }
}
