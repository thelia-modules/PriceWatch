<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Action\Image as ImageAction;
use Thelia\Core\Event\Image\ImageEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\ProductImageQuery;

/**
 * Our own product picture, for the rows whose competitor offer came without
 * one. Same mechanism as the back office's listings: the first image by
 * position, resized and cached by the core's image action.
 */
final readonly class CatalogThumbnails
{
    private const SIZE = 80;

    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, string> product id => thumbnail URL
     */
    public function urls(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $first = [];

        foreach (ProductImageQuery::create()->filterByProductId($productIds, Criteria::IN)->filterByVisible(1)->orderByPosition()->find() as $image) {
            $first[(int) $image->getProductId()] ??= $image;
        }

        $urls = [];

        foreach ($first as $productId => $image) {
            $event = (new ImageEvent())
                ->setSourceFilepath($image->getUploadDir().\DIRECTORY_SEPARATOR.$image->getFile())
                ->setCacheSubdirectory('product')
                ->setWidth(self::SIZE)
                ->setHeight(self::SIZE)
                ->setResizeMode((string) ImageAction::EXACT_RATIO_WITH_BORDERS);

            try {
                $this->events->dispatch($event, TheliaEvents::IMAGE_PROCESS);
            } catch (\Throwable) {
                // An unreadable image: no picture, never a broken page.
                continue;
            }

            // A missing file raises nothing: the URL just comes back empty.
            if ((string) $event->getFileUrl() !== '') {
                $urls[$productId] = (string) $event->getFileUrl();
            }
        }

        return $urls;
    }
}
