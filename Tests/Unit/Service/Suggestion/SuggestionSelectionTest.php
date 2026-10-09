<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Suggestion;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use PriceWatch\Service\Suggestion\SuggestionSelection;

final class SuggestionSelectionTest extends TestCase
{
    public function testOnlyProductIdsAreKeptOnce(): void
    {
        self::assertSame([12, 7], SuggestionSelection::productIds(['12', '7', '12', '0', '-3', 'x', '1 OR 1=1', ['9'], 7]));
        self::assertSame([], SuggestionSelection::productIds('12'), 'not a list');
        self::assertSame([], SuggestionSelection::productIds(null));
    }

    public function testMoreThanOnePassCanTakeIsRefused(): void
    {
        $max = SuggestionPresenter::MAX_SELECTED;

        self::assertCount($max, SuggestionSelection::productIds(array_map('strval', range(1, $max))) ?? []);
        self::assertNull(SuggestionSelection::productIds(array_map('strval', range(1, $max + 1))), 'refused, never cut silently');
    }
}
