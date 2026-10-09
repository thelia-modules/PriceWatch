<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use PriceWatch\Command\CollectOffersCommand;
use PriceWatch\Service\Run\ManualRun;

final class CollectBudgetTest extends TestCase
{
    /**
     * Every batch the back office can start must pass the command's own
     * budget guard with the budget it announces.
     */
    public function testEveryManualPassFitsTheBudgetItAnnounces(): void
    {
        foreach (range(1, 600) as $variants) {
            $worstCase = ManualRun::worstCaseUsd($variants);
            $announced = number_format($worstCase, 3, '.', '');

            self::assertFalse(CollectOffersCommand::exceedsBudget($worstCase, $announced), $variants.' variant(s): '.$worstCase.' against '.$announced);
        }
    }

    public function testAWorstCaseOverTheBudgetIsRefused(): void
    {
        self::assertTrue(CollectOffersCommand::exceedsBudget(0.024, '0.018'));
        self::assertTrue(CollectOffersCommand::exceedsBudget(0.0181, '0.018'));
        self::assertFalse(CollectOffersCommand::exceedsBudget(0.0, '0'));
    }
}
