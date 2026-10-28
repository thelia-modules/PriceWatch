<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\DashboardPresenter;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Tests\Doubles\RecordingBackgroundRunner;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\RunProgress;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Usage\UsagePresenter;
use PriceWatch\Service\Usage\UsageRepository;
use PriceWatch\Service\Watched\WatchedRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Thelia\Model\ProductSaleElementsQuery;

final class DashboardTest extends PriceWatchIntegrationTestCase
{
    private PwCompetitor $often;
    private PwCompetitor $rarely;

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test's competitors are whitelisted while it runs.
        PwCompetitorQuery::create()
            ->filterByStatus([CompetitorStatus::Watched->value, CompetitorStatus::Paused->value])
            ->update(['Status' => CompetitorStatus::Proposed->value]);

        $tag = bin2hex(random_bytes(4));
        $competitors = new CompetitorRepository();
        // Alphabetical order is the opposite of the expected one, so the sort
        // by "we are dearer" is what puts the second first.
        $this->rarely = $competitors->create('a-rarely-'.$tag.'.example', CompetitorStatus::Watched, 'manual');
        $this->often = $competitors->create('z-often-'.$tag.'.example', CompetitorStatus::Watched, 'manual');
    }

    public function testTheSummaryAddsUpLikeTheComparator(): void
    {
        $this->offer($this->rarely, 'matched_judged', true, '5.000');
        $this->offer($this->rarely, 'matched_judged', true, '-0.040');
        $this->offer($this->often, 'matched_manual', true, '-12.500', 'Casque Bell TX501');
        $this->offer($this->often, 'matched_judged', true, '-3.000', 'Blouson Helstons Indy');
        $this->offer($this->often, 'needs_review', false, '-40.000');

        $dashboard = $this->presenter()->present('fr_FR');

        self::assertTrue($dashboard['hasCompetitors']);
        self::assertSame(4, $dashboard['split']['compared']);
        self::assertSame(1, $dashboard['counts']['review']);
        self::assertSame(
            ['cheaper' => 1, 'equal' => 1, 'dearer' => 2],
            array_column($dashboard['split']['segments'], 'count', 'position'),
            'cheaper, same price (shown as 0.0 %), dearer: every compared offer once',
        );
        self::assertSame(50.0, $dashboard['split']['segments'][2]['share']);
        self::assertSame('50,0 %', str_replace("\u{a0}", ' ', $dashboard['split']['segments'][2]['shareLabel']), 'written the reader\'s way, not as a CSS number');

        self::assertSame(['Casque Bell TX501', 'Blouson Helstons Indy'], array_column($dashboard['worstGaps'], 'title'), 'the dearest first, and only where we are dearer');
        self::assertSame('dearer', $dashboard['worstGaps'][0]['position']);
        self::assertStringContainsString('12,5', $dashboard['worstGaps'][0]['gap']);

        self::assertSame($this->often->getDomain(), $dashboard['competitors'][0]['domain'], 'the competitor we are most often dearer than comes first');
        self::assertSame(2, $dashboard['competitors'][0]['compared']);
    }

    public function testTheSummaryWarnsAboutProductsReadLongAgoOrNever(): void
    {
        // Only this test's identities are watched while it runs.
        \PriceWatch\Model\PwProductIdentityQuery::create()->update(['Monitored' => 0]);
        $this->offer($this->rarely, 'matched_judged', true, '5.000');
        \PriceWatch\Model\PwProductIdentityQuery::create()->filterByMonitored(1)->update(['LastCollectedAt' => new \DateTime('-20 days')]);
        $this->offer($this->often, 'matched_judged', true, '1.000');

        $dashboard = $this->presenter()->present('fr_FR');

        self::assertSame(['stale' => 1, 'never' => 1, 'staleDays' => 14], $dashboard['watch']);
        self::assertSame('/w?view=stale&sort=collected', $dashboard['links']['stale']);
        self::assertSame('/w?view=never', $dashboard['links']['never']);
    }

    public function testTheSummaryTellsOfRecentCompetitorPriceDrops(): void
    {
        $this->offer($this->often, 'matched_judged', true, '-12.000');
        \PriceWatch\Model\PwCompetitorOfferQuery::create()->filterByCompetitorId($this->often->getId())
            ->update(['PriceChangePct' => '-15.000', 'LastSeenAt' => new \DateTime('-2 days')]);
        $this->offer($this->rarely, 'matched_judged', true, '-3.000');
        \PriceWatch\Model\PwCompetitorOfferQuery::create()->filterByCompetitorId($this->rarely->getId())
            ->update(['PriceChangePct' => '-15.000', 'LastSeenAt' => new \DateTime('-20 days')]);

        $dashboard = $this->presenter()->present('fr_FR');

        self::assertSame(1, $dashboard['dropped'], 'read in the last 7 days only');
        self::assertSame('/c?dropped=1', $dashboard['links']['dropped']);
    }

    public function testWithoutWhitelistTheBlockSaysSo(): void
    {
        PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value)->update(['Status' => CompetitorStatus::Proposed->value]);

        $dashboard = $this->presenter()->present('fr_FR');

        self::assertFalse($dashboard['hasCompetitors']);
        self::assertSame([], $dashboard['split']['segments']);
    }

    private function offer(PwCompetitor $competitor, string $state, bool $comparable, string $gapPct, string $title = 'Produit'): void
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title)->save();
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($saleElement);

        (new PwProductIdentity())->setProductSaleElementsId($saleElement->getId())->setProductId($product->getId())->setSizeLabel('M')->save();

        (new PwCompetitorOffer())
            ->setCompetitorId($competitor->getId())
            ->setProductSaleElementsId($saleElement->getId())
            ->setOfferKey('dash-'.bin2hex(random_bytes(5)))
            ->setMatchState($state)
            ->setLastComparable($comparable ? 1 : 0)
            ->setLastDeltaPct($gapPct)
            ->setActive(1)
            ->save();
    }

    private function presenter(): DashboardPresenter
    {
        $routes = new RouteCollection();

        foreach (['pricewatch_comparator' => '/c', 'pricewatch_competitors' => '/k', 'admin.products.update' => '/p', 'pricewatch_watched' => '/w'] as $name => $path) {
            $routes->add($name, new Route($path));
        }

        $runner = new RecordingBackgroundRunner();
        $ledger = new UsageRepository();

        return new DashboardPresenter(
            new ComparatorRepository(),
            new RunStatus(new RunProgress(new ManualRun($runner, new LockFactory(new InMemoryStore()), '/tmp')), $ledger),
            new UsagePresenter($ledger),
            new UrlGenerator($routes, new RequestContext()),
            new WatchedRepository(new ComparatorRepository()),
        );
    }
}
