<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * GTIN-8, UPC-A (12), EAN-13 and GTIN-14 all normalise to 14 digits.
 *
 * Left padding with zeros is safe: a digit's weight depends on its distance to
 * the check digit, which padding does not change.
 */
final readonly class GtinNormalizer
{
    private const ACCEPTED_LENGTHS = [8, 12, 13, 14];

    public function normalize(?string $raw): NormalizedGtin
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if (!\in_array(\strlen($digits), self::ACCEPTED_LENGTHS, true)) {
            return NormalizedGtin::unusable($raw);
        }

        $padded = str_pad($digits, 14, '0', \STR_PAD_LEFT);

        return new NormalizedGtin($raw, $padded, $this->hasValidCheckDigit($padded));
    }

    public function hasValidCheckDigit(string $gtin14): bool
    {
        if (preg_match('/^\d{14}$/', $gtin14) !== 1) {
            return false;
        }

        return (int) $gtin14[13] === $this->checkDigit(substr($gtin14, 0, 13));
    }

    public function checkDigit(string $body): int
    {
        $sum = 0;
        $length = \strlen($body);

        for ($position = 0; $position < $length; ++$position) {
            $weight = ($length - $position) % 2 === 1 ? 3 : 1;
            $sum += (int) $body[$position] * $weight;
        }

        return (10 - $sum % 10) % 10;
    }
}
