<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Schedule;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Schedule\WatchTier;
use PriceWatch\Service\Settings\InvalidSettingException;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;

final class ScheduleSettingsTest extends TestCase
{
    public function testOffByDefaultWithTheAskedPeriods(): void
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());

        self::assertFalse($settings->enabled(), 'a cron line alone never spends anything');
        self::assertSame(['flagship' => 1, 'base' => 7, 'simple' => 15], $settings->allDays());
        self::assertSame(100, $settings->maxVariants());
        self::assertSame([2, 7], [$settings->startHour(), $settings->endHour()]);
    }

    public function testPeriodsAreWholeDaysWithinBounds(): void
    {
        $store = new InMemoryConfigStore();
        $settings = new ScheduleSettings($store);

        $settings->setDays(WatchTier::Base, ' 10 ');
        self::assertSame(10, $settings->days(WatchTier::Base));

        foreach (['0', '91', '2.5', 'abc', '-3'] as $wrong) {
            try {
                $settings->setDays(WatchTier::Base, $wrong);
                self::fail('expected a refusal for '.$wrong);
            } catch (InvalidSettingException) {
            }
        }

        self::assertSame(10, $settings->days(WatchTier::Base), 'a refused value keeps the stored one');

        $settings->setDays(WatchTier::Base, '');
        self::assertSame(7, $settings->days(WatchTier::Base), 'empty: the default');

        $store->values['schedule_days_simple'] = '999';
        self::assertSame(15, $settings->days(WatchTier::Simple), 'an odd stored value reads as the default');
    }

    public function testTheHoursMayCrossMidnight(): void
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());
        $at = static fn (string $hour): \DateTimeImmutable => new \DateTimeImmutable('2026-10-22 '.$hour);

        self::assertTrue($settings->isWithinHours($at('02:00')));
        self::assertTrue($settings->isWithinHours($at('06:59')));
        self::assertFalse($settings->isWithinHours($at('07:00')), 'the end hour is excluded');
        self::assertFalse($settings->isWithinHours($at('01:59')));

        $settings->setHours('22', '3');
        self::assertTrue($settings->isWithinHours($at('23:30')));
        self::assertTrue($settings->isWithinHours($at('02:30')));
        self::assertFalse($settings->isWithinHours($at('12:00')));

        $settings->setHours('5', '5');
        self::assertTrue($settings->isWithinHours($at('12:00')), 'the same hour twice: all day');

        $this->expectException(InvalidSettingException::class);
        $settings->setHours('24', '3');
    }

    public function testTheVariantsCapStaysWithinTheLargestManualBatch(): void
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());

        $settings->setMaxVariants('250');
        self::assertSame(250, $settings->maxVariants());

        $this->expectException(InvalidSettingException::class);
        $settings->setMaxVariants('501');
    }

    public function testTheScheduledTaskIsMissingWhenItHasNotRunForMoreThanTwoHours(): void
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());
        $now = new \DateTimeImmutable('2026-10-28 12:00:00');

        self::assertTrue($settings->taskIsMissing($now), 'never run');

        $settings->recordTick($now->modify('-2 hours'));
        self::assertFalse($settings->taskIsMissing($now), 'two hours exactly: an hourly line may be late');

        $settings->recordTick($now->modify('-2 hours -1 second'));
        self::assertTrue($settings->taskIsMissing($now));
    }

    public function testTheLastPassIsKept(): void
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());
        self::assertNull($settings->lastPass());

        $settings->recordPass(new \DateTimeImmutable('2026-10-22 02:00:00+02:00'), 12, 40, 0.08);

        self::assertSame(['at' => '2026-10-22T02:00:00+02:00', 'products' => 12, 'variants' => 40, 'costUsd' => 0.08], $settings->lastPass());
    }
}
