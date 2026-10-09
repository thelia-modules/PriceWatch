<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

interface ServerEnvironment
{
    /**
     * null when the variable is absent or empty.
     */
    public function get(string $name): ?string;
}
