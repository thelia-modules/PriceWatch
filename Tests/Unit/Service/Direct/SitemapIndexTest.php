<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\PageFetcher;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Seen 2026-10-20: speedway.fr announced no sitemap, martimotos.com and
 * motocard.com answered 403 to every sitemap, and the back office only showed
 * that their prices never came.
 */
final class SitemapIndexTest extends TestCase
{
    private string $directory;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pw-sitemap-index-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/var/pricewatch/sitemaps/*') ?: [] as $file) {
            unlink($file);
        }
    }

    public function testASiteWhoseRobotsTxtAnnouncesNoSitemapIsSaidSo(): void
    {
        $index = $this->index();

        self::assertSame([], $index->urls('shop.example', $this->fetcher(["User-agent: *\nDisallow: /fr/", []])));
        self::assertSame(SitemapIndex::NO_SITEMAP, $index->diagnosis('shop.example')['problem'] ?? null);
    }

    public function testASiteRefusingEverySitemapIsSaidSoWithTheAnswer(): void
    {
        $index = $this->index();
        $fetcher = $this->fetcher(["Sitemap: https://www.shop.example/sitemap-fr.xml\nSitemap: https://www.shop.example/sitemap-products-fr.xml", [
            '/sitemap-fr.xml' => new MockResponse('<title>Just a moment...</title>', ['http_code' => 403]),
            '/sitemap-products-fr.xml' => new MockResponse('<title>Just a moment...</title>', ['http_code' => 403]),
        ]]);

        self::assertSame([], $index->urls('shop.example', $fetcher));
        $diagnosis = $index->diagnosis('shop.example');
        self::assertSame(SitemapIndex::UNREADABLE, $diagnosis['problem'] ?? null);
        self::assertSame('HTTP 403 × 2', $diagnosis['detail'] ?? null);
    }

    public function testARefusedSitemapBesideAReadOneIsNoProblem(): void
    {
        $index = $this->index();
        $fetcher = $this->fetcher(["Sitemap: https://www.shop.example/sitemap-products.xml\nSitemap: https://www.shop.example/sitemap-cms.xml", [
            '/sitemap-products.xml' => new MockResponse('<urlset><url><loc>https://www.shop.example/casque.html</loc></url></urlset>'),
            '/sitemap-cms.xml' => new MockResponse('', ['http_code' => 403]),
        ]]);

        self::assertSame(['https://www.shop.example/casque.html'], $index->urls('shop.example', $fetcher));
        $diagnosis = $index->diagnosis('shop.example');
        self::assertNotNull($diagnosis);
        self::assertNull($diagnosis['problem']);
        self::assertSame(1, $diagnosis['urls']);
    }

    public function testAnEmptyListSavedWithoutItsReasonIsDownloadedAgain(): void
    {
        $index = $this->index();
        mkdir($this->directory.'/var/pricewatch/sitemaps', 0775, true);
        file_put_contents($this->directory.'/var/pricewatch/sitemaps/shop.example.txt', '');

        $index->urls('shop.example', $this->fetcher(["User-agent: *\nDisallow:", []]));

        self::assertContains('https://www.shop.example/robots.txt', $this->requested);
        self::assertSame(SitemapIndex::NO_SITEMAP, $index->diagnosis('shop.example')['problem'] ?? null);
    }

    public function testNoDiagnosisBeforeTheFirstDownload(): void
    {
        self::assertNull($this->index()->diagnosis('shop.example'));
    }

    private function index(): SitemapIndex
    {
        return new SitemapIndex($this->directory, new DomainNormalizer());
    }

    /**
     * @param array{0: string, 1: array<string, MockResponse>} $site robots.txt, then the other answers by path
     */
    private function fetcher(array $site): PageFetcher
    {
        [$robots, $answers] = $site;
        $http = new MockHttpClient(function (string $method, string $url) use ($robots, $answers): MockResponse {
            $this->requested[] = $url;
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return $path === '/robots.txt' ? new MockResponse($robots) : ($answers[$path] ?? new MockResponse('', ['http_code' => 404]));
        });

        return new PageFetcher($http, static function (int $seconds): void {});
    }
}
