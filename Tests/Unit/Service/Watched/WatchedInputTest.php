<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Watched;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedView;
use Symfony\Component\HttpFoundation\Request;

final class WatchedInputTest extends TestCase
{
    public function testOddQueryValuesMeanTheDefaults(): void
    {
        foreach (['', 'abc', '-2', '[]'] as $value) {
            $filters = WatchedFilters::fromRequest(new Request(['view' => $value, 'sort' => $value, 'page' => $value]));

            self::assertSame(WatchedView::All, $filters->view);
            self::assertSame('gap', $filters->sort);
            self::assertSame(1, $filters->page);
        }

        self::assertEquals(new WatchedFilters(), WatchedFilters::fromRequest(new Request(['view' => ['never'], 'q' => ['x'], 'sort' => ['title'], 'page' => ['2']])));
    }

    public function testFiltersSurviveTheRoundTripThroughLinks(): void
    {
        $filters = new WatchedFilters(WatchedView::Never, 'Helstons', 'collected', 3);

        self::assertEquals($filters, WatchedFilters::fromRequest(new Request($filters->toQueryParams())));
        self::assertSame([], (new WatchedFilters())->toQueryParams(), 'defaults stay out of the URL');
    }
}
