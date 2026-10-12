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
use PriceWatch\Service\Direct\DirectCollector;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\IdentityFingerprint;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Run\PassItems;
use PriceWatch\Service\Run\PassStep;
use PriceWatch\Service\Run\PriceWatchLog;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
    private const POLL_SECONDS = 5;

    /**
     * After this, late tasks are read directly instead of waited for. Reading
     * is free, and tasks_ready listed none of the title or sellers tasks of
     * 2026-10-12: at 30 s, every step lasted at least 30 s.
     */
    private const PROBE_AFTER_SECONDS = 5;

    /** How often the wait is reported in the log. */
    private const REPORT_EVERY_SECONDS = 60;
    private const LOCALE = 'fr_FR';
    private const HUMAN_MATCH = 'matched_manual';
    private const MATCHED_STATES = ['matched_exact', 'matched_judged', 'matched_manual'];

    /** Rows read per search allowed: sizes riding on another's search are skipped. */
    private const SCAN_PER_SEARCH = 20;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @var \Closure(): int */
    private \Closure $now;

    private LoggerInterface $log;

    /**
     * @param (\Closure(int): void)|null $sleep injected so tests never wait
     * @param (\Closure(): int)|null     $now   injected so tests control time
     */
    public function __construct(
        private DataForSeoClient $client,
        private DomainNormalizer $domains,
        private TextNormalizer $text,
        private IdentityFingerprint $fingerprint,
        private OurPrice $ourPrice,
        private CardRelevance $relevance,
        private ShownSize $shownSize,
        ?\Closure $sleep = null,
        ?PriceWatchLog $log = null,
        ?\Closure $now = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->now = $now ?? static fn (): int => time();
        $this->log = $log ?? new NullLogger();
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
        $tally = new CollectTally();
        $this->log->info('Collect pass #{run} started: limit {limit}, {queue} queue{products}{sandbox}.', [
            'run' => $run->getId(),
            'limit' => $limit,
            'queue' => $priority === 2 ? 'priority' : 'standard',
            'products' => $productRefs === [] ? '' : ', products '.implode(', ', $productRefs),
            'sandbox' => $sandbox ? ', sandbox' : '',
        ]);

        try {
            $this->pass($run, $tally, $limit, $priority, $maxWaitSeconds, $productRefs, $sandbox, $onePerProduct);
        } catch (\Throwable $error) {
            // Whatever stops the pass, its row says so, with what was already
            // paid: never a pass left "started" because of an exception.
            $tally->errors[] = \sprintf('Stopped by an error: %s (%s)', $error->getMessage(), $error::class);
            $this->close($run, $tally, 'failed');
            $this->log->error('Collect pass #{run} stopped by an error: {error}', ['run' => $run->getId(), 'error' => $error->getMessage(), 'exception' => $error]);

            throw $error;
        }

        $this->close($run, $tally, $tally->errors === [] ? 'success' : 'partial');
        arsort($tally->unknownSellers);

        return ['run' => $run, 'unknownSellers' => $tally->unknownSellers, 'noPlausibleCard' => $tally->noCard];
    }

    private function close(PwCollectRun $run, CollectTally $tally, string $status): void
    {
        $run->setStatus($status)
            ->setFinishedAt(new \DateTime())
            ->setTargetsPlanned($tally->targets)
            ->setOffersSeen($tally->seen)
            ->setQuotesWritten($tally->written)
            ->setDataforseoTasks($tally->tasks)
            ->setDataforseoCostUsd(number_format($tally->costUsd, 8, '.', ''))
            ->setErrorsJson($tally->errors === [] ? null : json_encode($tally->errors, \JSON_UNESCAPED_UNICODE))
            ->save();

        $seconds = $run->getFinishedAt() !== null && $run->getStartedAt() !== null ? $run->getFinishedAt()->getTimestamp() - $run->getStartedAt()->getTimestamp() : 0;
        $this->log->log($status === 'success' ? 'info' : 'warning', 'Collect pass #{run} {status} in {seconds} s: {targets} variant(s), {tasks} task(s), {cost} USD, {seen} offer(s) seen, {written} recorded, {otherSize} left out as another size, {errors} error(s).', [
            'run' => $run->getId(),
            'status' => $status,
            'seconds' => $seconds,
            'targets' => $tally->targets,
            'tasks' => $tally->tasks,
            'cost' => number_format($tally->costUsd, 3, '.', ''),
            'seen' => $tally->seen,
            'written' => $tally->written,
            'otherSize' => $tally->otherSize,
            'errors' => \count($tally->errors),
        ]);
    }

    /**
     * @param list<string> $productRefs
     *
     * @throws DataForSeoException
     */
    private function pass(PwCollectRun $run, CollectTally $tally, int $limit, int $priority, int $maxWaitSeconds, array $productRefs, bool $sandbox, bool $onePerProduct): void
    {
        $watched = $this->watchedCompetitors();
        $domainsById = array_flip($watched);
        $sellers = new SellerMatcher($watched, $this->domains, $this->text);
        // A product whose sizes we all sell at one price is one comparison:
        // one variant is searched, the others ride on the card it leads to.
        [$identities, $ridesOn] = $this->identities($limit, $productRefs, $onePerProduct);
        $searched = array_diff_key($identities, $ridesOn);
        $tally->targets = \count($searched);
        $this->log->info('Collect: {variants} variant(s) to search, {riders} more at the same price, {competitors} whitelisted competitor(s).', ['variants' => \count($searched), 'riders' => \count($ridesOn), 'competitors' => \count($watched)]);

        if ($watched === []) {
            $tally->errors[] = 'No whitelisted competitor: nothing would be compared. Add competitors to the whitelist first.';
        }

        if ($watched !== [] && $identities !== []) {
            // 1. Find the product card Google attaches to each GTIN.
            $titles = $this->productTitles($identities);
            $gtins = [];
            $ourTitles = [];
            $items = new PassItems();

            $brands = [];
            $ourBrands = [];

            foreach ($identities as $id => $identity) {
                $items->add((int) $identity->getProductId(), $titles[(int) $identity->getProductId()] ?? '', $identity->getSizeLabel());
                $brands[(int) $identity->getProductId()] ??= $identity->getBrandLabel();

                if (isset($searched[$id])) {
                    $gtins[(string) $id] = self::searchableGtin((string) $identity->getGtin());
                    $ourTitles[(string) $id] = $titles[(int) $identity->getProductId()] ?? '';
                    $ourBrands[(string) $id] = $identity->getBrandLabel();
                }
            }

            $items->set(PassItems::GTIN);
            $items->save($run);

            $byGtin = $this->searchCards($run, PassStep::GtinSearch, $gtins, $ourTitles, $ourBrands, 'identity', $priority, $maxWaitSeconds, $sandbox);

            // Paid at the post, whatever comes back: these go to the back of
            // the queue for the next pass.
            PwProductIdentityQuery::create()
                ->filterById(array_keys($identities), Criteria::IN)
                ->update(['LastCollectedAt' => new \DateTime()]);
            $cards = $byGtin['cards'];

            foreach ($ridesOn as $rider => $searchedId) {
                if (isset($cards[$searchedId])) {
                    $cards[$rider] = $cards[$searchedId];
                }
            }

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

            $items->set(PassItems::SELLERS);
            $items->set(PassItems::TITLE, array_keys($missedByProduct));
            $items->save($run);
            $titleKeywords = [];
            $titleBrands = [];

            foreach (array_keys($missedByProduct) as $productId) {
                if (($titles[$productId] ?? '') !== '') {
                    $titleKeywords['p'.$productId] = $titles[$productId];
                    $titleBrands['p'.$productId] = $brands[$productId] ?? null;
                }
            }

            $byTitle = $this->searchCards($run, PassStep::TitleSearch, $titleKeywords, $titleKeywords, $titleBrands, 'product', $priority, $maxWaitSeconds, $sandbox);

            foreach ($missedByProduct as $productId => $tags) {
                $card = $byTitle['cards']['p'.$productId] ?? null;

                if ($card === null) {
                    // A search that failed is not a product Google does not know.
                    $failed = \in_array('p'.$productId, $byTitle['failed'], true)
                        || (!isset($titleKeywords['p'.$productId]) && array_intersect($tags, $byGtin['failed']) !== []);
                    $items->set($failed ? PassItems::ERROR : PassItems::NO_CARD, [$productId]);

                    // Kept for the report: a human tells a strict gate from
                    // a product Google does not know at a glance.
                    $tally->noCard[$titles[$productId] ?? '#'.$productId] = array_merge(
                        array_map(static fn (string $title): string => 'GTIN: '.$title, \array_slice($byGtin['missed'][$tags[0]] ?? [], 0, 2)),
                        array_map(static fn (string $title): string => 'title: '.$title, \array_slice($byTitle['missed']['p'.$productId] ?? [], 0, 2)),
                    );
                    continue;
                }

                $items->set(PassItems::SELLERS, [$productId]);

                foreach ($tags as $tag) {
                    $cards[$tag] = $card;
                    $searchedBy[$tag] = 'title';
                }
            }

            $items->save($run);

            // A GTIN search that failed for a product the title search then
            // found cost nothing but its price: no error (pass #198 closed
            // "partial" on four of them, both products read).
            $byGtin['errors'] = array_diff_key($byGtin['errors'], $cards);

            foreach ([$byGtin, $byTitle] as $search) {
                $tally->costUsd += $search['costUsd'];
                $tally->tasks += $search['tasks'];
                array_push($tally->errors, ...array_values($search['errors']));
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
                $tally->costUsd += $postedSellers['costUsd'];
                $tally->tasks += \count($postedSellers['tasks']);
                $this->log->info('Collect: {count} sellers lookup(s) posted.', ['count' => \count($postedSellers['tasks'])]);

                $ready = $this->waitFor($run, PassStep::Sellers, 'sellers', $postedSellers['tasks'], $maxWaitSeconds, $sandbox);
                $read = 0;
                PassStep::Recording->record($run, 0, \count($ready));

                foreach ($ready as $productId => $taskId) {
                    PassStep::Recording->record($run, $read++, \count($ready));
                    // Our products behind this Google card.
                    $ours = array_values(array_unique(array_map(static fn (string $tag): int => (int) $identities[(int) $tag]->getProductId(), $tagsByProductId[$productId] ?? [])));

                    try {
                        $listing = $this->client->sellerOffers($taskId, $sandbox);
                        $offers = $listing['offers'];
                    } catch (DataForSeoException $error) {
                        $tally->errors[] = \sprintf('sellers of card %s: %s', $productId, $error->getMessage());
                        $this->log->warning('Collect: sellers of card {card} not read: {error}', ['card' => $productId, 'error' => $error->getMessage(), 'task' => $taskId]);
                        $items->set(PassItems::ERROR, $ours);
                        $items->save($run);
                        continue;
                    }

                    // Another card of the same product may have failed: an
                    // error stays visible.
                    $items->set(PassItems::DONE, array_values(array_filter($ours, static fn (int $ourId): bool => $items->status($ourId) !== PassItems::ERROR)));

                    // The card shows one size: each offer only reaches our
                    // variants of the size its details name.
                    $sizeByTag = [];

                    foreach ($tagsByProductId[$productId] ?? [] as $tag) {
                        $sizeByTag[$tag] = $identities[(int) $tag]->getSizeLabel();
                    }

                    $shown = array_map(fn (SellerOffer $offer): array => array_flip($this->shownSize->variantsShown($offer->details, $sizeByTag)), $offers);

                    foreach ($tagsByProductId[$productId] ?? [] as $tag) {
                        $identity = $identities[(int) $tag];
                        $ourPrice = $this->ourPrice->inclTax((int) $identity->getProductSaleElementsId());

                        foreach ($offers as $index => $offer) {
                            ++$tally->seen;
                            $competitorId = $sellers->competitorOf($offer);

                            if ($competitorId === null) {
                                $label = $this->domains->registrableDomain($offer->domain) ?? $offer->sellerName;
                                $tally->unknownSellers[$label] = ($tally->unknownSellers[$label] ?? 0) + 1;
                                continue;
                            }

                            if (!isset($shown[$index][(string) $tag])) {
                                ++$tally->otherSize;
                                continue;
                            }

                            $items->offerRecorded((int) $identity->getProductId());
                            $links = [
                                'merchant' => $this->merchantUrl($listing['pageUrl'], $domainsById[$competitorId] ?? null),
                                'google' => $listing['googleUrl'] ?? $cards[$tag]->googleUrl,
                            ];

                            if ($this->record($identity, $competitorId, $cards[$tag], $searchedBy[$tag], $offer, $links, $ourPrice, (int) $run->getId())) {
                                ++$tally->written;
                            }
                        }
                    }

                    $items->save($run);
                }

                PassStep::Recording->record($run, \count($ready), \count($ready));
            }

            // A card whose sellers lookup was never created: not read.
            foreach ($identities as $identity) {
                if ($items->status((int) $identity->getProductId()) === PassItems::SELLERS) {
                    $items->set(PassItems::ERROR, [(int) $identity->getProductId()]);
                }
            }

            $items->save($run);
        }
    }

    /**
     * Posts the searches, waits, and keeps for each the first card whose
     * title is plausible against ours.
     *
     * @param array<array-key, string> $keywordsByTag PHP turns numeric tags into int keys
     * @param array<array-key, string> $ourTitleByTag
     * @param array<array-key, ?string> $brandByTag    our brand: alone, it makes no card ours
     *
     * @return array{cards: array<array-key, ShoppingCard>, missed: array<array-key, list<string>>, costUsd: float, tasks: int, errors: array<string, string>, failed: list<string>} errors keyed by tag; failed: tags whose search ended in error
     *
     * @throws DataForSeoException
     */
    private function searchCards(PwCollectRun $run, PassStep $step, array $keywordsByTag, array $ourTitleByTag, array $brandByTag, string $subject, int $priority, int $maxWaitSeconds, bool $sandbox): array
    {
        $found = ['cards' => [], 'missed' => [], 'costUsd' => 0.0, 'tasks' => 0, 'errors' => [], 'failed' => []];

        if ($keywordsByTag === []) {
            return $found;
        }

        $posted = $this->client->postProductSearches($keywordsByTag, $priority, $sandbox);
        $found['costUsd'] = $posted['costUsd'];
        $found['tasks'] = \count($posted['tasks']);
        $this->log->info('Collect: {count} {subject} search(es) posted for {cost} USD.', ['count' => $found['tasks'], 'subject' => $subject, 'cost' => number_format($posted['costUsd'], 3, '.', '')]);

        if ($found['tasks'] < \count($keywordsByTag)) {
            $this->log->warning('Collect: DataForSEO created {created} of {asked} {subject} search(es).', ['created' => $found['tasks'], 'asked' => \count($keywordsByTag), 'subject' => $subject]);
        }

        foreach ($this->waitFor($run, $step, 'products', $posted['tasks'], $maxWaitSeconds, $sandbox) as $tag => $taskId) {
            try {
                $result = $this->client->productCards($taskId, $sandbox);
            } catch (DataForSeoException $error) {
                // Observed live: a task can end in 40101 "Internal SE Server
                // Error". One failed search must not sink the pass.
                $found['errors'][(string) $tag] = \sprintf('search for %s %s: %s', $subject, $tag, $error->getMessage());
                $found['failed'][] = (string) $tag;
                $this->log->warning('Collect: search for {subject} {tag} failed: {error}', ['subject' => $subject, 'tag' => $tag, 'error' => $error->getMessage(), 'task' => $taskId]);
                continue;
            }

            foreach ($result['cards'] as $card) {
                if ($this->relevance->isPlausible($ourTitleByTag[$tag] ?? '', $card->title, $brandByTag[$tag] ?? null)) {
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
     * Observed live (2026-10-09): a task that ends in error (40101) never
     * shows in tasks_ready, so the pass used to wait for it until the
     * deadline: 7 minutes for 10 variants. After PROBE_AFTER_SECONDS, late
     * tasks are read directly (free): done in error or in result, they stop
     * being waited for. tasks_ready itself answered 40104 twice in a row of
     * normal answers: a polling error is logged and waited out.
     *
     * @param array<string, string> $tasks tag => task id
     *
     * @return array<string, string> tag => task id
     */
    private function waitFor(PwCollectRun $run, PassStep $step, string $function, array $tasks, int $maxWaitSeconds, bool $sandbox): array
    {
        if ($sandbox || $tasks === []) {
            // The sandbox answers any task id at once and lists nothing as ready.
            $step->record($run, \count($tasks), \count($tasks));

            return $tasks;
        }

        $pending = array_flip($tasks);
        $start = ($this->now)();
        $deadline = $start + $maxWaitSeconds;
        $lastReport = $start;

        while ($pending !== [] && ($this->now)() < $deadline) {
            $step->record($run, \count($tasks) - \count($pending), \count($tasks));

            try {
                foreach ($this->client->readyTaskIds($function, $sandbox) as $id) {
                    unset($pending[$id]);
                }
            } catch (DataForSeoException $error) {
                $this->log->warning('Collect: tasks_ready for {function} failed, retrying: {error}', ['function' => $function, 'error' => $error->getMessage()]);
            }

            if ($pending !== [] && ($this->now)() - $start >= self::PROBE_AFTER_SECONDS) {
                foreach (array_keys($pending) as $id) {
                    if ($this->client->taskState($function, (string) $id, $sandbox) === 'done') {
                        unset($pending[$id]);
                    }
                }
            }

            if ($pending === []) {
                break;
            }

            if (($this->now)() - $lastReport >= self::REPORT_EVERY_SECONDS) {
                $lastReport = ($this->now)();
                $this->log->info('Collect: still waiting for {count} {function} task(s) after {seconds} s.', ['count' => \count($pending), 'function' => $function, 'seconds' => $lastReport - $start]);
            }

            ($this->sleep)(self::POLL_SECONDS);
        }

        $step->record($run, \count($tasks) - \count($pending), \count($tasks));

        if ($pending !== []) {
            $this->log->warning('Collect: {count} {function} task(s) still not ready after {seconds} s; read anyway, a later pass will retry them.', ['count' => \count($pending), 'function' => $function, 'seconds' => $maxWaitSeconds, 'tasks' => array_keys($pending)]);
        } else {
            $this->log->info('Collect: {count} {function} task(s) ready after {seconds} s.', ['count' => \count($tasks), 'function' => $function, 'seconds' => ($this->now)() - $start]);
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
     * A product whose sizes we all sell at one price costs one search: its
     * other sizes ride on the card the searched one leads to, and the limit,
     * which counts searches, moves on to other products.
     *
     * @param list<string> $productRefs
     *
     * @return array{0: array<int, PwProductIdentity>, 1: array<int, int>} every identity keyed by id, rider id => id of the one searched
     */
    private function identities(int $limit, array $productRefs, bool $onePerProduct): array
    {
        // Oldest first, never searched before all: successive passes rotate
        // through the whole referential instead of taking its first rows
        // every time (MySQL sorts NULL first in ascending order).
        $query = PwProductIdentityQuery::create()
            ->filterByMonitored(1)
            ->filterByGtinValid(1)
            ->filterByGtinShared(0)
            ->orderByLastCollectedAt(Criteria::ASC)
            ->orderById();

        if (!$onePerProduct) {
            // Sizes skipped as riders must not starve the limit.
            $query->limit(max($limit * self::SCAN_PER_SEARCH, $limit));
        }

        if ($productRefs !== []) {
            $query->useProductSaleElementsQuery()->useProductQuery()->filterByRef($productRefs, Criteria::IN)->endUse()->endUse();
        }

        $identities = [];
        $searchedByProduct = [];
        $onePrice = [];

        foreach ($query->find() as $identity) {
            $productId = (int) $identity->getProductId();

            if (isset($searchedByProduct[$productId])) {
                if ($onePerProduct) {
                    continue;
                }

                $onePrice[$productId] ??= $this->onePriceSizes($productId);

                if ($onePrice[$productId] !== false) {
                    continue;
                }
            }

            $searchedByProduct[$productId] ??= (int) $identity->getId();
            $identities[(int) $identity->getId()] = $identity;

            if (\count($identities) >= $limit) {
                break;
            }
        }

        $ridesOn = [];

        if ($onePerProduct) {
            return [$identities, $ridesOn];
        }

        foreach ($searchedByProduct as $productId => $searchedId) {
            $sizes = $onePrice[$productId] ??= $this->onePriceSizes($productId);

            foreach ($sizes === false ? [] : $sizes as $id => $identity) {
                if ($id !== $searchedId) {
                    $identities[$id] = $identity;
                    $ridesOn[$id] = $searchedId;
                }
            }
        }

        return [$identities, $ridesOn];
    }

    /**
     * The monitored sizes of a product we sell at one price, or false when
     * there is a single size, a size without price, or two prices.
     *
     * @return array<int, PwProductIdentity>|false keyed by identity id
     */
    private function onePriceSizes(int $productId): array|false
    {
        $sizes = [];

        foreach (PwProductIdentityQuery::create()->filterByProductId($productId)->filterByMonitored(1)->find() as $identity) {
            $sizes[(int) $identity->getId()] = $identity;
        }

        $prices = array_map(fn (PwProductIdentity $identity): ?string => $this->ourPrice->inclTax((int) $identity->getProductSaleElementsId()), $sizes);

        return \count($sizes) < 2 || \in_array(null, $prices, true) || \count(array_unique($prices)) !== 1 ? false : $sizes;
    }

    /**
     * The result's product page is the one of the seller Google lists first:
     * it is this competitor's page only if it is on this competitor's domain.
     */
    private function merchantUrl(?string $pageUrl, ?string $competitorDomain): ?string
    {
        if ($competitorDomain === null || !self::isWebUrl($pageUrl)) {
            return null;
        }

        return $this->domains->registrableDomain($pageUrl) === $competitorDomain ? $pageUrl : null;
    }

    private static function isWebUrl(?string $url): bool
    {
        return \is_string($url) && preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1;
    }

    /**
     * @param array{merchant: ?string, google: ?string} $links
     */
    private function record(PwProductIdentity $identity, int $competitorId, ShoppingCard $card, string $searchedBy, SellerOffer $offer, array $links, ?string $ourPrice, int $runId): bool
    {
        $saleElementId = (int) $identity->getProductSaleElementsId();
        $offerKey = 'shopping:'.$saleElementId;
        $now = new \DateTime();

        // Read straight from this competitor's page: Google's reading of the
        // same variant would only compete with the proven one.
        if (PwCompetitorOfferQuery::create()->filterByCompetitorId($competitorId)->filterByOfferKey(DirectCollector::OFFER_KEY_PREFIX.$saleElementId)->filterByActive(1)->count() > 0) {
            return false;
        }

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
            // A link found on an earlier day stays: Google lists a different
            // seller first from one day to the next. Its own redirect token
            // ("file:///goto?url=...") is no link at all.
            ->setUrl($links['merchant'] ?? (self::isWebUrl($model->getUrl()) ? $model->getUrl() : null))
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
            ->setRawPayloadJson(json_encode(['searched_by' => $searchedBy, 'google_url' => $links['google'], 'card' => (array) $card, 'offer' => (array) $offer],\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))
            ->setCollectRunId($runId)
            ->save();

        return true;
    }
}
