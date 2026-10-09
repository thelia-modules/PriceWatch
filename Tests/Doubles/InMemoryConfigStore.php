<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Doubles;

use PriceWatch\Service\Settings\ConfigStore;

final class InMemoryConfigStore implements ConfigStore
{
    /** @param array<string, string> $values */
    public function __construct(public array $values = [])
    {
    }

    public function get(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }
}
