<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

/**
 * Thin seam over module_config, so settings logic is testable without a
 * database. An empty string means "not set".
 */
interface ConfigStore
{
    public function get(string $key): string;

    public function set(string $key, string $value): void;
}
