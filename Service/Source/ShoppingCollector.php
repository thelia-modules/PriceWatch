<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Model\PwCollectRun;
use PriceWatch\Model\PwCompetitorOffer;
use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Model\PwPriceQuote;
use PriceWatch\Model\PwPriceQuoteQuery;
use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\IdentityFingerprint;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductI18nQuery;

/**
 * Collects competitor offers from Google Shopping.
 *
 * Google Shopping returns no GTIN anywhere (DataForSEO docs read 2026-10-09),
 * so strict GTIN equality is impossible on this source. Instead: search
 * Shopping with our GTIN, take the product card Google attaches to it, read
 * that card's sellers, keep the whitelisted ones. The offer is stored as a
 * candidate; pricewatch:judge decides whether it is really our variant.
 *
 * Merchants outside the whitelist are counted, never stored: the blacklist is
 * never compared, and an undecided competitor's prices are nobody's business
 * yet. Their names show in the report, as hints for the competitor list.
 */
final readonly class ShoppingCollector
{
    public const PASS = 'collect';
    public const SOURCE = 'dataforseo';
    private const POLL_SECONDS = 10;
    private const LOCALE = 'fr_FR';
    private const HUMAN_MATCH = 'matched_manual';
    private const MATCHED_STATES = ['matched_exact', 'matched_judged', 'matched_manual'];

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep injected so tests never wait
     */
    public function __construct(
        private DataForSeoClient $client,
        private DomainNormalizer $domains,
        private TextNormalizer $text,
        private IdentityFingerprint $fingerprint,
        private OurPrice $ourPrice,
        private CardRelevance $relevance,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param list<string> $productRefs   restrict to these products
     * @param bool         $onePerProduct one variant per product, to sample a catalogue
     *
     * @return array{run: PwCollectRun, unknownSellers: array<string, int>, noPlausibleCard: array<string, list<string>>} our title => first card titles
     *
     * @throws DataForSeoException
     */
    public function collect(int $limit, int $priority, int $maxWaitSeconds, array $productRefs = [], bool $sandbox = false, bool $onePerProduct = false): array
    {
        $run = (new PwCollectRun())->setPassName(self::PASS)->setStatus('started')->setStartedAt(new \DateTime());
        $run->save();

        $watched = $this->watchedCompetitors();
        $sellers = new SellerMatcher($watched, $this->domains, $this->text);
        $identities = $this->identities($limit, $productRefs, $onePerProduct);
        $errors = [];
        $unknownSellers = [];
        $counts = ['tasks' => 0, 'seen' => 0, 'written' => 0, 'targets' => \count($identities)];
        $noCard = [];
        $cost = 0.0;

        if ($watched === []) {
            $errors[] = 'No whitelisted competitor: nothing would be compared. Add competitors to the whitelist first.';
        }

        if ($watched !== [] && $identities !== []) {
            // 1. Find the product card Google attaches to each GTIN.
            $titles = $this->productTitles($identities);
            $gtins = [];
            $ourTitles = [];

            foreach ($identities as $id => $identity) {
                $gtins[(string) $id] = self::searchableGtin((string) $identity->getGtin());
                $ourTitles[(string) $id] = $titles[(int) $identity->getProductId()] ?? '';
            }

            $byGtin = $this->searchCards($gtins, $ourTitles, 'identity', $priority, $maxWaitSeconds, $sandbox);
            $cards = $byGtin['cards'];
            $searchedBy = array_fill_keys(array_keys($cards), 'gtin');

            // 1b. Observed live (2026-10-09): Google knew 2 GTINs out of 10,
            // while a title search found the right card. A product whose GTIN
            // led nowhere is searched once by title, for all its variants
            // at once; telling the variant apart is Jev's job.
            $missedByProduct = [];

            foreach ($identities as $id => $identity) {
                if (!isset($cards[$id])) {
                    $missedByProduct[(int) $identity->getProductId()][] = (string) $id;
                }
            }

            $titleKeywords = [];

            foreach (array_keys($missedByProduct) as $productId) {
                if (($titles[$productId] ?? '') !== '') {
                    $titleKeywords['p'.$productId] = $titles[$productId];
                }
            }

            $byTitle = $this->searchCards($titleKeywords, $titleKeywords, 'product', $priority, $maxWaitSeconds, $sandbox);

            foreach ($missedByProduct as $productId => $tags) {
                $card = $byTitle['cards']['p'.$productId] ?? null;

                if ($card === null) {
                    // Kept for the report: a human tells a strict gate from
                    // a product Google does not know at a glance.
                    $noCard[$titles[$productId] ?? '#'.$productId] = array_merge(
                        array_map(static fn (string $title): string => 'GTIN: '.$title, \array_slice($byGtin['missed'][$tags[0]] ?? [], 0, 2)),
                        array_map(static fn (string $title): string => 'title: '.$title, \array_slice($byTitle['missed']['p'.$productId] ?? [], 0, 2)),
                    );
                    continue;
                }

                foreach ($tags as $tag) {
                    $cards[$tag] = $card;
                    $searchedBy[$tag] = 'title';
                }
            }

            foreach ([$byGtin, $byTitle] as $search) {
                $cost += $search['costUsd'];
                $counts['tasks'] += $search['tasks'];
                array_push($errors, ...$search['errors']);
            }

            // 2. Read the sellers of each card, once per card: the variants
            // of one product often lead Google to the same card.
            $tagsByProductId = [];

            foreach ($cards as $tag => $card) {
                $tagsByProductId[$card->productId][] = $tag;
            }

            if ($tagsByProductId !== []) {
                $productIds = array_combine(array_map('strval', array_keys($tagsByProductId)), array_map('strval', array_keys($tagsByProductId)));
                $postedSellers = $this->client->postSellerLookups($productIds, $priority, $sandbox);
                $cost += $postedSellers['costUsd'];
                $counts['tasks'] += \count($postedSellers['tasks']);

                foreach ($this->waitFor('sellers', $postedSellers['tasks'], $maxWaitSeconds, $sandbox) as $productId => $taskId) {
                    try {
                        $offers = $this->client->sellerOffers($taskId, $sandbox)['offers'];
                    } catch (DataForSeoException $error) {
                        $errors[] = \sprintf('sellers of card %s: %s', $productId, $error->getMessage());
                        continue;
                    }

                    foreach ($tagsByProductId[$productId] ?? [] as $tag) {
                        $identity = $identities[(int) $tag];
                        $ourPrice = $this->ourPrice->inclTax((int) $identity->getProductSaleElementsId());

                        foreach ($offers as $offer) {
                            ++$counts['seen'];
                            $competitorId = $sellers->competitorOf($offer);

                            if ($competitorId === null) {
                                $label = $this->domains->registrableDomain($offer->domain) ?? $offer->sellerName;
                                $unknownSellers[$label] = ($unknownSellers[$label] ?? 0) + 1;
                                continue;
                            }

                            if ($this->record($identity, $competitorId, $cards[$tag], $searchedBy[$tag], $offer, $ourPrice, (int) $run->getId())) {
                                ++$counts['written'];
                            }
                        }
                    }
                }
            }
        }

        arsort($unknownSellers);

        $run->setStatus($errors === [] ? 'success' : 'partial')
            ->setFinishedAt(new \DateTime())
            ->setTargetsPlanned($counts['targets'])
            ->setOffersSeen($counts['seen'])
            ->setQuotesWritten($counts['written'])
            ->setDataforseoTasks($counts['tasks'])
            ->setDataforseoCostUsd(number_format($cost, 8, '.', ''))
            ->setErrorsJson($errors === [] ? null : json_encode($errors, \JSON_UNESCAPED_UNICODE))
            ->save();

        return ['run' => $run, 'unknownSellers' => $unknownSellers, 'noPlausibleCard' => $noCard];
    }

    /**
     * Posts the searches, waits, and keeps for each the first card whose
     * title is plausible against ours.
     *
     * @param array<string, string> $keywordsByTag
     * @param array<string, string> $ourTitleByTag
     *
     * @return array{cards: array<string, ShoppingCard>, missed: array<string, list<string>>, costUsd: float, tasks: int, errors: list<string>}
     *
     * @throws DataForSeoException
     */
    private function searchCards(array $keywordsByTag, array $ourTitleByTag, string $subject, int $priority, int $maxWaitSeconds, bool $sandbox): array
    {
        $found = ['cards' => [], 'missed' => [], 'costUsd' => 0.0, 'tasks' => 0, 'errors' => []];

        if ($keywordsByTag === []) {
            return $found;
        }

        $posted = $this->client->postProductSearches($keywordsByTag, $priority, $sandbox);
        $found['costUsd'] = $posted['costUsd'];
        $found['tasks'] = \count($posted['tasks']);

        foreach ($this->waitFor('products', $posted['tasks'], $maxWaitSeconds, $sandbox) as $tag => $taskId) {
            try {
                $result = $this->client->productCards($taskId, $sandbox);
            } catch (DataForSeoException $error) {
                // Observed live: a task can end in 40101 "Internal SE Server
                // Error". One failed search must not sink the pass.
                $found['errors'][] = \sprintf('search for %s %s: %s', $subject, $tag, $error->getMessage());
                continue;
            }

            foreach ($result['cards'] as $card) {
                if ($this->relevance->isPlausible($ourTitleByTag[$tag] ?? '', $card->title)) {
                    $found['cards'][$tag] = $card;
                    continue 2;
                }
            }

            $found['missed'][$tag] = array_map(static fn (ShoppingCard $card): string => $card->title, \array_slice($result['cards'], 0, 3));
        }

        return $found;
    }

    /**
     * @param array<int, PwProductIdentity> $identities
     *
     * @return array<int, string> product id => title
     */
    private function productTitles(array $identities): array
    {
        $productIds = array_values(array_unique(array_map(static fn (PwProductIdentity $identity): int => (int) $identity->getProductId(), $identities)));

        if ($productIds === []) {
            return [];
        }

        $titles = [];

        foreach (ProductI18nQuery::create()->filterById($productIds, Criteria::IN)->filterByLocale(self::LOCALE)->find() as $i18n) {
            $titles[(int) $i18n->getId()] = (string) $i18n->getTitle();
        }

        return $titles;
    }

    /**
     * An EAN-13 travels as 13 digits: the 14-digit padded form is our storage
     * format, not what merchants publish.
     */
    public static function searchableGtin(string $gtin14): string
    {
        return str_starts_with($gtin14, '0') && \strlen($gtin14) === 14 ? substr($gtin14, 1) : $gtin14;
    }

    /**
     * Waits on tasks_ready, then hands back every task: the ready ones, and
     * the ones never listed, which the caller reads once anyway.
     *
     * Observed live (2026-10-09): tasks_ready answered 40104 twice in a row of
     * normal answers, and a task that ended in error never appeared in it. So
     * a polling error is waited out, and an unlisted task is read rather than
     * dropped: reading is free, and it either brings the result or the reason
     * it failed.
     *
     * @param array<string, string> $tasks tag => task id
     *
     * @return array<string, string> tag => task id
     */
    private function waitFor(string $function, array $tasks, int $maxWaitSeconds, bool $sandbox): array
    {
        if ($sandbox || $tasks === []) {
            // The sandbox answers any task id at once and lists nothing as ready.
            return $tasks;
        }

        $pending = array_flip($tasks);
        $deadline = time() + $maxWaitSeconds;

        while ($pending !== [] && time() < $deadline) {
            try {
                foreach ($this->client->readyTaskIds($function, $sandbox) as $id) {
                    unset($pending[$id]);
                }
            } catch (DataForSeoException) {
                // Transient: try again on the next tick.
            }

            if ($pending !== []) {
                ($this->sleep)(self::POLL_SECONDS);
            }
        }

        return $tasks;
    }

    /**
     * @return array<string, int> registrable domain => competitor id
     */
    private function watchedCompetitors(): array
    {
        $watched = [];

        foreach (PwCompetitorQuery::create()->filterByStatus(CompetitorStatus::Watched->value)->find() as $competitor) {
            $watched[(string) $competitor->getDomain()] = (int) $competitor->getId();
        }

        return $watched;
    }

    /**
     * Only variants whose GTIN can be searched: valid and not shared, since a
     * shared GTIN points Google at a card for several variants.
     *
     * @param list<string> $productRefs
     *
     * @return array<int, PwProductIdentity> keyed by identity id
     */
    private function identities(int $limit, array $productRefs, bool $onePerProduct): array
    {
        $query = PwProductIdentityQuery::create()
            ->filterByMonitored(1)
            ->filterByGtinValid(1)
            ->filterByGtinShared(0)
            ->orderById();

        if (!$onePerProduct) {
            $query->limit($limit);
        }

        if ($productRefs !== []) {
            $query->useProductSaleElementsQuery()->useProductQuery()->filterByRef($productRefs, Criteria::IN)->endUse()->endUse();
        }

        $identities = [];
        $products = [];

        foreach ($query->find() as $identity) {
            if ($onePerProduct && isset($products[(int) $identity->getProductId()])) {
                continue;
            }

            $products[(int) $identity->getProductId()] = true;
            $identities[(int) $identity->getId()] = $identity;

            if (\count($identities) >= $limit) {
                break;
            }
        }

        return $identities;
    }

    private function record(PwProductIdentity $identity, int $competitorId, ShoppingCard $card, string $searchedBy, SellerOffer $offer, ?string $ourPrice, int $runId): bool
    {
        $saleElementId = (int) $identity->getProductSaleElementsId();
        $offerKey = 'shopping:'.$saleElementId;
        $now = new \DateTime();

        $model = PwCompetitorOfferQuery::create()->filterByCompetitorId($competitorId)->filterByOfferKey($offerKey)->findOne()
            ?? (new PwCompetitorOffer())
                ->setCompetitorId($competitorId)
                ->setProductSaleElementsId($saleElementId)
                ->setOfferKey($offerKey)
                ->setMatchState('candidate')
                ->setFirstSeenAt($now);

        $fingerprint = $this->fingerprint->forRemoteOffer($card->title, null, null, $offer->details);

        // A previously judged offer whose remote identity changed must be
        // judged again: its verdict was about something else. A match a
        // human confirmed goes back to a human, never to the machine.
        $drifted = $model->getJudgedFingerprint() !== null && $model->getJudgedFingerprint() !== $fingerprint;

        if ($drifted) {
            $humanMatch = $model->getMatchState() === self::HUMAN_MATCH;
            $model
                ->setMatchState($humanMatch ? 'needs_review' : 'candidate')
                ->setMatchReason($humanMatch ? 'IDENTITY_DRIFT' : $model->getMatchReason())
                ->setLastComparable(0);
        }

        // An unchanged matched offer keeps its verdict: today's reading counts
        // in the gap as yesterday's did.
        $comparable = !$drifted && \in_array($model->getMatchState(), self::MATCHED_STATES, true) && (int) $model->getLastComparable() === 1;

        $price = $offer->priceExcludingShipping();
        $delta = $price !== null && $ourPrice !== null ? (float) $price - (float) $ourPrice : null;

        $model
            ->setUrl($offer->url)
            ->setRemoteTitle(mb_substr($card->title, 0, 255))
            ->setRemoteVariantLabel($offer->details === null ? null : mb_substr($offer->details, 0, 190))
            ->setRemoteCondition($offer->condition === null ? null : mb_substr($offer->condition, 0, 20))
            ->setIdentityFingerprint($fingerprint)
            ->setLastPrice($price)
            ->setLastTotalPrice($offer->totalPrice)
            ->setLastCurrencyCode($offer->currency)
            ->setLastOurPrice($ourPrice)
            ->setLastDelta($delta === null ? null : number_format($delta, 6, '.', ''))
            ->setLastDeltaPct($delta === null || (float) $ourPrice === 0.0 ? null : number_format($delta / (float) $ourPrice * 100, 3, '.', ''))
            ->setLastSeenAt($now)
            ->setActive(1)
            ->save();

        // One reading per offer, per day, per source: a rerun the same day
        // refreshes nothing and duplicates nothing.
        $today = new \DateTime('today');

        if (PwPriceQuoteQuery::create()->filterByOfferId($model->getId())->filterByCollectedDay($today)->filterBySource(self::SOURCE)->count() > 0) {
            return false;
        }

        (new PwPriceQuote())
            ->setOfferId($model->getId())
            ->setCollectedDay($today)
            ->setSource(self::SOURCE)
            ->setCollectedAt($now)
            ->setPrice($price)
            ->setListPrice($card->oldPrice)
            ->setShippingPrice($offer->shippingPrice)
            ->setShippingIncluded(0)
            ->setTotalPrice($offer->totalPrice)
            ->setCurrencyCode($offer->currency)
            ->setAvailability($offer->availability === null ? null : mb_substr($offer->availability, 0, 32))
            ->setConditionCode($offer->condition === null ? null : mb_substr($offer->condition, 0, 20))
            ->setOurPrice($ourPrice)
            ->setDelta($delta === null ? null : number_format($delta, 6, '.', ''))
            ->setDeltaPct($delta === null || (float) $ourPrice === 0.0 ? null : number_format($delta / (float) $ourPrice * 100, 3, '.', ''))
            ->setComparable($comparable ? 1 : 0)
            ->setExclusionReason($comparable ? null : $model->getExclusionReason())
            ->setExtractionMethod('dataforseo_sellers')
            ->setRawPayloadJson(json_encode(['searched_by' => $searchedBy, 'card' => (array) $card, 'offer' => (array) $offer],\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
            ->setCollectRunId($runId)
            ->save();

        return true;
    }
}
