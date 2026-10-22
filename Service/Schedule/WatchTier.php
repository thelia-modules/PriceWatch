<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

/**
 * How often the scheduled passes read a product (asked 2026-10-20). The
 * periods are settings (ScheduleSettings); these are their defaults.
 */
enum WatchTier: string
{
    case Flagship = 'flagship';
    case Base = 'base';
    case Simple = 'simple';

    /** What a product without a tier of its own is: newly watched ones included. */
    public const DEFAULT = self::Base;

    public function defaultDays(): int
    {
        return match ($this) {
            self::Flagship => 1,
            self::Base => 7,
            self::Simple => 15,
        };
    }

    /**
     * First read when several products are due at once.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Flagship => 0,
            self::Base => 1,
            self::Simple => 2,
        };
    }

    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DEFAULT;
    }
}
