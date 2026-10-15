<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwPriceQuote;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes the comparator screen for the template, so the template only prints.
 */
final readonly class ComparatorPresenter
{
    public function __construct(
        private ComparatorRepository $repository,
        private UrlGeneratorInterface $urlGenerator,
        private CatalogThumbnails $thumbnails,
    ) {
    }

    /** A gap this wide or wider fills its half of the bar. */
    private const BAR_FULL_AT_PCT = 40.0;

    /**
     * @return array<string, mixed>
     */
    public function present(ComparatorFilters $filters, string $locale, bool $withRows = true): array
    {
        // Every tab lists products, every competitor of a product on its line,
        // the offers in the detail (asked 2026-10-13: the gaps tab's layout
        // on every tab).
        $productIds = $withRows ? $this->repository->productPage($filters) : [];
        // Another tab of the screen shows: the counts are needed, the rows not.
        $offers = $withRows ? $this->repository->offersOfProducts($filters, $productIds) : [];
        // Offers for the figures, products for the tabs, as the lists are.
        $counts = $this->repository->counts($filters);
        $productCounts = $this->repository->productCounts($filters);
        $total = $productCounts[$filters->view->value];
        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);

        $quotes = $this->repository->latestQuotes(array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getId(), $offers));
        $identities = $this->repository->identities(array_values(array_unique(array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getProductSaleElementsId(), $offers))));
        $products = $this->repository->products(array_values(array_unique(array_map(static fn (array $identity): int => $identity['productId'], $identities))), $locale);
        $competitors = $this->repository->competitors();
        $pictures = $this->pictures(array_keys($products));

        $rows = [];

        foreach ($offers as $offer) {
            $identity = $identities[(int) $offer->getProductSaleElementsId()] ?? ['productId' => 0, 'size' => null, 'pseRef' => null];
            $rows[] = $this->row($offer, $quotes[(int) $offer->getId()] ?? null, $identity, $products[$identity['productId']] ?? null, $competitors[(int) $offer->getCompetitorId()] ?? '', $money, $percent, $pictures[$identity['productId']] ?? null);
        }

        $rows = self::lines($rows);

        $direction = $filters->gapDirection();

        $summary = $this->repository->gapSummary($filters);

        return [
            'filters' => $filters,
            'view' => $filters->view->value,
            'rows' => $rows,
            'products' => self::products($rows, $productIds),
            'counts' => $productCounts,
            'competitors' => $competitors,
            'summary' => [
                'compared' => $counts[ComparatorView::Gaps->value],
                'weAreCheaper' => $summary['weAreCheaper'],
                'weAreDearer' => $summary['weAreDearer'],
                'averagePct' => $summary['averagePct'] === null ? null : self::percent($summary['averagePct'], $percent),
                'averagePosition' => PricePosition::fromGapPct($summary['averagePct'])->value,
            ],
            'tabs' => array_map(fn (ComparatorView $view): array => [
                'view' => $view->value,
                'count' => $productCounts[$view->value],
                'url' => $this->url($filters->withView($view)),
                'active' => $view === $filters->view,
            ], ComparatorView::cases()),
            'pagination' => [
                'page' => $filters->page,
                'pages' => max(1, (int) ceil($total / ComparatorFilters::PAGE_SIZE)),
                'previous' => $filters->page > 1 ? $this->url($filters->withPage($filters->page - 1)) : null,
                'next' => $filters->offset() + ComparatorFilters::PAGE_SIZE < $total ? $this->url($filters->withPage($filters->page + 1)) : null,
            ],
            'returnQuery' => http_build_query($filters->toQueryParams()),
            'sort' => [
                'direction' => $direction,
                'toggle' => $this->url($filters->withSort($direction === 'ASC' ? ComparatorFilters::SORT_GAP_DESC : ComparatorFilters::SORT_GAP)),
            ],
        ];
    }

    /**
     * Every row gets a picture when one exists anywhere: the Google Shopping
     * card of an offer on the same product first, our own catalogue's next.
     *
     * @param list<int> $productIds
     *
     * @return array<int, string> product id => picture URL
     */
    private function pictures(array $productIds): array
    {
        $pictures = $this->repository->cardImages($productIds);
        $missing = array_values(array_diff($productIds, array_keys($pictures)));

        return $pictures + $this->thumbnails->urls($missing);
    }

    /**
     * The gap drawn from the middle of a bar: to the left when the
     * competitor is cheaper, to the right when we are, nothing when equal.
     *
     * @return array{side: ?string, width: int}|null width in percent of the whole bar
     */
    public static function bar(?string $deltaPct): ?array
    {
        if ($deltaPct === null || $deltaPct === '') {
            return null;
        }

        $gap = (float) $deltaPct;

        if (abs($gap) < PricePosition::EQUAL_BELOW) {
            return ['side' => null, 'width' => 0];
        }

        return [
            'side' => $gap < 0 ? 'left' : 'right',
            // Never thinner than a visible sliver.
            'width' => max(2, (int) round(min(abs($gap), self::BAR_FULL_AT_PCT) / self::BAR_FULL_AT_PCT * 50)),
        ];
    }

    /**
     * The competitor URL ends up in an href. Google Shopping hands out its
     * own redirect ("file:///goto?url=..."), which is neither a merchant page
     * nor safe to link: only http(s) survives.
     */
    public static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }

    /**
     * From the most to the least direct: the product page itself when Google
     * gave it for this competitor, the Google Shopping page listing every
     * seller with a working link, and a search restricted to the
     * competitor's site, which always exists.
     *
     * @return array{product: ?string, google: ?string, search: ?string, site: ?string}
     */
    public static function links(?string $productUrl, mixed $googleUrl, string $competitorDomain, string $productTitle): array
    {
        $domain = preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $competitorDomain) === 1 ? $competitorDomain : null;

        return [
            'product' => self::safeUrl($productUrl),
            'google' => self::safeUrl(\is_string($googleUrl) ? $googleUrl : null),
            'search' => $domain === null ? null : 'https://www.google.com/search?'.http_build_query(['q' => 'site:'.$domain.' '.$productTitle]),
            'site' => $domain === null ? null : 'https://'.$domain,
        ];
    }

    /**
     * Readings made before the Google page was kept still carry the card's
     * product id, which is all the page address needs (same form as the
     * check_url DataForSEO returns).
     */
    public static function googleShoppingUrl(mixed $productId): ?string
    {
        if (!\is_string($productId) || preg_match('/^\d{5,25}$/', $productId) !== 1) {
            return null;
        }

        return 'https://www.google.fr/search?'.http_build_query([
            'ibp' => 'oshop',
            'q' => 'cid:'.$productId,
            'prds' => 'catalogid:'.$productId.',pvt:hg,pvo:3',
            'hl' => 'fr',
            'gl' => 'FR',
        ]);
    }

    /**
     * Gaps are stored in percent (-12.5 for -12.5 %); the sign is always
     * shown, since it says who is cheaper.
     */
    private static function percent(float $value, \NumberFormatter $percent): string
    {
        // Rounded first: -0.02 would otherwise print as "-0.0 %", a sign
        // for a gap the screen shows as equal.
        $value = round($value, 1) == 0.0 ? 0.0 : $value;

        return ($value > 0 ? '+' : '').$percent->format($value / 100);
    }

    /**
     * @param array{productId: int, size: ?string, pseRef: ?string} $identity
     * @param array{title: string, ref: string}|null                 $product
     *
     * @return array<string, mixed>
     */
    private function row(PwCompetitorOffer $offer, ?PwPriceQuote $quote, array $identity, ?array $product, string $competitor, \NumberFormatter $money, \NumberFormatter $percent, ?string $picture): array
    {
        $state = (string) $offer->getMatchState();
        $theirs = $offer->getLastPrice();
        $ours = $offer->getLastOurPrice();
        $delta = $offer->getLastDelta();
        $deltaPct = $offer->getLastDeltaPct();
        $payload = json_decode((string) $quote?->getRawPayloadJson(), true);
        $judgment = json_decode((string) $offer->getJudgmentJson(), true);

        return [
            'id' => (int) $offer->getId(),
            'productId' => $identity['productId'],
            'groupIds' => [(int) $offer->getId()],
            'sizes' => $identity['size'] === null || $identity['size'] === '' ? [] : [$identity['size']],
            // A size whose price moved keeps its line: merged, its move vanished.
            'lineKey' => $identity['productId'] > 0 && $theirs !== null && $ours !== null
                ? implode('|', [$offer->getCompetitorId(), $identity['productId'], $theirs, $ours, $deltaPct, $state, $offer->getLastComparable(), $offer->getExclusionReason(), $offer->getMatchMethod(), PriceChange::shown($offer->getPriceChangePct()) ? $offer->getPreviousPrice() : ''])
                : null,
            'state' => $state,
            'reason' => $offer->getMatchReason(),
            // In the gaps although a human has not decided: shown as such.
            'toCheck' => ComparatorView::toCheck($state, $offer->getLastComparable()),
            // How the price moved since the reading of an earlier day, from 1 %.
            'change' => PriceChange::shown($offer->getPriceChangePct()) ? [
                // Unsigned: "down" or "up" says which way.
                'pct' => $percent->format(abs((float) $offer->getPriceChangePct()) / 100),
                'direction' => (float) $offer->getPriceChangePct() < 0 ? 'down' : 'up',
                'previous' => $money->formatCurrency((float) $offer->getPreviousPrice(), 'EUR'),
                'since' => $offer->getPreviousPriceDay()?->format('d/m'),
            ] : null,
            'method' => $offer->getMatchMethod(),
            'exclusion' => $offer->getExclusionReason(),
            'reviewedBy' => $offer->getReviewedBy(),
            'reviewedAt' => $offer->getReviewedAt()?->format('d/m/Y H:i'),
            'product' => [
                'title' => $product === null ? '' : ($product['title'] !== '' ? $product['title'] : $product['ref']),
                'ref' => $product['ref'] ?? '',
                'size' => $identity['size'],
                'pseRef' => $identity['pseRef'],
                'editUrl' => $identity['productId'] > 0 ? $this->urlGenerator->generate('admin.products.update', ['product_id' => $identity['productId']]) : null,
            ],
            'competitor' => $competitor,
            'remoteTitle' => (string) $offer->getRemoteTitle(),
            'remoteVariant' => $offer->getRemoteVariantLabel(),
            'links' => self::links(
                $offer->getUrl(),
                (\is_array($payload) ? ($payload['google_url'] ?? null) : null) ?? self::googleShoppingUrl(\is_array($payload) ? ($payload['card']['productId'] ?? null) : null),
                $competitor,
                $product['title'] ?? '',
            ),
            'image' => (\is_array($payload) ? self::safeUrl(\is_string($payload['card']['image'] ?? null) ? $payload['card']['image'] : null) : null) ?? $picture,
            'theirs' => $theirs === null ? null : $money->formatCurrency((float) $theirs, (string) ($offer->getLastCurrencyCode() ?? 'EUR')),
            'ours' => $ours === null ? null : $money->formatCurrency((float) $ours, 'EUR'),
            'oursValue' => $ours === null ? null : (float) $ours,
            'shipping' => $quote?->getShippingPrice() === null ? null : $money->formatCurrency((float) $quote->getShippingPrice(), (string) ($quote->getCurrencyCode() ?? 'EUR')),
            'delta' => $delta === null ? null : ((float) $delta > 0 ? '+' : '').$money->formatCurrency((float) $delta, 'EUR'),
            'deltaPct' => $deltaPct === null ? null : self::percent((float) $deltaPct, $percent),
            'position' => PricePosition::fromGapPct($deltaPct)->value,
            'bar' => self::bar($deltaPct),
            'availability' => $quote?->getAvailability(),
            'seenAt' => $offer->getLastSeenAt()?->format('d/m/Y H:i'),
            'searchedBy' => \is_array($payload) && \is_string($payload['searched_by'] ?? null) ? $payload['searched_by'] : null,
            'extraction' => $quote?->getExtractionMethod(),
            'confidence' => MatchConfidence::of($offer->getMatchMethod(), \is_array($judgment) ? $judgment : [], $state),
            'actions' => [
                'confirm' => \in_array($state, ['needs_review', 'rejected', 'candidate'], true) || ($state !== OfferReview::HUMAN_MATCH && \in_array($state, ComparatorView::MATCHED_STATES, true) && (int) $offer->getLastComparable() === 0),
                'reject' => $state !== 'rejected',
                'reopen' => $state !== 'needs_review',
            ],
        ];
    }

    /**
     * The sizes of one product read at one competitor at one price, in one
     * state, are one line (asked 2026-10-12: five lines said the same thing).
     * Sizes at another price, or decided otherwise, keep a line of their own.
     * Nothing is inferred: a size shows only where it was read.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(array $rows): array
    {
        $lines = [];
        $lineOf = [];

        foreach ($rows as $row) {
            $key = $row['lineKey'] ?? null;

            if (\is_string($key) && isset($lineOf[$key])) {
                $line = &$lines[$lineOf[$key]];
                $line['groupIds'][] = $row['id'];
                $line['sizes'] = [...$line['sizes'], ...$row['sizes']];
                unset($line);
                continue;
            }

            if (\is_string($key)) {
                $lineOf[$key] = \count($lines);
            }

            $lines[] = $row;
        }

        return $lines;
    }

    /**
     * One line per product on the gaps tab, every competitor on it (asked
     * 2026-10-12: one helmet showed as a line per competitor). The lines
     * arrive in the order of the gap sort, so the first line of a product,
     * or of a competitor within it, is the one its gap is shown by.
     *
     * @param list<array<string, mixed>> $lines     from lines()
     * @param list<int>                  $productIds the page's products, in order
     *
     * @return list<array<string, mixed>>
     */
    public static function products(array $lines, array $productIds): array
    {
        $byProduct = array_fill_keys($productIds, []);

        foreach ($lines as $line) {
            // A variant missing from the referential has no product: its
            // offer stands alone rather than joining another's line.
            $key = $line['productId'] > 0 ? $line['productId'] : -$line['id'];
            $byProduct[$key][] = $line;
        }

        $products = [];

        foreach (array_filter($byProduct) as $key => $productLines) {
            $competitors = [];

            foreach ($productLines as $line) {
                $competitors[$line['competitor']] ??= ['name' => $line['competitor'], 'headline' => $line, 'lines' => [], 'change' => null];
                $competitors[$line['competitor']]['lines'][] = $line;
                // The chip shows a move of any of its sizes, not only the headline's.
                $competitors[$line['competitor']]['change'] ??= $line['change'] ?? null;
            }

            $priced = array_values(array_filter($productLines, static fn (array $line): bool => $line['oursValue'] !== null));
            usort($priced, static fn (array $a, array $b): int => $a['oursValue'] <=> $b['oursValue']);
            $cheapest = $priced[0]['ours'] ?? null;
            $dearest = $priced === [] ? null : $priced[\count($priced) - 1]['ours'];
            $pictured = array_values(array_filter(array_column($productLines, 'image')));

            $products[] = [
                'id' => $key,
                'product' => $productLines[0]['product'],
                'image' => $pictured[0] ?? null,
                'headline' => $productLines[0],
                'ours' => ['from' => $cheapest, 'to' => $dearest === $cheapest ? null : $dearest],
                'competitors' => array_values($competitors),
                'lines' => $productLines,
            ];
        }

        return $products;
    }

    private function url(ComparatorFilters $filters): string
    {
        return $this->urlGenerator->generate('pricewatch_comparator', $filters->toQueryParams());
    }
}
