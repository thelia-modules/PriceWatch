<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitor;
use PriceWatch\Model\PwCompetitorPage;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Run\BackgroundRunner;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Schedule\ScheduledProduct;
use PriceWatch\Service\Schedule\ScheduledRun;
use PriceWatch\Service\Schedule\ScheduleRepository;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Schedule\WatchTier;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Propel\Runtime\Propel;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * The real watched products are paused inside the rolled-back transaction:
 * only the test's own products are due.
 */
final class ScheduleTest extends PriceWatchIntegrationTestCase
{
    private string $tag;
    private PwCompetitor $watched;

    /** @var list<array{commands: list<list<string>>, log: string}> */
    private array $started = [];

    /** @var list<string> */
    private array $markers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'PWS'.bin2hex(random_bytes(4));
        $this->watched = (new CompetitorRepository())->create('s-'.strtolower($this->tag).'.example', CompetitorStatus::Watched, 'manual');
        Propel::getConnection('TheliaMain')->exec('UPDATE pw_product_identity SET monitored = 0 WHERE monitored = 1');
    }

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            if (is_file($marker)) {
                unlink($marker);
            }
        }

        parent::tearDown();
    }

    public function testAWatchedProductCarriesItsTierAndItsLatestReadingOfAnyKind(): void
    {
        $searched = $this->product('Casque cherché');
        $this->variant($searched, collectable: true, collectedAt: '2026-10-10 10:00:00');
        $this->page($searched, 'read', '2026-10-12 09:00:00');

        $scheduled = $this->product('Blouson pris par un passage');
        $this->variant($scheduled, collectable: true, collectedAt: '2026-10-01 10:00:00');
        $this->variant($scheduled, collectable: false);
        $this->repository()->markScheduled([$scheduled->getId()], new \DateTimeImmutable('2026-10-15 02:00:00'));
        $this->repository()->setTier($scheduled->getId(), WatchTier::Flagship);

        $failedPage = $this->product('Gants page en échec');
        $this->variant($failedPage, collectable: true);
        $this->page($failedPage, 'read_no_match', '2026-10-20 09:00:00');

        $paused = $this->product('Bottes en pause');
        $this->variant($paused, collectable: true, monitored: false);

        $offline = $this->product('Veste hors ligne');
        $offline->setVisible(0)->save();
        $this->variant($offline, collectable: true);

        $byId = [];

        foreach ($this->repository()->watched() as $product) {
            $byId[$product->productId] = $product;
        }

        self::assertSame([$searched->getId(), $scheduled->getId(), $failedPage->getId()], array_keys($byId), 'paused and offline products are never scheduled');
        self::assertSame('2026-10-12 09:00', $byId[$searched->getId()]->lastRead?->format('Y-m-d H:i'), 'a page read counts as a reading');
        self::assertSame(WatchTier::Base, $byId[$searched->getId()]->tier, 'no tier set: base');
        self::assertSame('2026-10-15 02:00', $byId[$scheduled->getId()]->lastRead?->format('Y-m-d H:i'), 'taken by a scheduled pass counts, found or not');
        self::assertSame(WatchTier::Flagship, $byId[$scheduled->getId()]->tier, 'the scheduled mark kept the tier set');
        self::assertSame(1, $byId[$scheduled->getId()]->collectable, 'only variants with a valid GTIN of their own are searched');
        self::assertNull($byId[$failedPage->getId()]->lastRead, 'a page that showed nothing of ours read nothing');
    }

    public function testATickStartsTheDueProductsInTheStandardQueueAndMarksThem(): void
    {
        $flagship = $this->product('Casque phare');
        $this->variant($flagship, collectable: true, collectedAt: (new \DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s'));
        $this->repository()->setTier($flagship->getId(), WatchTier::Flagship);

        $weekly = $this->product('Blouson lu hier');
        $this->variant($weekly, collectable: true, collectedAt: (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'));

        $never = $this->product('Gants jamais lus');
        $this->variant($never, collectable: true);
        $this->variant($never, collectable: true);

        $settings = $this->settings();
        $outcome = $this->scheduledRun($settings)->tick(new \DateTimeImmutable(), ignoreHours: true);

        self::assertSame('started', $outcome['status']);
        self::assertSame([$flagship->getId(), $never->getId()], array_map(static fn (ScheduledProduct $product): int => $product->productId, $outcome['products']), 'the flagship first; the weekly one read yesterday waits');
        self::assertSame(3, $outcome['variants']);
        self::assertCount(1, $this->started);
        $collect = $this->started[0]['commands'][1];
        self::assertNotContains('--priority', $collect);
        self::assertContains('--product='.$flagship->getRef(), $collect);
        self::assertContains('--product='.$never->getRef(), $collect);
        self::assertNotContains('--product='.$weekly->getRef(), $collect);
        self::assertSame(3, $settings->lastPass()['variants'] ?? null);

        $again = $this->scheduledRun($settings)->tick(new \DateTimeImmutable(), ignoreHours: true);

        self::assertSame('nothing_due', $again['status'], 'taken an instant ago: not paid for again at the next hour, found or not');
        self::assertCount(1, $this->started);
    }

    public function testNothingStartsWhenOffOutsideItsHoursOrForADryRun(): void
    {
        $product = $this->product('Casque à relever');
        $this->variant($product, collectable: true);

        self::assertSame('disabled', $this->scheduledRun($this->settings(enabled: false))->tick(new \DateTimeImmutable(), ignoreHours: true)['status']);

        $settings = $this->settings();
        $settings->setHours('2', '7');
        self::assertSame('outside_hours', $this->scheduledRun($settings)->tick(new \DateTimeImmutable('2026-10-22 12:00:00'))['status']);

        $dryRun = $this->scheduledRun($settings)->tick(new \DateTimeImmutable(), ignoreHours: true, dryRun: true);
        self::assertSame('dry_run', $dryRun['status']);
        self::assertSame([$product->getId()], array_map(static fn (ScheduledProduct $scheduled): int => $scheduled->productId, $dryRun['products']));
        self::assertSame([], $this->started);
        self::assertNull($this->repository()->watched()[0]->lastRead, 'a dry run marks nothing');
    }

    public function testNoSecondChainWhileAPassRowIsStillOpen(): void
    {
        $product = $this->product('Casque à relever');
        $this->variant($product, collectable: true);
        Propel::getConnection('TheliaMain')->exec("INSERT INTO pw_collect_run (pass_name, status, started_at, created_at, updated_at) VALUES ('direct', 'started', NOW() - INTERVAL 10 MINUTE, NOW(), NOW())");

        self::assertSame('running', $this->scheduledRun($this->settings())->tick(new \DateTimeImmutable(), ignoreHours: true)['status'], 'between two commands of a chain no lock is held');
        self::assertSame([], $this->started);
    }

    private function settings(bool $enabled = true): ScheduleSettings
    {
        $settings = new ScheduleSettings(new InMemoryConfigStore());
        $settings->setEnabled($enabled);

        return $settings;
    }

    private function scheduledRun(ScheduleSettings $settings): ScheduledRun
    {
        $runner = new class($this->started) implements BackgroundRunner {
            /** @param list<array{commands: list<list<string>>, log: string}> $started */
            public function __construct(private array &$started)
            {
            }

            public function start(array $commands, string $logFile): void
            {
                $this->started[] = ['commands' => $commands, 'log' => $logFile];
            }
        };
        // A marker of its own each time: the one just touched would read as a pass starting.
        $marker = sys_get_temp_dir().'/pricewatch-schedule-test-'.bin2hex(random_bytes(4)).'.started';
        $this->markers[] = $marker;

        return new ScheduledRun($settings, $this->repository(), new ManualRun($runner, new LockFactory(new InMemoryStore()), '/var/www/html', $marker), new ComparatorRepository());
    }

    private function repository(): ScheduleRepository
    {
        return new ScheduleRepository();
    }

    private function product(string $title): Product
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $product->setLocale('fr_FR')->setTitle($title.' '.$this->tag)->setVisible(1)->save();

        return $product;
    }

    private function variant(Product $product, bool $collectable, ?string $collectedAt = null, bool $monitored = true): ProductSaleElements
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();

        if ($saleElement === null || \PriceWatch\Model\PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->count() > 0) {
            $saleElement = (new ProductSaleElements())->setProductId($product->getId())->setRef('PWS-'.bin2hex(random_bytes(4)))->setQuantity(1)->setIsDefault(false);
            $saleElement->save();
        }

        (new PwProductIdentity())
            ->setProductSaleElementsId($saleElement->getId())
            ->setProductId($product->getId())
            ->setMonitored($monitored ? 1 : 0)
            ->setGtinValid($collectable ? 1 : 0)
            ->setGtinShared(0)
            ->setLastCollectedAt($collectedAt === null ? null : new \DateTime($collectedAt))
            ->save();

        return $saleElement;
    }

    private function page(Product $product, string $outcome, string $fetchedAt): void
    {
        $url = 'https://'.$this->watched->getDomain().'/'.bin2hex(random_bytes(4));

        (new PwCompetitorPage())
            ->setCompetitorId($this->watched->getId())
            ->setProductId($product->getId())
            ->setUrl($url)
            ->setUrlKey(sha1($url))
            ->setStatus('confirmed')
            ->setLastFetchedAt(new \DateTime($fetchedAt))
            ->setLastOutcome($outcome)
            ->save();
    }
}
