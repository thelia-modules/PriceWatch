<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

use PriceWatch\PriceWatch;

final readonly class ModuleConfigStore implements ConfigStore
{
    public function get(string $key): string
    {
        return (string) PriceWatch::getConfigValue($key, '');
    }

    public function set(string $key, string $value): void
    {
        PriceWatch::setConfigValue($key, $value);
    }
}
