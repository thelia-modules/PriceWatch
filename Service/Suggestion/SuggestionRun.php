<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Referential\ProductEnrolment;
use PriceWatch\Service\Run\ManualRun;

/**
 * One pass on the products ticked in the suggestions tab. Those not watched
 * yet are added to the watch first, paused ones resumed; a product without a
 * variant that can be searched is left out of the pass, not failed.
 */
final readonly class SuggestionRun
{
    public function __construct(
        private ProductEnrolment $enrolment,
        private CatalogVariantRepository $catalog,
        private ManualRun $manualRun,
    ) {
    }

    /**
     * @param list<int> $productIds
     *
     * @return array{products: int, variants: int} what the pass covers; nothing started when products is 0
     *
     * @throws \RuntimeException when a pass is already running
     */
    public function start(array $productIds): array
    {
        if ($this->manualRun->isRunning()) {
            throw new \RuntimeException('A price watch pass is already running.');
        }

        $refs = [];
        $variants = 0;

        foreach ($productIds as $productId) {
            if (!$this->catalog->productExists($productId)) {
                continue;
            }

            // Adds what is missing, resumes what was paused, keeps the rest.
            $this->enrolment->enrol($productId);
            $collectable = $this->catalog->collectableVariantCount($productId);
            $ref = $this->catalog->productRef($productId);

            if ($collectable > 0 && $ref !== null) {
                $refs[] = $ref;
                $variants += $collectable;
            }
        }

        if ($refs !== []) {
            $this->manualRun->start($variants, $refs);
        }

        return ['products' => \count($refs), 'variants' => $variants];
    }
}
