<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * Manufacturer part numbers are written with every possible separator:
 * IXON-100101045-1023-L, IXON 100101045/1023 L, ixon100101045_1023l.
 * All collapse to IXON1001010451023L.
 */
final readonly class MpnNormalizer
{
    private const MINIMUM_USEFUL_LENGTH = 4;

    public function normalize(?string $raw): ?string
    {
        $upper = strtoupper((string) $raw);
        $compact = preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';

        return \strlen($compact) < self::MINIMUM_USEFUL_LENGTH ? null : $compact;
    }

    /**
     * The MPN rail is only deterministic when the part number carries the
     * variant, otherwise it identifies the article but not the size - which is
     * precisely what must not be guessed in motorcycle gear.
     */
    public function encodesVariant(?string $normalizedMpn, ?string $canonicalSize): bool
    {
        if ($normalizedMpn === null || $canonicalSize === null || \strlen($normalizedMpn) < 8) {
            return false;
        }

        $suffix = preg_replace('/[^A-Z0-9]+/', '', strtoupper($canonicalSize)) ?? '';

        return $suffix !== '' && str_ends_with($normalizedMpn, $suffix);
    }
}
