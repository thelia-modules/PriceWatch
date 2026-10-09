<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Comparator;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Comparator\ComparatorFilters;
use PriceWatch\Service\Comparator\ComparatorPresenter;
use PriceWatch\Service\Comparator\ComparatorView;
use Symfony\Component\HttpFoundation\Request;

/**
 * What reaches the comparator screen from outside: query strings, and the
 * competitor URLs that end up in an href.
 */
final class ComparatorInputTest extends TestCase
{
    public function testOnlyWebLinksReachAnHref(): void
    {
        self::assertSame('https://www.motoblouz.com/casque-123.html', ComparatorPresenter::safeUrl(' https://www.motoblouz.com/casque-123.html '));
        self::assertNull(ComparatorPresenter::safeUrl('file:///goto%3Furl=CAEScwHrOzAVeFKs'), 'the Google Shopping redirect seen live');
        self::assertNull(ComparatorPresenter::safeUrl('javascript:alert(1)'));
        self::assertNull(ComparatorPresenter::safeUrl('https://shop.example/"onmouseover="alert(1)'));
        self::assertNull(ComparatorPresenter::safeUrl(null));
    }

    public function testLinksFromTheMostToTheLeastDirect(): void
    {
        $links = ComparatorPresenter::links(
            'https://www.motoblouz.com/vente-blouson-moto-helstons-indy-cuir-rag-241159.html?size=S',
            'https://google.fr/search?ibp=oshop&q=cid%3A5363984672087245351',
            'motoblouz.com',
            'Blouson Helstons Indy cuir noir',
        );

        self::assertSame('https://www.motoblouz.com/vente-blouson-moto-helstons-indy-cuir-rag-241159.html?size=S', $links['product']);
        self::assertSame('https://google.fr/search?ibp=oshop&q=cid%3A5363984672087245351', $links['google']);
        self::assertSame('https://www.google.com/search?q=site%3Amotoblouz.com+Blouson+Helstons+Indy+cuir+noir', $links['search']);
        self::assertSame('https://motoblouz.com', $links['site']);
    }

    public function testTheGoogleShoppingPageIsRebuiltFromTheCardId(): void
    {
        self::assertSame(
            'https://www.google.fr/search?ibp=oshop&q=cid%3A5363984672087245351&prds=catalogid%3A5363984672087245351%2Cpvt%3Ahg%2Cpvo%3A3&hl=fr&gl=FR',
            ComparatorPresenter::googleShoppingUrl('5363984672087245351'),
        );
        self::assertNull(ComparatorPresenter::googleShoppingUrl('5363&q=evil'));
        self::assertNull(ComparatorPresenter::googleShoppingUrl(null));
    }

    public function testNoLinkIsBuiltFromSomethingThatIsNotADomain(): void
    {
        $links = ComparatorPresenter::links('file:///goto%3Furl=CAES', 'javascript:alert(1)', 'evil.example/"><script>', 'x');

        self::assertSame(['product' => null, 'google' => null, 'search' => null, 'site' => null], $links);
    }

    public function testUnknownOrHostileQueryValuesFallBackToTheDefaults(): void
    {
        $filters = ComparatorFilters::fromRequest(new Request([
            'view' => 'everything',
            'competitor' => '-3',
            'page' => '-1',
            'q' => str_repeat('x', 500),
        ]));

        self::assertSame(ComparatorView::Gaps, $filters->view);
        self::assertNull($filters->competitorId);
        self::assertSame(1, $filters->page);
        self::assertSame(80, mb_strlen($filters->search));
    }

    /**
     * What the filter form itself sends when "all competitors" is chosen
     * (competitor=), and what anyone can type in the address bar. Reported
     * on 2026-10-09: an empty competitor threw instead of meaning "all".
     */
    public function testEmptyOrNonNumericQueryValuesMeanTheDefault(): void
    {
        foreach (['', 'abc', '3.5', '1e3', '[]'] as $value) {
            $filters = ComparatorFilters::fromRequest(new Request(['competitor' => $value, 'page' => $value, 'cheaper' => $value]));

            self::assertNull($filters->competitorId, 'competitor='.$value);
            self::assertSame(1, $filters->page, 'page='.$value);
            self::assertFalse($filters->cheaperOnly, 'cheaper='.$value);
        }

        $arrays = ComparatorFilters::fromRequest(new Request(['competitor' => ['1'], 'page' => ['2'], 'q' => ['x'], 'view' => ['review']]));
        self::assertEquals(new ComparatorFilters(), $arrays, 'array values from competitor[]=1 and the like');

        self::assertSame(7, ComparatorFilters::fromRequest(new Request(['competitor' => '7']))->competitorId);
        self::assertTrue(ComparatorFilters::fromRequest(new Request(['cheaper' => '1']))->cheaperOnly, 'the checkbox sends 1');
    }

    public function testFiltersSurviveTheRoundTripThroughLinks(): void
    {
        $filters = new ComparatorFilters(ComparatorView::Review, 7, 'Helstons', true, 3);

        $again = ComparatorFilters::fromRequest(new Request($filters->toQueryParams()));

        self::assertEquals($filters, $again);
        self::assertSame(['view' => 'gaps'], (new ComparatorFilters())->toQueryParams(), 'defaults stay out of the URL');
        self::assertSame(1, $filters->withView(ComparatorView::Gaps)->page, 'changing tab starts at the first page');
    }
}
