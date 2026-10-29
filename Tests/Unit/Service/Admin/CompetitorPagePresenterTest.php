<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Admin;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Admin\CompetitorPagePresenter;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Normalizer\DomainNormalizer;

final class CompetitorPagePresenterTest extends TestCase
{
    private CompetitorPagePresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new CompetitorPagePresenter(
            new CompetitorRepository(),
            new SitemapIndex(sys_get_temp_dir().'/pw-presenter-test-'.bin2hex(random_bytes(4)), new DomainNormalizer()),
        );
    }

    public function testOnlyHttpSourcesReachTheHref(): void
    {
        // These URLs come from a model answer and are printed into an href:
        // Twig escaping does not stop a javascript: URL.
        $sources = $this->presenter->safeSources([
            ['url' => 'javascript:alert(document.cookie)', 'title' => 'xss'],
            ['url' => 'JaVaScRiPt:alert(1)', 'title' => 'xss'],
            ['url' => 'data:text/html,<script>alert(1)</script>', 'title' => 'xss'],
            ['url' => '//evil.example/protocol-relative', 'title' => 'relative'],
            ['url' => 'https://ok.example/page" onmouseover="x', 'title' => 'attribute break'],
            // No space: only the quote rule stops this one. Twig escaping
            // would too; this is the second lock, and it must hold alone.
            ['url' => 'https://ok.example/a"onmouseover="alert(1)', 'title' => 'quote only'],
            ['url' => "https://ok.example/a'onfocus='x", 'title' => 'single quote only'],
            ['url' => 'https://ok.example/page', 'title' => 'Fine'],
            ['url' => 'http://also-ok.example', 'title' => ''],
        ]);

        self::assertSame([
            ['url' => 'https://ok.example/page', 'title' => 'Fine'],
            ['url' => 'http://also-ok.example', 'title' => ''],
        ], $sources);
    }

    public function testMalformedEvidenceYieldsNoSource(): void
    {
        self::assertSame([], $this->presenter->safeSources(null));
        self::assertSame([], $this->presenter->safeSources('https://ok.example'));
        self::assertSame([], $this->presenter->safeSources([['title' => 'no url'], 'string', ['url' => 42]]));
    }

    public function testAtMostFiveSourcesWithShortTitles(): void
    {
        $many = array_map(static fn (int $i): array => ['url' => 'https://s'.$i.'.example', 'title' => str_repeat('t', 300)], range(1, 9));
        $sources = $this->presenter->safeSources($many);

        self::assertCount(5, $sources);
        self::assertSame(120, mb_strlen($sources[0]['title']));
    }
}
