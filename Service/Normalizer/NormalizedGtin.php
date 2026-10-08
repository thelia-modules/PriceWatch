<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

final readonly class NormalizedGtin
{
    public function __construct(
        public ?string $raw,
        public ?string $value,
        public bool $checkDigitValid,
    ) {
    }

    public static function unusable(?string $raw): self
    {
        return new self($raw, null, false);
    }

    /**
     * The only form the deterministic matching rail accepts: 14 digits with a
     * valid check digit. Everything else must fall through to another rail.
     */
    public function isUsable(): bool
    {
        return $this->value !== null && $this->checkDigitValid;
    }
}
