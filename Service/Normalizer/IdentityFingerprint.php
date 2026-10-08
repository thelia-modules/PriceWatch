<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * Fingerprints the remote identity of an offer. When it changes, the competitor
 * has altered what sits behind the URL, so a previously granted match is no
 * longer trustworthy and must be judged again or sent back to a human.
 *
 * Price is deliberately absent: a price change is a normal event and must not
 * invalidate a match.
 */
final readonly class IdentityFingerprint
{
    public function __construct(
        private TextNormalizer $textNormalizer,
        private BrandNormalizer $brandNormalizer,
        private GtinNormalizer $gtinNormalizer,
    ) {
    }

    public function forRemoteOffer(
        ?string $title,
        ?string $brand,
        ?string $gtin,
        ?string $variantLabel,
    ): string {
        $parts = [
            $this->textNormalizer->normalize($title),
            $this->brandNormalizer->normalize($brand) ?? '',
            $this->gtinNormalizer->normalize($gtin)->value ?? '',
            $this->textNormalizer->normalize($variantLabel),
        ];

        return sha1(implode("\x1f", $parts));
    }
}
