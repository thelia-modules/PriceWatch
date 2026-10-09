<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Service\Jev\OfferQuestions;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shapes the comparator screen for the template, so the template only prints.
 */
final readonly class ComparatorPresenter
{
    public function __construct(
        private ComparatorRepository $repository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ComparatorFilters $filters, string $locale): array
    {
        $offers = $this->repository->page($filters);
        $counts = $this->repository->counts($filters);
        $total = $counts[$filters->view->value];
        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $percent = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $percent->setAttribute(\NumberFormatter::FRACTION_DIGITS, 1);

        $quotes = $this->repository->latestQuotes(array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getId(), $offers));
        $identities = $this->repository->identities(array_values(array_unique(array_map(static fn (PwCompetitorOffer $offer): int => (int) $offer->getProductSaleElementsId(), $offers))));
        $products = $this->repository->products(array_values(array_unique(array_map(static fn (array $identity): int => $identity['productId'], $identities))), $locale);
        $competitors = $this->repository->competitors();

        $rows = [];

        foreach ($offers as $offer) {
            $identity = $identities[(int) $offer->getProductSaleElementsId()] ?? ['productId' => 0, 'size' => null, 'pseRef' => null];
            $rows[] = $this->row($offer, $quotes[(int) $offer->getId()] ?? null, $identity, $products[$identity['productId']] ?? null, $competitors[(int) $offer->getCompetitorId()] ?? '', $money, $percent);
        }

        $summary = $this->repository->gapSummary($filters);

        return [
            'filters' => $filters,
            'view' => $filters->view->value,
            'rows' => $rows,
            'counts' => $counts,
            'competitors' => $competitors,
            'summary' => [
                'compared' => $counts[ComparatorView::Gaps->value],
                'cheaper' => $summary['cheaper'],
                'dearer' => $summary['dearer'],
                'averagePct' => $summary['averagePct'] === null ? null : self::percent($summary['averagePct'], $percent),
            ],
            'tabs' => array_map(fn (ComparatorView $view): array => [
                'view' => $view->value,
                'count' => $counts[$view->value],
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
     * Gaps are stored in percent (-12.5 for -12.5 %); the sign is always
     * shown, since it says who is cheaper.
     */
    private static function percent(float $value, \NumberFormatter $percent): string
    {
        return ($value > 0 ? '+' : '').$percent->format($value / 100);
    }

    /**
     * @param array{productId: int, size: ?string, pseRef: ?string} $identity
     * @param array{title: string, ref: string}|null                 $product
     *
     * @return array<string, mixed>
     */
    private function row(PwCompetitorOffer $offer, ?PwPriceQuote $quote, array $identity, ?array $product, string $competitor, \NumberFormatter $money, \NumberFormatter $percent): array
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
            'state' => $state,
            'reason' => $offer->getMatchReason(),
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
            'url' => self::safeUrl($offer->getUrl()),
            'theirs' => $theirs === null ? null : $money->formatCurrency((float) $theirs, (string) ($offer->getLastCurrencyCode() ?? 'EUR')),
            'ours' => $ours === null ? null : $money->formatCurrency((float) $ours, 'EUR'),
            'shipping' => $quote?->getShippingPrice() === null ? null : $money->formatCurrency((float) $quote->getShippingPrice(), (string) ($quote->getCurrencyCode() ?? 'EUR')),
            'delta' => $delta === null ? null : ((float) $delta > 0 ? '+' : '').$money->formatCurrency((float) $delta, 'EUR'),
            'deltaPct' => $deltaPct === null ? null : self::percent((float) $deltaPct, $percent),
            // Negative: the competitor is cheaper than us.
            'trend' => $deltaPct === null ? 'none' : ((float) $deltaPct < 0 ? 'cheaper' : ((float) $deltaPct > 0 ? 'dearer' : 'equal')),
            'availability' => $quote?->getAvailability(),
            'seenAt' => $offer->getLastSeenAt()?->format('d/m/Y H:i'),
            'searchedBy' => \is_array($payload) && \is_string($payload['searched_by'] ?? null) ? $payload['searched_by'] : null,
            'extraction' => $quote?->getExtractionMethod(),
            'jev' => $this->judgment(\is_array($judgment) ? $judgment : []),
            'actions' => [
                'confirm' => \in_array($state, ['needs_review', 'rejected', 'candidate'], true) || ($state !== OfferReview::HUMAN_MATCH && \in_array($state, ComparatorView::MATCHED_STATES, true) && (int) $offer->getLastComparable() === 0),
                'reject' => $state !== 'rejected',
                'reopen' => $state !== 'needs_review',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $judgment
     *
     * @return list<array{question: string, band: string, probability: ?string, choice: ?string}>
     */
    private function judgment(array $judgment): array
    {
        $lines = [];

        foreach ([OfferQuestions::SAME_PRODUCT, OfferQuestions::SAME_VARIANT, OfferQuestions::PRICE_COMPARABILITY] as $question) {
            $answer = $judgment[$question] ?? null;

            if (!\is_array($answer)) {
                continue;
            }

            $value = $answer['value'] ?? '';
            $confidence = \is_numeric($answer['confidence'] ?? null) ? number_format((float) $answer['confidence'] * 100, 0).' %' : null;
            $lines[] = [
                'question' => $question,
                'band' => (string) ($answer['band'] ?? ''),
                // A probability for yes/no questions; the chosen option and
                // its confidence for the price question.
                'probability' => \is_numeric($value) ? number_format((float) $value * 100, 0).' %' : $confidence,
                'choice' => \is_numeric($value) ? null : (string) $value,
            ];
        }

        return $lines;
    }

    private function url(ComparatorFilters $filters): string
    {
        return $this->urlGenerator->generate('pricewatch_comparator', $filters->toQueryParams());
    }
}
