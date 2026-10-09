<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The price watch card on the product page: one line per variant of the
 * referential, with what the comparator knows about it.
 */
final readonly class ProductCardPresenter
{
    public function __construct(
        private ComparatorRepository $repository,
        private CatalogVariantRepository $catalog,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(int $productId, string $locale): array
    {
        $identities = $this->repository->identitiesOfProduct($productId);
        $offers = $this->repository->offersOfVariants(array_map(static fn (PwProductIdentity $identity): int => (int) $identity->getProductSaleElementsId(), $identities));
        $competitors = $this->repository->competitors();
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);

        $byVariant = [];

        foreach ($offers as $offer) {
            $byVariant[(int) $offer->getProductSaleElementsId()][] = $offer;
        }

        $variants = [];
        $totals = ['compared' => 0, 'review' => 0, 'weAreDearer' => 0];

        foreach ($identities as $identity) {
            $variantOffers = $byVariant[(int) $identity->getProductSaleElementsId()] ?? [];
            $compared = array_values(array_filter($variantOffers, static fn (PwCompetitorOffer $offer): bool => (int) $offer->getLastComparable() === 1
                && \in_array($offer->getMatchState(), ComparatorView::MATCHED_STATES, true)));
            $review = \count(array_filter($variantOffers, static fn (PwCompetitorOffer $offer): bool => $offer->getMatchState() === 'needs_review'));
            // Offers come sorted by gap: the first comparable one is where
            // the competition is cheapest.
            $best = $compared[0] ?? null;

            $totals['compared'] += \count($compared);
            $totals['review'] += $review;
            $totals['weAreDearer'] += \count(array_filter($compared, static fn (PwCompetitorOffer $offer): bool => PricePosition::fromGapPct($offer->getLastDeltaPct()) === PricePosition::WeAreDearer));

            $variants[] = [
                'saleElementId' => (int) $identity->getProductSaleElementsId(),
                // The collector only searches valid GTINs that no other
                // variant shares: anything else is never collected.
                'collectable' => (int) $identity->getGtinValid() === 1 && (int) $identity->getGtinShared() === 0,
                'ref' => $identity->getProductSaleElements()?->getRef(),
                'size' => $identity->getSizeLabel(),
                'color' => $identity->getColorLabel(),
                'gtin' => $identity->getGtin() === null ? null : ltrim((string) $identity->getGtin(), '0'),
                'gtinValid' => (int) $identity->getGtinValid() === 1,
                'gtinShared' => (int) $identity->getGtinShared() === 1,
                'monitored' => (int) $identity->getMonitored() === 1,
                'compared' => \count($compared),
                'review' => $review,
                'best' => $best === null ? null : [
                    'competitor' => $competitors[(int) $best->getCompetitorId()] ?? '',
                    'gap' => (((float) $best->getLastDeltaPct() > 0) ? '+' : '').$percent->format((float) $best->getLastDeltaPct() / 100),
                    'position' => PricePosition::fromGapPct($best->getLastDeltaPct())->value,
                ],
            ];
        }

        $ref = $this->repository->productRef($productId) ?? '';

        $monitored = \count(array_filter($variants, static fn (array $variant): bool => $variant['monitored']));

        return [
            'productId' => $productId,
            'inReferential' => $identities !== [],
            'watch' => [
                'monitored' => $monitored,
                'paused' => \count($variants) - $monitored,
                'missing' => max(0, $this->catalog->variantCount($productId) - \count($variants)),
                'notCollectable' => \count(array_filter($variants, static fn (array $variant): bool => $variant['monitored'] && !$variant['collectable'])),
            ],
            'variants' => $variants,
            'totals' => $totals,
            'comparatorUrl' => $this->urlGenerator->generate('pricewatch_comparator', $ref === '' ? [] : ['q' => $ref]),
            'reviewUrl' => $this->urlGenerator->generate('pricewatch_comparator', ['view' => ComparatorView::Review->value] + ($ref === '' ? [] : ['q' => $ref])),
        ];
    }
}
