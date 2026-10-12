<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Normalizer\NormalizedSize;
use PriceWatch\Service\Normalizer\SizeNormalizer;

/**
 * A Google Shopping card lists its sellers for one size, named in each
 * offer's details (measured 2026-10-12: "Casque SZ-R Vas Evo Arai Noir Mat S
 * - casque jet moto"). Recording such an offer against every variant of the
 * product paid Jev to reject four sizes out of five.
 *
 * Code only narrows, never decides: when the details name exactly one of our
 * sizes, only the variants of that size are kept, and Jev still judges them.
 * Anything else keeps every variant, as before.
 */
final readonly class ShownSize
{
    public function __construct(private SizeNormalizer $sizes)
    {
    }

    /**
     * @param array<array-key, ?string> $sizeByTag tag => our size label
     *
     * @return list<string> the tags the offer can be about
     */
    public function variantsShown(?string $details, array $sizeByTag): array
    {
        $all = array_map('strval', array_keys($sizeByTag));
        $ours = array_map(fn (?string $label): NormalizedSize => $this->sizes->normalize($label), $sizeByTag);
        $named = [];

        foreach ($this->sizes->mentioned($details) as $mentioned) {
            foreach ($ours as $size) {
                if ($size->matches($mentioned)) {
                    $named[$size->canonical] = true;
                }
            }
        }

        if (\count($named) !== 1) {
            return $all;
        }

        $shown = (string) array_key_first($named);
        $kept = [];

        foreach ($ours as $tag => $size) {
            // A variant whose size we do not know is never dropped on a guess.
            if ($size->canonical === null || $size->canonical === $shown) {
                $kept[] = (string) $tag;
            }
        }

        return $kept;
    }
}
