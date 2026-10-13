<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\PageFetcher;
use PriceWatch\Service\Direct\PageOutcome;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PageFetcherTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    /** @var list<array<string, mixed>> */
    private array $options = [];

    private int $slept = 0;

    public function testAPageRobotsTxtAllowsIsFetchedUnderOurOwnName(): void
    {
        $page = $this->fetcher(['/robots.txt' => new MockResponse("User-agent: *\nDisallow: /recherche"), '/casque.html' => new MockResponse('<html>ok</html>')])
            ->fetch('https://www.shop.example/casque.html');

        self::assertNull($page->failure);
        self::assertSame('<html>ok</html>', $page->html);
        self::assertStringContainsString('PriceWatch', implode(' ', $this->options[1]['headers'] ?? []), 'a robot says it is one');
        self::assertSame(120.0, (float) ($this->options[1]['max_duration'] ?? 0), 'a dripping answer cannot hold the pass: seen 29 minutes on one page');
    }

    public function testAPathRobotsTxtForbidsIsNeverRequested(): void
    {
        $page = $this->fetcher(['/robots.txt' => new MockResponse("User-agent: *\nDisallow: /recherche")])
            ->fetch('https://www.shop.example/recherche?q=arai');

        self::assertSame(PageOutcome::Disallowed, $page->failure);
        self::assertSame(['https://www.shop.example/robots.txt'], $this->requested);
    }

    public function testNoRobotsTxtAllowsEverything(): void
    {
        $page = $this->fetcher(['/robots.txt' => new MockResponse('', ['http_code' => 404]), '/casque.html' => new MockResponse('<html>ok</html>')])
            ->fetch('https://www.shop.example/casque.html');

        self::assertNull($page->failure);
    }

    public function testAnUnreachableRobotsTxtForbidsEverything(): void
    {
        $page = $this->fetcher(['/robots.txt' => new MockResponse('', ['http_code' => 503])])
            ->fetch('https://www.shop.example/casque.html');

        self::assertSame(PageOutcome::Disallowed, $page->failure, 'RFC 9309: a server error means complete disallow');
    }

    public function testRobotsTxtIsReadOnceAndTheSiteGetsTimeBetweenTwoPages(): void
    {
        $fetcher = $this->fetcher(['/robots.txt' => new MockResponse(''), '/a.html' => new MockResponse('a'), '/b.html' => new MockResponse('b')]);

        $fetcher->fetch('https://www.shop.example/a.html');
        $fetcher->fetch('https://www.shop.example/b.html');

        self::assertSame(['https://www.shop.example/robots.txt', 'https://www.shop.example/a.html', 'https://www.shop.example/b.html'], $this->requested);
        self::assertGreaterThanOrEqual(PageFetcher::DELAY_SECONDS, $this->slept);
    }

    public function testAnErrorStatusIsNoPage(): void
    {
        $page = $this->fetcher(['/robots.txt' => new MockResponse(''), '/casque.html' => new MockResponse('Forbidden', ['http_code' => 403])])
            ->fetch('https://www.shop.example/casque.html');

        self::assertSame(PageOutcome::HttpError, $page->failure);
        self::assertSame(403, $page->status);
        self::assertNull($page->html);
    }

    /**
     * @param array<string, MockResponse> $responses by path
     */
    private function fetcher(array $responses): PageFetcher
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($responses): MockResponse {
            $this->requested[] = $url;
            $this->options[] = $options;

            return $responses[(string) parse_url($url, \PHP_URL_PATH)] ?? new MockResponse('', ['http_code' => 404]);
        });
        $clock = 1_000;

        return new PageFetcher(
            $http,
            function (int $seconds) use (&$clock): void {
                $this->slept += $seconds;
                $clock += $seconds;
            },
            static function () use (&$clock): int {
                return $clock;
            },
        );
    }
}
