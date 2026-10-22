<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Source\OurPrice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The price proposals screen and its CSV export: one line per product and
 * price, the sizes read at that price merged as the comparator merges them.
 * A size is never given a proposal it was not read for.
 */
final readonly class PriceProposalPresenter
{
    public function __construct(
        private PriceProposalRepository $repository,
        private ComparatorRepository $comparator,
        private OurPrice $ourPrice,
        private PriceWatchSettings $settings,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ProposalKind $view, string $locale, \DateTimeImmutable $now): array
    {
        $lines = $this->lines($locale, $now);
        $tabs = [];

        foreach (ProposalKind::cases() as $kind) {
            $tabs[] = [
                'view' => $kind->value,
                'count' => \count(array_filter($lines, static fn (array $line): bool => $line['kind'] === $kind->value)),
                'active' => $kind === $view,
                'url' => $this->urlGenerator->generate('pricewatch_proposals', ['view' => $kind->value]),
            ];
        }

        return [
            'view' => $view->value,
            'tabs' => $tabs,
            'lines' => $this->withSalesHistory(array_values(array_filter($lines, static fn (array $line): bool => $line['kind'] === $view->value)), $now),
            'minimumMarginPct' => $this->settings->minimumMarginPct(),
            'salesDays' => SalesTrend::DAYS,
            'salesHistoryMonths' => SalesHistogram::MONTHS,
            'declineFromPct' => SalesTrend::DECLINE_FROM_PCT,
            'csvUrl' => $this->urlGenerator->generate('pricewatch_proposals_csv'),
        ];
    }

    /**
     * Every proposal, most urgent kind first, then the widest gap or the
     * thinnest margin.
     *
     * @param list<int>|null $productIds null for every watched product
     *
     * @return list<array<string, mixed>>
     */
    public function lines(string $locale, \DateTimeImmutable $now, ?array $productIds = null): array
    {
        $variants = $this->repository->variants($productIds);

        if ($variants === []) {
            return [];
        }

        $offers = $this->repository->cheapestOffers(array_column($variants, 'saleElementId'));
        $watchedProducts = array_values(array_unique(array_column($variants, 'productId')));
        $trends = $this->repository->salesTrends($watchedProducts, $now);
        $minimumMargin = $this->settings->minimumMarginPct();
        $lines = [];

        foreach ($variants as $variant) {
            $prices = $this->ourPrice->prices($variant['saleElementId']);

            if ($prices === null) {
                continue;
            }

            $ourPrice = round($prices['inclTax'], 2);
            $purchase = PurchasePrice::parse($variant['purchase']);
            $offer = $offers[$variant['saleElementId']] ?? null;
            $trend = $trends[$variant['productId']] ?? SalesTrend::none();
            $proposal = PriceProposal::of($ourPrice, $prices['exclTax'], $purchase, $offer['price'] ?? null, $trend->declining(), $minimumMargin);

            if ($proposal === null) {
                continue;
            }

            $key = implode('|', [
                $variant['productId'],
                $proposal->kind->value,
                self::cents($ourPrice),
                $purchase === null ? '-' : self::cents($purchase),
                $offer === null ? '-' : self::cents($offer['price']).'@'.$offer['competitor'],
            ]);

            if (isset($lines[$key])) {
                $lines[$key]['sizes'] = [...$lines[$key]['sizes'], $variant['size'] ?? $variant['ref']];
                $lines[$key]['saleElementIds'][] = $variant['saleElementId'];
                continue;
            }

            $taxRatio = $ourPrice / $prices['exclTax'];
            $lines[$key] = [
                'productId' => $variant['productId'],
                'kind' => $proposal->kind->value,
                'sizes' => [$variant['size'] ?? $variant['ref']],
                'saleElementIds' => [$variant['saleElementId']],
                'ourPrice' => $ourPrice,
                'ourPriceExclTax' => round($prices['exclTax'], 2),
                'promo' => $prices['promo'],
                'purchase' => $purchase,
                'floorPrice' => $proposal->floorPrice,
                'proposedPrice' => $proposal->proposedPrice,
                'proposedExclTax' => $proposal->proposedPrice === null ? null : round($proposal->proposedPrice / $taxRatio, 2),
                'changePct' => $proposal->proposedPrice === null ? null : ($proposal->proposedPrice - $ourPrice) / $ourPrice * 100,
                'marginNowPct' => $proposal->marginNowPct,
                'marginProposedPct' => $proposal->marginProposedPct,
                'competitor' => $offer['competitor'] ?? null,
                'competitorPrice' => $offer['price'] ?? null,
                'competitorUrl' => self::webUrl($offer['url'] ?? null),
                'competitors' => $offer['competitors'] ?? 0,
                'seenAt' => $offer === null || $offer['seenAt'] === null ? null : (new \DateTimeImmutable($offer['seenAt']))->format('d/m/Y'),
                'gapPct' => $offer === null ? null : ($offer['price'] - $ourPrice) / $ourPrice * 100,
                'units' => $trend->units,
                'unitsYearBefore' => $trend->unitsYearBefore,
                'unitsPeriodBefore' => $trend->unitsPeriodBefore,
                'yearOnYearPct' => $trend->yearOnYearPct(),
                'periodOnPeriodPct' => $trend->periodOnPeriodPct(),
                'declining' => $trend->declining(),
            ];
        }

        $products = $this->comparator->products(array_values(array_unique(array_column($lines, 'productId'))), $locale);

        foreach ($lines as $key => $line) {
            $lines[$key]['title'] = $products[$line['productId']]['title'] ?? '';
            $lines[$key]['ref'] = $products[$line['productId']]['ref'] ?? '';
            $lines[$key]['productUrl'] = $this->urlGenerator->generate('admin.products.update', ['product_id' => $line['productId'], 'current_tab' => 'pse']);
        }

        $order = array_flip(array_map(static fn (ProposalKind $kind): string => $kind->value, ProposalKind::cases()));
        $lines = array_values($lines);

        usort($lines, static fn (array $a, array $b): int => [$order[$a['kind']], self::urgency($a), $a['productId']] <=> [$order[$b['kind']], self::urgency($b), $b['productId']]);

        return $lines;
    }

    /**
     * Adds to each line the units its product sold per month over the last
     * SalesHistogram::MONTHS months. Sales are counted per product, so the
     * lines of one product share the same chart.
     *
     * @param list<array<string, mixed>> $lines lines()
     *
     * @return list<array<string, mixed>> the same lines, each with its 'salesHistory' (null without any sale)
     */
    public function withSalesHistory(array $lines, \DateTimeImmutable $now): array
    {
        $sales = $this->repository->monthlySales(
            array_values(array_unique(array_map('intval', array_column($lines, 'productId')))),
            SalesHistogram::since($now),
            $now,
        );

        foreach ($lines as $index => $line) {
            $lines[$index]['salesHistory'] = SalesHistogram::of($sales[$line['productId']] ?? [], $now);
        }

        return $lines;
    }

    /**
     * Lower sorts first: the widest gap against us, or for a margin alert
     * the thinnest margin.
     *
     * @param array<string, mixed> $line
     */
    private static function urgency(array $line): float
    {
        return $line['kind'] === ProposalKind::MarginAlert->value
            ? (float) ($line['marginNowPct'] ?? 0.0)
            : (float) ($line['gapPct'] ?? 0.0);
    }

    private static function cents(float $price): int
    {
        return (int) round($price * 100);
    }

    /**
     * A collected address goes in an href: http(s) only.
     */
    private static function webUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
