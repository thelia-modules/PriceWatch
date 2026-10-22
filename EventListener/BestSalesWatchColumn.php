<?php

declare(strict_types=1);

namespace PriceWatch\EventListener;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Admin\WatchCell;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use Statistic\Event\BestSalesTableEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;

/**
 * The price watch column of the best sales table, in the general statistics
 * of the admin home and of the statistics tool. The Statistic module writes
 * the title and the cells as HTML. A product deleted since the order has no
 * product id: empty cell.
 */
final readonly class BestSalesWatchColumn implements EventSubscriberInterface
{
    public const COLUMN = 'pricewatch';

    // StatisticEvents::BEST_SALES_TABLE, as a string: without the Statistic
    // module the event is never dispatched and nothing of it is loaded.
    private const BEST_SALES_TABLE = 'statistic.best_sales.table';

    public function __construct(
        private SecurityContext $securityContext,
        private CatalogVariantRepository $catalog,
        private WatchCell $cell,
        private TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [self::BEST_SALES_TABLE => 'onBestSalesTable'];
    }

    public function onBestSalesTable(BestSalesTableEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], [AdminRequestGuard::MODULE_CODE], [AccessManager::VIEW])) {
            return;
        }

        $rows = $event->getRows();
        $states = $this->catalog->watchStates(array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_id'] ?? 0),
            $rows,
        )))));

        $event->addColumn(self::COLUMN, htmlspecialchars($this->translator->trans('Price watch', [], AdminRequestGuard::MODULE_CODE), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));

        foreach ($rows as $index => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $rows[$index][self::COLUMN] = isset($states[$productId]) ? $this->cell->render($productId, $states[$productId]) : '';
        }

        $event->setRows($rows);
    }
}
