<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Comparator;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Comparator\PriceChange;

final class PriceChangeTest extends TestCase
{
    public function testTheChangeIsAPercentageOfTheEarlierPrice(): void
    {
        self::assertSame('-42.426', PriceChange::pct('329.99', '189.99'), 'Dafy, Bell Custom 500 Flake, 12 to 13/10/2026');
        self::assertSame('10.000', PriceChange::pct('100.00', '110.00'));
        self::assertNull(PriceChange::pct(null, '110.00'), 'nothing read before');
        self::assertNull(PriceChange::pct('0.00', '110.00'));
    }

    public function testOnlyAMoveOfOnePercentOrMoreIsShown(): void
    {
        self::assertTrue(PriceChange::shown('-1.000'));
        self::assertTrue(PriceChange::shown('42.4'));
        self::assertFalse(PriceChange::shown('-0.999'), 'rounding, a few cents');
        self::assertFalse(PriceChange::shown(null));
    }
}
