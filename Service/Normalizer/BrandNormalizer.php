<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * Brands compare on their folded form with spaces removed, so "Ixon", "IXON"
 * and "ixon " all collapse to "ixon".
 *
 * Known limit: a retailer writing "Five Gloves" where we write "Five" will not
 * match. Resolving that needs a curated alias table, which is a separate
 * decision and is not guessed here.
 */
final readonly class BrandNormalizer
{
    public function __construct(private TextNormalizer $textNormalizer)
    {
    }

    public function normalize(?string $raw): ?string
    {
        $folded = str_replace(' ', '', $this->textNormalizer->normalize($raw));

        return $folded === '' ? null : $folded;
    }
}
