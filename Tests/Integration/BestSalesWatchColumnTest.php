<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\EventListener\BestSalesWatchColumn;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Admin\WatchCell;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use Statistic\Event\BestSalesTableEvent;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\IdentityTranslator;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Model\Admin;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The price watch column of the Statistic module's best sales table.
 */
final class BestSalesWatchColumnTest extends PriceWatchIntegrationTestCase
{
    public function testEachProductOfTheTableShowsItsWatchState(): void
    {
        $this->requireStatistic();
        $watched = $this->productWatched([1, 0]);
        $paused = $this->productWatched([0, 0]);
        $none = $this->product();
        $event = $this->table([
            ['product_ref' => 'W', 'product_id' => $watched->getId()],
            ['product_ref' => 'P', 'product_id' => $paused->getId()],
            ['product_ref' => 'N', 'product_id' => $none->getId()],
            ['product_ref' => 'GONE', 'product_id' => null],
        ]);

        $this->column(granted: true)->onBestSalesTable($event);

        self::assertArrayHasKey(BestSalesWatchColumn::COLUMN, $event->getColumns());
        $cells = array_column($event->getRows(), BestSalesWatchColumn::COLUMN, 'product_ref');
        self::assertStringContainsString('data-state="watched"', $cells['W'], 'one variant watched is enough');
        self::assertStringContainsString('data-state="paused"', $cells['P']);
        self::assertStringContainsString('data-state="none"', $cells['N']);
        self::assertStringContainsString('data-pw-watch="'.$watched->getId().'"', $cells['W']);
        self::assertSame('', $cells['GONE'], 'a product deleted since the order has no cell');
    }

    public function testTheCellHoldsNothingTheTableSearchWouldMatch(): void
    {
        $this->requireStatistic();
        $event = $this->table([['product_ref' => 'W', 'product_id' => $this->productWatched([1])->getId()]]);

        $this->column(granted: true)->onBestSalesTable($event);

        // Statistic searches the cell's HTML as text: classes, URLs or a token would match any row.
        $cell = $event->getRows()[0][BestSalesWatchColumn::COLUMN];
        self::assertDoesNotMatchRegularExpression('/class=|href=|btn|token|\/admin/', $cell);
    }

    public function testWithoutTheRightToSeeThePriceWatchTheTableIsLeftAsIs(): void
    {
        $this->requireStatistic();
        $rows = [['product_ref' => 'W', 'product_id' => (int) $this->productWatched([1])->getId()]];
        $event = $this->table($rows);

        $this->column(granted: false)->onBestSalesTable($event);

        self::assertSame([], $event->getColumns());
        self::assertSame($rows, $event->getRows());
    }

    public function testTheListenerSubscribesByEventNameSoWithoutStatisticNothingIsLoaded(): void
    {
        // A class name here would load the Statistic module's class when the
        // listener is built, in a shop that does not have it.
        self::assertSame(['statistic.best_sales.table' => 'onBestSalesTable'], BestSalesWatchColumn::getSubscribedEvents());
    }

    public function testStatesAreReadInOneQueryLikeOneByOne(): void
    {
        $ids = [(int) $this->productWatched([0, 1])->getId(), (int) $this->productWatched([0])->getId(), (int) $this->product()->getId()];
        $catalog = new CatalogVariantRepository();
        $oneByOne = [];

        foreach ($ids as $id) {
            $oneByOne[$id] = $catalog->watchState($id);
        }

        self::assertSame($oneByOne, $catalog->watchStates($ids));
        self::assertSame(['watched', 'paused', 'none'], array_values($oneByOne));
        self::assertSame([], $catalog->watchStates([]));
    }

    // ------------------------------------------------------------------

    private function requireStatistic(): void
    {
        if (!class_exists(BestSalesTableEvent::class)) {
            self::markTestSkipped('The Statistic module is not installed: its BestSalesTableEvent is absent.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function table(array $rows): BestSalesTableEvent
    {
        return new BestSalesTableEvent($rows, new \DateTime('-1 month'), new \DateTime(), 'fr_FR');
    }

    private function column(bool $granted): BestSalesWatchColumn
    {
        // The container's twig is private: the module's templates, with the one filter the cell uses.
        $translator = new IdentityTranslator();
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2).'/templates', 'PriceWatchModule');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        return new BestSalesWatchColumn($this->securityContext($granted), new CatalogVariantRepository(), new WatchCell($twig), $translator);
    }

    /**
     * isGranted() is final: the admin it checks comes from checkRole(). An
     * admin without a profile is a superadministrator.
     */
    private function securityContext(bool $granted): SecurityContext
    {
        $admin = $granted ? $this->createFixtureFactory()->admin() : null;

        return new class(new RequestStack(), $admin) extends SecurityContext {
            public function __construct(RequestStack $requestStack, private readonly ?Admin $admin)
            {
                parent::__construct($requestStack);
            }

            /**
             * @param list<string> $roles
             */
            public function checkRole(array $roles): ?UserInterface
            {
                return $this->admin;
            }
        };
    }

    /**
     * @param list<int> $monitored one variant per value, watched (1) or paused (0)
     */
    private function productWatched(array $monitored): Product
    {
        $product = $this->product();
        $saleElements = [ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne()];

        while (\count($saleElements) < \count($monitored)) {
            $saleElement = (new ProductSaleElements())
                ->setProductId($product->getId())
                ->setRef('PW-STAT-'.bin2hex(random_bytes(4)))
                ->setQuantity(1)
                ->setIsDefault(false);
            $saleElement->save();
            $saleElements[] = $saleElement;
        }

        foreach ($monitored as $index => $value) {
            (new PwProductIdentity())
                ->setProductSaleElementsId($saleElements[$index]?->getId())
                ->setProductId($product->getId())
                ->setMonitored($value)
                ->save();
        }

        return $product;
    }

    private function product(): Product
    {
        $factory = $this->createFixtureFactory();

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
    }
}
