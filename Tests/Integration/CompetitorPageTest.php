<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Service\Admin\CompetitorPagePresenter;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Normalizer\DomainNormalizer;

/**
 * The page is drawn from what the presenter hands the template: a key the
 * template reads and the presenter no longer gives is a 500 on the page.
 */
final class CompetitorPageTest extends PriceWatchIntegrationTestCase
{
    public function testAProposalSeenOnGoogleShoppingReachesThePage(): void
    {
        $domain = 'seen-'.bin2hex(random_bytes(3)).'.example';
        (new CompetitorRepository())->create($domain, CompetitorStatus::Proposed, 'shopping', 'Seen', ['reason' => 'Seller shown by Google Shopping', 'offers' => 3]);
        $presenter = new CompetitorPagePresenter(new CompetitorRepository(), new SitemapIndex(sys_get_temp_dir().'/pw-page-test-'.bin2hex(random_bytes(4)), new DomainNormalizer()));

        $page = $presenter->present();

        self::assertArrayNotHasKey('discoveryEnabled', $page, 'the page has no model switch any more');
        self::assertContains($domain, array_column($page['proposed'], 'domain'));
    }
}
