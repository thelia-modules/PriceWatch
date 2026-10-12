<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorPage;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Matching\MatchDecision;
use PriceWatch\Service\Matching\MatchVerdict;
use PriceWatch\Service\Run\PriceWatchLog;
use PriceWatch\Service\Source\OurPrice;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads competitor prices straight from their product pages, free and in a
 * few seconds a page, where Google Shopping took minutes and showed one size
 * per card. Only pages tied to one of our products are read, and a price is
 * recorded only for a variant matched by proof (DirectMatcher): the offers it
 * writes are exact matches, in the gap at once, with no Jev call.
 */
final readonly class DirectCollector
{
    public const PASS = 'direct';
    public const SOURCE = 'direct';
    public const OFFER_KEY_PREFIX = 'page:';
    private const SHOPPING_OFFER_KEY_PREFIX = 'shopping:';

    /** @var \Closure(int): void|null */
    private ?\Closure $sleep;

    /** @var \Closure(): int|null */
    private ?\Closure $now;

    private LoggerInterface $log;

    /**
     * @param (\Closure(int): void)|null $sleep injected so tests never wait
     * @param (\Closure(): int)|null     $now   injected so tests control time
     */
    public function __construct(
        private HttpClientInterface $http,
        private PageReader $reader,
        private DirectMatcher $matcher,
        private PageRepository $pages,
        private OurPrice $ourPrice,
        private MatchDecision $decision,
        ?PriceWatchLog $log = null,
        ?\Closure $sleep = null,
        ?\Closure $now = null,
    ) {
        $this->log = $log ?? new NullLogger();
        $this->sleep = $sleep;
        $this->now = $now;
    }

    /**
     * @param list<string> $productRefs
     *
     * @return array{run: PwCollectRun, pages: list<array{id: int, url: string, status: string, outcome: string, detail: ?string, recorded: int}>}
     */
    public function collect(array $productRefs = [], ?string $competitorDomain = null): array
    {
        $run = (new PwCollectRun())->setPassName(self::PASS)->setStatus('started')->setStartedAt(new \DateTime());
        $run->save();
        $fetcher = new PageFetcher($this->http, $this->sleep, $this->now);
        $report = [];
        $counts = ['fetched' => 0, 'httpErrors' => 0, 'seen' => 0, 'matched' => 0, 'written' => 0];
        $pages = $this->pages->toRead($productRefs, $competitorDomain);
        $this->log->info('Direct pass #{run} started: {pages} page(s) to read.', ['run' => $run->getId(), 'pages' => \count($pages)]);

        try {
            foreach ($pages as $page) {
                $report[] = $this->readPage($page, $fetcher, (int) $run->getId(), $counts);
            }
        } catch (\Throwable $error) {
            $this->close($run, 'failed', $counts, [\sprintf('Stopped by an error: %s (%s)', $error->getMessage(), $error::class)]);

            throw $error;
        }

        $this->close($run, 'success', $counts, []);
        $this->log->info('Direct pass #{run} done: {fetched} page(s) fetched, {matched} variant(s) matched, {written} reading(s) recorded, {errors} HTTP error(s).', [
            'run' => $run->getId(),
            'fetched' => $counts['fetched'],
            'matched' => $counts['matched'],
            'written' => $counts['written'],
            'errors' => $counts['httpErrors'],
        ]);

        return ['run' => $run, 'pages' => $report];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array{id: int, url: string, status: string, outcome: string, detail: ?string, recorded: int}
     */
    private function readPage(PwCompetitorPage $page, PageFetcher $fetcher, int $runId, array &$counts): array
    {
        $fetched = $fetcher->fetch((string) $page->getUrl());

        if ($fetched->failure !== PageOutcome::Disallowed) {
            ++$counts['fetched'];
        }

        if ($fetched->failure !== null || $fetched->html === null) {
            $counts['httpErrors'] += $fetched->failure === PageOutcome::HttpError ? 1 : 0;

            return $this->outcome($page, ($fetched->failure ?? PageOutcome::HttpError)->value, $fetched->error, 0);
        }

        $reading = $this->reader->read($fetched->html, $fetched->url);

        if ($reading->outcome !== PageOutcome::Read || $reading->page === null) {
            return $this->outcome($page, $reading->outcome->value, $reading->detail, 0);
        }

        $counts['seen'] += \count($reading->page->variants);
        $identities = $this->identities((int) $page->getProductId());
        $ours = [];

        foreach ($identities as $id => $identity) {
            $ours[$id] = [
                // A GTIN two of our variants share proves neither.
                'gtin' => (int) $identity->getGtinValid() === 1 && (int) $identity->getGtinShared() === 0 ? $identity->getGtin() : null,
                'size' => $identity->getSizeLabel(),
            ];
        }

        $onPage = array_values(array_filter(array_map(static fn (PageVariant $variant): ?string => $variant->gtin, $reading->page->variants)));
        $proven = array_intersect(array_filter(array_column($ours, 'gtin')), $onPage) !== [];

        if ($proven && $page->getStatus() !== PageRepository::CONFIRMED) {
            $this->pages->setStatus($page, PageRepository::CONFIRMED, 'ean');
        } elseif (!$proven && $onPage !== [] && $page->getStatus() === PageRepository::CANDIDATE) {
            // The page lists GTINs, none of ours: another article or colour.
            $this->pages->setStatus($page, PageRepository::REJECTED, null);

            return $this->outcome($page, 'foreign_gtin', implode(', ', \array_slice($onPage, 0, 3)), 0);
        }

        $matches = $this->matcher->match($reading->page, $page->getStatus() === PageRepository::CONFIRMED, $ours);
        $counts['matched'] += \count($matches);
        $written = 0;

        foreach ($matches as $identityId => $match) {
            if ($this->record($identities[$identityId], $page, $reading->page, $match['variant'], $match['method'], $runId)) {
                ++$written;
            }
        }

        $counts['written'] += $written;

        return $this->outcome($page, $matches === [] ? 'read_no_match' : PageOutcome::Read->value, $matches === [] && $page->getStatus() === PageRepository::CANDIDATE ? 'awaiting confirmation' : null, $written);
    }

    /**
     * @return array<int, PwProductIdentity>
     */
    private function identities(int $productId): array
    {
        $identities = [];

        foreach (PwProductIdentityQuery::create()->filterByProductId($productId)->filterByMonitored(1)->find() as $identity) {
            $identities[(int) $identity->getId()] = $identity;
        }

        return $identities;
    }

    private function record(PwProductIdentity $identity, PwCompetitorPage $page, ProductPage $product, PageVariant $variant, string $method, int $runId): bool
    {
        $saleElementId = (int) $identity->getProductSaleElementsId();
        $competitorId = (int) $page->getCompetitorId();
        $ourPrice = $this->ourPrice->inclTax($saleElementId);
        $now = new \DateTime();
        $offer = PwCompetitorOfferQuery::create()->filterByCompetitorId($competitorId)->filterByOfferKey(self::OFFER_KEY_PREFIX.$saleElementId)->findOne()
            ?? (new PwCompetitorOffer())
                ->setCompetitorId($competitorId)
                ->setProductSaleElementsId($saleElementId)
                ->setOfferKey(self::OFFER_KEY_PREFIX.$saleElementId)
                ->setFirstSeenAt($now);

        // The article is proven; only a gap no shop would show makes the
        // price itself doubtful, and a human looks. A wide but plausible gap
        // is a price like any other.
        $verdict = $ourPrice === null ? null : (MatchDecision::implausibleGap($ourPrice, $variant->price)
            ? new MatchVerdict(MatchVerdict::NEEDS_REVIEW, 'PRICE_GAP_IMPLAUSIBLE', false, 'PRICE_GAP_IMPLAUSIBLE', false)
            : $this->decision->prefilter($ourPrice, $variant->price, $product->currency, null));
        $rejectedByHuman = $offer->getMatchState() === 'rejected' && $offer->getReviewedBy() !== null;
        $comparable = !$rejectedByHuman && $verdict === null;

        if (!$rejectedByHuman) {
            $offer
                ->setMatchState($verdict === null ? 'matched_exact' : MatchVerdict::NEEDS_REVIEW)
                ->setMatchMethod($method)
                ->setMatchReason($verdict !== null ? $verdict->reason : ($method === DirectMatcher::BY_GTIN ? 'GTIN_EQUAL' : 'SIZE_ON_CONFIRMED_PAGE'))
                ->setExclusionReason($verdict?->exclusionReason);
        }

        $delta = $ourPrice !== null ? (float) $variant->price - (float) $ourPrice : null;
        $offer
            ->setUrl((string) $page->getUrl())
            ->setRemoteTitle($product->name === null ? null : mb_substr($product->name, 0, 255))
            ->setRemoteGtin($variant->gtin)
            ->setRemoteGtinValid($variant->gtin === null ? 0 : 1)
            ->setRemoteVariantLabel(mb_substr(implode(' / ', array_filter([$variant->size, $variant->colour])), 0, 190) ?: null)
            ->setLastPrice($variant->price)
            ->setLastTotalPrice(null)
            ->setLastCurrencyCode($product->currency)
            ->setLastOurPrice($ourPrice)
            ->setLastDelta($delta === null ? null : number_format($delta, 6, '.', ''))
            ->setLastDeltaPct($delta === null || (float) $ourPrice === 0.0 ? null : number_format($delta / (float) $ourPrice * 100, 3, '.', ''))
            ->setLastComparable($comparable ? 1 : 0)
            ->setLastSeenAt($now)
            ->setActive(1)
            ->save();

        // The same pair read through Google Shopping steps aside: one offer
        // per competitor and variant on the screen, the proven one.
        PwCompetitorOfferQuery::create()
            ->filterByCompetitorId($competitorId)
            ->filterByOfferKey(self::SHOPPING_OFFER_KEY_PREFIX.$saleElementId)
            ->update(['Active' => 0]);

        $today = new \DateTime('today');

        if (PwPriceQuoteQuery::create()->filterByOfferId($offer->getId())->filterByCollectedDay($today)->filterBySource(self::SOURCE)->count() > 0) {
            return false;
        }

        (new PwPriceQuote())
            ->setOfferId($offer->getId())
            ->setCollectedDay($today)
            ->setSource(self::SOURCE)
            ->setCollectedAt($now)
            ->setPrice($variant->price)
            ->setListPrice($variant->listPrice)
            ->setShippingIncluded(0)
            ->setCurrencyCode($product->currency)
            ->setAvailability($variant->inStock === null ? null : ($variant->inStock ? 'in_stock' : 'out_of_stock'))
            ->setConditionCode('new')
            ->setOurPrice($ourPrice)
            ->setDelta($delta === null ? null : number_format($delta, 6, '.', ''))
            ->setDeltaPct($delta === null || (float) $ourPrice === 0.0 ? null : number_format($delta / (float) $ourPrice * 100, 3, '.', ''))
            ->setComparable($comparable ? 1 : 0)
            ->setExclusionReason($comparable ? null : ($verdict !== null ? $verdict->exclusionReason : 'REJECTED_BY_HUMAN'))
            ->setExtractionMethod($product->extractor)
            ->setRawPayloadJson(json_encode(['page' => $page->getUrl(), 'method' => $method, 'variant' => $variant->toArray()], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
            ->setCollectRunId($runId)
            ->save();

        return true;
    }

    /**
     * @return array{id: int, url: string, status: string, outcome: string, detail: ?string, recorded: int}
     */
    private function outcome(PwCompetitorPage $page, string $outcome, ?string $detail, int $recorded): array
    {
        $page->setLastFetchedAt(new \DateTime())
            ->setLastOutcome($outcome)
            ->setLastError($detail === null ? null : mb_substr($detail, 0, 255))
            ->save();

        if ($outcome !== PageOutcome::Read->value) {
            $this->log->info('Direct: page {id} ({url}): {outcome}{detail}.', ['id' => $page->getId(), 'url' => $page->getUrl(), 'outcome' => $outcome, 'detail' => $detail === null ? '' : ', '.$detail]);
        }

        return ['id' => (int) $page->getId(), 'url' => (string) $page->getUrl(), 'status' => (string) $page->getStatus(), 'outcome' => $outcome, 'detail' => $detail, 'recorded' => $recorded];
    }

    /**
     * @param array<string, int> $counts
     * @param list<string>       $errors
     */
    private function close(PwCollectRun $run, string $status, array $counts, array $errors): void
    {
        $run->setStatus($status)
            ->setFinishedAt(new \DateTime())
            ->setPagesFetched($counts['fetched'])
            ->setHttpErrors($counts['httpErrors'])
            ->setOffersSeen($counts['seen'])
            ->setOffersMatched($counts['matched'])
            ->setQuotesWritten($counts['written'])
            ->setErrorsJson($errors === [] ? null : json_encode($errors, \JSON_UNESCAPED_UNICODE))
            ->save();
    }
}
