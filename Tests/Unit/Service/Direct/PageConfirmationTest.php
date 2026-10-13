<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\PageConfirmation;
use PriceWatch\Service\Jev\JevAnswer;

final class PageConfirmationTest extends TestCase
{
    public function testOnlyTwoDecisiveYesConfirmAPage(): void
    {
        self::assertSame([PageConfirmation::CONFIRMED, 'JEV'], PageConfirmation::decide(self::yes(), self::yes()));
        self::assertSame([PageConfirmation::UNDECIDED, 'COLOURWAY_UNCLEAR'], PageConfirmation::decide(self::yes(), self::unsure(0.6)));
        self::assertSame([PageConfirmation::UNDECIDED, 'PRODUCT_UNCLEAR'], PageConfirmation::decide(self::unsure(0.6), self::yes()));
        self::assertSame([PageConfirmation::UNDECIDED, 'NOT_JUDGED'], PageConfirmation::decide(null, self::yes()));
    }

    public function testANoOrAnUnlikelyArticleRejectsThePage(): void
    {
        self::assertSame([PageConfirmation::REJECTED, 'PRODUCT_MISMATCH'], PageConfirmation::decide(self::no(), self::yes()));
        self::assertSame([PageConfirmation::REJECTED, 'PRODUCT_MISMATCH'], PageConfirmation::decide(self::unsure(0.3), self::yes()), 'below 0.35, a no in all but name');
        self::assertSame([PageConfirmation::REJECTED, 'COLOURWAY_MISMATCH'], PageConfirmation::decide(self::yes(), self::no()));
    }

    private static function yes(): JevAnswer
    {
        return new JevAnswer('noul', 0.95, JevAnswer::YES, null, []);
    }

    private static function no(): JevAnswer
    {
        return new JevAnswer('noul', 0.05, JevAnswer::NO, null, []);
    }

    private static function unsure(float $value): JevAnswer
    {
        return new JevAnswer('noul', $value, JevAnswer::REVIEW, null, []);
    }
}
