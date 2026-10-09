<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Watched;

use PHPUnit\Framework\TestCase;
use PriceWatch\Controller\Admin\WatchedController;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

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

    public function testAnActionFromTheListGoesBackToTheListAndNowhereElse(): void
    {
        $routes = new RouteCollection();
        $routes->add('pricewatch_watched', new Route('/admin/module/pricewatch/watched'));
        $urls = new UrlGenerator($routes, new RequestContext());

        $fromList = new Request([], ['back' => 'watched', 'return' => 'view=never&q=Bell&page=2&next=https://evil.example']);
        self::assertSame('/admin/module/pricewatch/watched?view=never&q=Bell&page=2', WatchedController::returnUrl($fromList, $urls));

        self::assertNull(WatchedController::returnUrl(new Request([], ['back' => 'https://evil.example']), $urls), 'any other value: back to the product page');
        self::assertNull(WatchedController::returnUrl(new Request(), $urls));
    }
}
