<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Usage\UsagePresenter;
use PriceWatch\Service\Watched\LastWatch;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedRepository;
use PriceWatch\Service\Watched\WatchedView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The price watch summary on the admin home page. Every figure comes from
 * the comparator's own queries, so the summary and the screen it links to
 * can never disagree.
 */
final readonly class DashboardPresenter
{
    private const WORST_GAPS = 5;

    /** The competitor price drops the home page tells about: read this recently. */
    public const DROPPED_DAYS = 7;

    public function __construct(
        private ComparatorRepository $repository,
        private RunStatus $runStatus,
        private UsagePresenter $usage,
        private UrlGeneratorInterface $urlGenerator,
        private WatchedRepository $watched,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(string $locale, ?\DateTimeImmutable $now = null): array
    {
        $all = new ComparatorFilters();
        $counts = $this->repository->counts($all);
        // Same counts as the watched products tabs the alert links to.
        $watched = $this->watched->counts(new WatchedFilters(), $locale, $now ?? new \DateTimeImmutable());
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);

        return [
            'hasCompetitors' => $this->repository->competitors() !== [],
            // Products waiting, as the review tab it links to counts them.
            'counts' => ['review' => $this->repository->productCounts($all)[ComparatorView::Review->value]],
            'droppedDays' => self::DROPPED_DAYS,
            'dropped' => $this->repository->droppedProductCount(($now ?? new \DateTimeImmutable())->modify('-'.self::DROPPED_DAYS.' days')),
            'split' => $this->split($counts[ComparatorView::Gaps->value], $this->repository->gapSummary($all), $percent),
            'worstGaps' => $this->worstGaps($locale, $percent),
            'competitors' => $this->byCompetitor($percent),
            'run' => $this->runStatus->present(),
            'spentThisMonth' => $this->usage->present($locale)['total']['month'],
            'watch' => [
                'stale' => $watched[WatchedView::Stale->value],
                'never' => $watched[WatchedView::Never->value],
                'staleDays' => LastWatch::STALE_DAYS,
            ],
            'links' => [
                'comparator' => $this->urlGenerator->generate('pricewatch_comparator'),
                'dearer' => $this->urlGenerator->generate('pricewatch_comparator', ['cheaper' => 1]),
                'review' => $this->urlGenerator->generate('pricewatch_comparator', ['view' => ComparatorView::Review->value]),
                'dropped' => $this->urlGenerator->generate('pricewatch_comparator', ['dropped' => 1]),
                'competitors' => $this->urlGenerator->generate('pricewatch_competitors'),
                'stale' => $this->urlGenerator->generate('pricewatch_watched', ['view' => WatchedView::Stale->value, 'sort' => 'collected']),
                'never' => $this->urlGenerator->generate('pricewatch_watched', ['view' => WatchedView::Never->value]),
            ],
        ];
    }

    /**
     * Our position over every compared offer: a diverging split, cheaper on
     * one side, dearer on the other, equal in the neutral middle.
     *
     * @param array{weAreCheaper: int, weAreDearer: int, averagePct: ?float} $summary
     *
     * @return array<string, mixed>
     */
    private function split(int $compared, array $summary, \NumberFormatter $percent): array
    {
        $equal = max(0, $compared - $summary['weAreCheaper'] - $summary['weAreDearer']);
        $share = static fn (int $count): float => $compared === 0 ? 0.0 : round($count / $compared * 100, 1);
        // The share is a CSS width (dot decimal) and a label (the reader's locale).
        $label = static fn (int $count): string => (string) $percent->format($share($count) / 100);

        return [
            'compared' => $compared,
            'segments' => array_values(array_filter([
                ['position' => PricePosition::WeAreCheaper->value, 'count' => $summary['weAreCheaper'], 'share' => $share($summary['weAreCheaper']), 'shareLabel' => $label($summary['weAreCheaper'])],
                ['position' => PricePosition::Equal->value, 'count' => $equal, 'share' => $share($equal), 'shareLabel' => $label($equal)],
                ['position' => PricePosition::WeAreDearer->value, 'count' => $summary['weAreDearer'], 'share' => $share($summary['weAreDearer']), 'shareLabel' => $label($summary['weAreDearer'])],
            ], static fn (array $segment): bool => $segment['count'] > 0)),
            'averagePct' => $summary['averagePct'] === null ? null : self::signed($summary['averagePct'], $percent),
            'averagePosition' => PricePosition::fromGapPct($summary['averagePct'])->value,
        ];
    }

    /**
     * Where we are the dearest: the comparator's own "only where we are
     * dearer" list, cheapest competitor first.
     *
     * @return list<array<string, mixed>>
     */
    private function worstGaps(string $locale, \NumberFormatter $percent): array
    {
        $offers = \array_slice($this->repository->page(new ComparatorFilters(cheaperOnly: true)), 0, self::WORST_GAPS);
        $identities = $this->repository->identities(array_values(array_unique(array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getProductSaleElementsId(), $offers))));
        $products = $this->repository->products(array_values(array_unique(array_map(static fn (array $identity): int => $identity['productId'], $identities))), $locale);
        $competitors = $this->repository->competitors();
        $rows = [];

        foreach ($offers as $offer) {
            $identity = $identities[(int) $offer->getProductSaleElementsId()] ?? ['productId' => 0, 'size' => null];
            $product = $products[$identity['productId']] ?? ['title' => '', 'ref' => ''];

            $rows[] = [
                'title' => $product['title'] !== '' ? $product['title'] : $product['ref'],
                'size' => $identity['size'],
                'competitor' => $competitors[(int) $offer->getCompetitorId()] ?? '',
                'gap' => self::signed((float) $offer->getLastDeltaPct(), $percent),
                'position' => PricePosition::fromGapPct($offer->getLastDeltaPct())->value,
                'url' => $identity['productId'] > 0
                    ? $this->urlGenerator->generate('admin.products.update', ['product_id' => $identity['productId'], 'current_tab' => 'modules'])
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byCompetitor(\NumberFormatter $percent): array
    {
        $rows = [];

        foreach ($this->repository->competitors() as $id => $domain) {
            $filters = new ComparatorFilters(competitorId: $id);
            $compared = $this->repository->counts($filters)[ComparatorView::Gaps->value];
            $summary = $this->repository->gapSummary($filters);

            $rows[] = [
                'domain' => $domain,
                'compared' => $compared,
                'split' => $this->split($compared, $summary, $percent),
                'url' => $this->urlGenerator->generate('pricewatch_comparator', ['competitor' => $id]),
            ];
        }

        // The competitors we are most often dearer than first.
        usort($rows, static fn (array $a, array $b): int => [$b['split']['segments'] === [] ? 0 : self::dearer($b['split']), $b['compared']]
            <=> [$a['split']['segments'] === [] ? 0 : self::dearer($a['split']), $a['compared']]);

        return $rows;
    }

    /**
     * @param array<string, mixed> $split
     */
    private static function dearer(array $split): int
    {
        foreach ($split['segments'] as $segment) {
            if ($segment['position'] === PricePosition::WeAreDearer->value) {
                return (int) $segment['count'];
            }
        }

        return 0;
    }

    private static function signed(float $gapPct, \NumberFormatter $percent): string
    {
        return ($gapPct > 0 ? '+' : '').$percent->format($gapPct / 100);
    }
}
