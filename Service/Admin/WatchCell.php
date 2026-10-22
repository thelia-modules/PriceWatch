<?php

declare(strict_types=1);

namespace PriceWatch\Service\Admin;

use Twig\Environment;

/**
 * The price watch cell of the best sales table: drawn with the table, and
 * again by the watch actions when the click came from that table.
 */
final readonly class WatchCell
{
    public function __construct(private Environment $twig)
    {
    }

    public function render(int $productId, string $state): string
    {
        return $this->twig->render('@PriceWatchModule/backOffice/default-twig/hook/watch-cell.html.twig', [
            'productId' => $productId,
            'state' => $state,
        ]);
    }
}
