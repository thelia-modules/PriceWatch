<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

final readonly class ProcessServerEnvironment implements ServerEnvironment
{
    public function get(string $name): ?string
    {
        // Dotenv fills $_SERVER and $_ENV; a real process variable only shows
        // up through getenv(). Read all three.
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
