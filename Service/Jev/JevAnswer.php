<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

/**
 * A validated, typed verdict. The band is where code decides: "yes"/"no" for
 * a noul, "act" for a confident choice or score, "review" otherwise. Only a
 * decisive band may drive an automatic decision; review is a signal for a
 * human, never a pass.
 */
final readonly class JevAnswer
{
    public const YES = 'yes';
    public const NO = 'no';
    public const ACT = 'act';
    public const REVIEW = 'review';

    /** Decisive thresholds, as in jevseo/jev.py:27-28. */
    public const DECISIVE = 0.80;
    public const NOUL_NO = 0.20;

    /**
     * @param array<string, float> $probabilities
     */
    public function __construct(
        public string $type,
        public float|string $value,
        public string $band,
        public ?float $confidence,
        public array $probabilities,
        public bool $fromCache = false,
    ) {
    }

    public function isDecisive(): bool
    {
        return $this->band !== self::REVIEW;
    }

    public function cached(): self
    {
        return new self($this->type, $this->value, $this->band, $this->confidence, $this->probabilities, true);
    }
}
