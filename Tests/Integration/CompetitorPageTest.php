<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Service\Admin\CompetitorPagePresenter;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Direct\SitemapIndex;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;

/**
 * The page is drawn from what the presenter hands the template: a key the
 * template reads and the presenter no longer gives is a 500 on the page.
 */
final class CompetitorPageTest extends PriceWatchIntegrationTestCase
{
    public function testTheSuggestButtonFollowsTheDiscoverySwitch(): void
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $presenter = new CompetitorPagePresenter(
            new CompetitorRepository(),
            $settings,
            new SitemapIndex(sys_get_temp_dir().'/pw-page-test-'.bin2hex(random_bytes(4)), new DomainNormalizer()),
        );

        self::assertFalse($presenter->present()['discoveryEnabled']);

        $settings->setDiscoveryEnabled(true);

        self::assertTrue($presenter->present()['discoveryEnabled']);
    }
}
