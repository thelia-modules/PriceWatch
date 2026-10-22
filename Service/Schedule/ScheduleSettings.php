<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Service\Settings\ConfigStore;
use PriceWatch\Service\Settings\InvalidSettingException;

/**
 * The settings of the scheduled passes (asked 2026-10-20), off until someone
 * turns them on: a cron line alone never spends anything.
 */
final readonly class ScheduleSettings
{
    private const ENABLED = 'schedule_enabled';
    private const DAYS_PREFIX = 'schedule_days_';
    private const START_HOUR = 'schedule_start_hour';
    private const END_HOUR = 'schedule_end_hour';
    private const MAX_VARIANTS = 'schedule_max_variants';
    private const LAST_PASS = 'schedule_last_pass';

    public const MAX_DAYS = 90;

    /** At night, away from the shop's busy hours. */
    public const DEFAULT_START_HOUR = 2;
    public const DEFAULT_END_HOUR = 7;

    public const DEFAULT_MAX_VARIANTS = 100;

    /** The largest batch the back office offers by hand. */
    public const MAX_VARIANTS_LIMIT = 500;

    public function __construct(
        private ConfigStore $store,
    ) {
    }

    public function enabled(): bool
    {
        return $this->store->get(self::ENABLED) === '1';
    }

    public function setEnabled(bool $enabled): void
    {
        $this->store->set(self::ENABLED, $enabled ? '1' : '0');
    }

    public function days(WatchTier $tier): int
    {
        $stored = $this->store->get(self::DAYS_PREFIX.$tier->value);

        return ctype_digit($stored) && (int) $stored >= 1 && (int) $stored <= self::MAX_DAYS ? (int) $stored : $tier->defaultDays();
    }

    /**
     * @return array<string, int> tier value => days
     */
    public function allDays(): array
    {
        $days = [];

        foreach (WatchTier::cases() as $tier) {
            $days[$tier->value] = $this->days($tier);
        }

        return $days;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setDays(WatchTier $tier, string $value): void
    {
        $value = trim($value);

        if ($value === '') {
            $this->store->set(self::DAYS_PREFIX.$tier->value, '');

            return;
        }

        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_DAYS) {
            throw new InvalidSettingException(self::DAYS_PREFIX.$tier->value, \sprintf('A reading period is a whole number of days, from 1 to %d.', self::MAX_DAYS));
        }

        $this->store->set(self::DAYS_PREFIX.$tier->value, (string) (int) $value);
    }

    public function startHour(): int
    {
        return self::hour($this->store->get(self::START_HOUR), self::DEFAULT_START_HOUR);
    }

    public function endHour(): int
    {
        return self::hour($this->store->get(self::END_HOUR), self::DEFAULT_END_HOUR);
    }

    /**
     * @throws InvalidSettingException
     */
    public function setHours(string $start, string $end): void
    {
        foreach ([self::START_HOUR => trim($start), self::END_HOUR => trim($end)] as $key => $value) {
            if ($value !== '' && (!ctype_digit($value) || (int) $value > 23)) {
                throw new InvalidSettingException($key, 'An hour is a whole number from 0 to 23.');
            }
        }

        $this->store->set(self::START_HOUR, trim($start));
        $this->store->set(self::END_HOUR, trim($end));
    }

    /**
     * From the start hour included to the end hour excluded, across midnight
     * when the end comes first; the same hour twice means all day.
     */
    public function isWithinHours(\DateTimeInterface $now): bool
    {
        $hour = (int) $now->format('G');
        $start = $this->startHour();
        $end = $this->endHour();

        return match (true) {
            $start === $end => true,
            $start < $end => $hour >= $start && $hour < $end,
            default => $hour >= $start || $hour < $end,
        };
    }

    public function maxVariants(): int
    {
        $stored = $this->store->get(self::MAX_VARIANTS);

        return ctype_digit($stored) && (int) $stored >= 1 && (int) $stored <= self::MAX_VARIANTS_LIMIT ? (int) $stored : self::DEFAULT_MAX_VARIANTS;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setMaxVariants(string $value): void
    {
        $value = trim($value);

        if ($value !== '' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > self::MAX_VARIANTS_LIMIT)) {
            throw new InvalidSettingException(self::MAX_VARIANTS, \sprintf('The variants per scheduled pass are a whole number from 1 to %d.', self::MAX_VARIANTS_LIMIT));
        }

        $this->store->set(self::MAX_VARIANTS, $value === '' ? '' : (string) (int) $value);
    }

    /**
     * @return array{at: string, products: int, variants: int, costUsd: float}|null
     */
    public function lastPass(): ?array
    {
        $decoded = json_decode($this->store->get(self::LAST_PASS), true);

        if (!\is_array($decoded) || !isset($decoded['at'], $decoded['products'], $decoded['variants'], $decoded['costUsd'])) {
            return null;
        }

        return ['at' => (string) $decoded['at'], 'products' => (int) $decoded['products'], 'variants' => (int) $decoded['variants'], 'costUsd' => (float) $decoded['costUsd']];
    }

    public function recordPass(\DateTimeInterface $at, int $products, int $variants, float $costUsd): void
    {
        $this->store->set(self::LAST_PASS, json_encode([
            'at' => $at->format(\DATE_ATOM),
            'products' => $products,
            'variants' => $variants,
            'costUsd' => $costUsd,
        ], \JSON_THROW_ON_ERROR));
    }

    private static function hour(string $stored, int $default): int
    {
        return ctype_digit($stored) && (int) $stored <= 23 ? (int) $stored : $default;
    }
}
