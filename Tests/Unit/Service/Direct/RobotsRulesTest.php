<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\RobotsRules;

final class RobotsRulesTest extends TestCase
{
    /** Dafy's rules for every robot, as published on 2026-10-12 (excerpt). */
    private const DAFY = "User-agent: *\nDisallow: /recherche\nDisallow: /*newness=*\nDisallow: /panier\n\nUser-agent: BadBot\nDisallow: /\n";

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'a product page' => [self::DAFY, '/casque-sz-r-vas-evo-arai-noir-mat.html?attr2=86', true];
        yield 'the search' => [self::DAFY, '/recherche?q=arai', false];
        yield 'a wildcard in the middle' => [self::DAFY, '/casques.html?newness=1', false];
        yield 'another robot\'s ban is not ours' => [self::DAFY, '/marque/arai.html', true];
        yield 'our own group wins over *' => ["User-agent: *\nDisallow:\n\nUser-agent: PriceWatch\nDisallow: /", '/a.html', false];
        yield 'the longest rule wins' => ["User-agent: *\nDisallow: /catalog\nAllow: /catalog/casques", '/catalog/casques/arai.html', true];
        yield 'end anchor' => ["User-agent: *\nDisallow: /*.php$", '/index.php?x=1', true];
        yield 'empty file' => ['', '/anything', true];
        yield 'everything banned' => ["User-agent: *\nDisallow: /", '/casque.html', false];
    }

    #[DataProvider('paths')]
    public function testOnlyWhatRobotsTxtAllowsIsFetched(string $robots, string $path, bool $allowed): void
    {
        self::assertSame($allowed, RobotsRules::parse($robots, 'PriceWatch')->allows($path));
    }

    /**
     * Motoblouz's robots.txt (2026-10-13, excerpt): Sitemap lines sit
     * outside any group and apply to every robot.
     */
    public function testTheSitemapsAnnouncedAreReadWhateverTheGroup(): void
    {
        $robots = "User-agent: *\nDisallow: /panier\nSitemap: https://www.motoblouz.com/enjoytheride/sitemap_index.xml\n\nUser-agent: BadBot\nDisallow: /\nsitemap:https://media.motoblouz.com/sitemap/fr_FR/motoblouz/sitemap-index.xml\n";

        self::assertSame(
            ['https://www.motoblouz.com/enjoytheride/sitemap_index.xml', 'https://media.motoblouz.com/sitemap/fr_FR/motoblouz/sitemap-index.xml'],
            RobotsRules::sitemaps($robots),
        );
        self::assertSame([], RobotsRules::sitemaps(''));
    }
}
