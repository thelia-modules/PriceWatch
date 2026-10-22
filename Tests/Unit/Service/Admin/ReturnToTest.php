<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Admin\ReturnTo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class ReturnToTest extends TestCase
{
    private UrlGenerator $urls;

    protected function setUp(): void
    {
        $routes = new RouteCollection();
        $routes->add('pricewatch_watched', new Route('/admin/module/pricewatch/watched'));
        $routes->add('admin.categories.default', new Route('/admin/categories'));
        $routes->add('pricewatch_comparator', new Route('/admin/module/pricewatch/comparator'));
        $this->urls = new UrlGenerator($routes, new RequestContext());
    }

    public function testBackToTheWatchedListWithItsFilters(): void
    {
        $request = new Request([], ['back' => 'watched', 'return' => 'view=never&q=Bell&page=2&next=https://evil.example']);

        self::assertSame('/admin/module/pricewatch/watched?view=never&q=Bell&page=2', ReturnTo::url($request, $this->urls));
    }

    public function testBackToTheComparatorWithItsFilters(): void
    {
        $request = new Request([], ['back' => 'comparator', 'return' => 'view=gaps&q=Bell&cheaper=1&page=2&next=https://evil.example']);

        self::assertSame('/admin/module/pricewatch/comparator?view=gaps&q=Bell&cheaper=1&page=2', ReturnTo::url($request, $this->urls));
    }

    public function testBackToTheCatalogueCategoryAndPage(): void
    {
        $request = new Request([], ['back' => 'category', 'return' => 'category_id=12&page=3&redirect=https://evil.example']);

        self::assertSame('/admin/categories?category_id=12&page=3', ReturnTo::url($request, $this->urls));
        self::assertSame('/admin/categories', ReturnTo::url(new Request([], ['back' => 'category', 'return' => 'category_id=abc&page=-1']), $this->urls), 'odd values are dropped, not passed on');
    }

    public function testAnythingElseMeansNoListToGoBackTo(): void
    {
        self::assertNull(ReturnTo::url(new Request([], ['back' => 'https://evil.example']), $this->urls));
        self::assertNull(ReturnTo::url(new Request([], ['back' => ['watched']]), $this->urls));
        self::assertNull(ReturnTo::url(new Request(), $this->urls));
    }
}
