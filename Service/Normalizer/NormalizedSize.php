<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

final readonly class NormalizedSize
{
    public function __construct(
        public ?string $raw,
        public SizeFamily $family,
        public ?string $canonical,
        public ?string $alphaHint = null,
    ) {
    }

    public static function unknown(?string $raw): self
    {
        return new self($raw, SizeFamily::Unknown, null);
    }

    /**
     * Two sizes only compare inside the same family. A "52" garment number and
     * a "52" head circumference are both numeric but mean nothing to each
     * other, so equality is necessary and never sufficient on its own.
     */
    public function matches(self $other): bool
    {
        if ($this->family === SizeFamily::Unknown || $other->family !== $this->family) {
            return false;
        }

        if ($this->canonical === $other->canonical) {
            return true;
        }

        return $this->family === SizeFamily::Numeric
            && $this->alphaHint !== null
            && $this->alphaHint === $other->alphaHint;
    }
}
