<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use PriceWatch\Service\Settings\ServerEnvironment;

final readonly class ArrayServerEnvironment implements ServerEnvironment
{
    /** @param array<string, string> $variables */
    public function __construct(private array $variables = [])
    {
    }

    public function get(string $name): ?string
    {
        $value = trim($this->variables[$name] ?? '');

        return $value === '' ? null : $value;
    }
}
