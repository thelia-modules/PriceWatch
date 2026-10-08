<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * Parses "Taille:L|Coloris:Noir" into an ordered map.
 *
 * Keys are sorted on output so that the order the merchant typed them in never
 * changes the row checksum, which is what makes a re-import idempotent.
 */
final readonly class PairListParser
{
    /**
     * @return array<string, string>|null null when the syntax is broken
     */
    public function parse(?string $raw): ?array
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return [];
        }

        $pairs = [];

        foreach (explode('|', $value) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                return null;
            }

            $separatorPosition = strpos($chunk, ':');

            if ($separatorPosition === false) {
                return null;
            }

            $name = trim(substr($chunk, 0, $separatorPosition));
            $pairValue = trim(substr($chunk, $separatorPosition + 1));

            if ($name === '' || $pairValue === '') {
                return null;
            }

            $pairs[$name] = $pairValue;
        }

        ksort($pairs);

        return $pairs;
    }
}
