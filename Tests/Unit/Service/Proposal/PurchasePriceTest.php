<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\PurchasePrice;

final class PurchasePriceTest extends TestCase
{
    public function testBothDecimalMarksAreRead(): void
    {
        self::assertSame(417.06, PurchasePrice::parse('417,06'));
        self::assertSame(85.652, PurchasePrice::parse('85.652'));
        self::assertSame(120.0, PurchasePrice::parse(' 120 '));
    }

    public function testWhatGivesNoMarginIsNull(): void
    {
        foreach ([null, '', '0', '0,00', '-5', 'n/a', '12.5.3'] as $raw) {
            self::assertNull(PurchasePrice::parse($raw), var_export($raw, true));
        }
    }
}
