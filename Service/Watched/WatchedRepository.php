<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Direct\PageOutcome;
use Propel\Runtime\Propel;

/**
 * Every product of the price watch referential, one row each, whether or not
 * it was ever collected: the referential is the starting point, competitor
 * offers are joined to it, never the other way round.
 *
 * One aggregate query, because the figures are per product while the data
 * is per variant (identities) and per variant and competitor (offers). Only
 * offers of whitelisted competitors count, as on the comparator.
 *
 * A product's last watch is the latest of its last Google Shopping search
 * and its last competitor page read straight (LastWatch).
 */
final readonly class WatchedRepository
{
    private const FALLBACK_LOCALE = 'fr_FR';

    /** Stands for "never" inside GREATEST(), which NULL would swallow. */
    private const NEVER = '1000-01-01 00:00:00';

    public function __construct(
        private ComparatorRepository $comparator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(WatchedFilters $filters, string $locale, ?\DateTimeInterface $now = null): array
    {
        [$sql, $params] = $this->aggregate($filters, $filters->view, $locale, $now ?? new \DateTimeImmutable());
        $order = match ($filters->sort) {
            'title' => 'title ASC, product_id ASC',
            'collected' => 'last_watched_at IS NULL ASC, last_watched_at DESC, title ASC',
            default => 'best_gap IS NULL ASC, best_gap ASC, title ASC',
        };

        // Sorted outside the aggregate: inside it, a name shared by an alias
        // and a column (last_collected_at) means the column, not the MAX().
        return $this->fetch('SELECT * FROM ('.$sql.') AS products ORDER BY '.$order.' LIMIT '.WatchedFilters::PAGE_SIZE.' OFFSET '.$filters->offset(), $params);
    }

    /**
     * The products a "collect them" button of the watched list starts on
     * (asked 2026-10-13): watched, never read or read too long ago; those
     * that can be searched first, then the oldest reading. Same rules as the
     * tabs of the same names.
     *
     * @return array{productIds: list<int>, searchable: int, variants: int, total: int} the first $limit products, how many of them can be searched and with how many variants, how many qualify
     */
    public function toCollect(WatchedView $view, int $limit, ?\DateTimeInterface $now = null): array
    {
        if ($view !== WatchedView::Never && $view !== WatchedView::Stale) {
            throw new \InvalidArgumentException('Only the never read and the stale products are collected in a batch.');
        }

        [$sql, $params] = $this->aggregate(new WatchedFilters($view), $view, self::FALLBACK_LOCALE, $now ?? new \DateTimeImmutable());
        $rows = $this->fetch('SELECT product_id, collectable FROM ('.$sql.') AS products ORDER BY collectable > 0 DESC, last_watched_at IS NULL DESC, last_watched_at ASC, product_id ASC', $params);
        $batch = \array_slice($rows, 0, max(0, $limit));

        return [
            'productIds' => array_map(static fn (array $row): int => (int) $row['product_id'], $batch),
            'searchable' => \count(array_filter($batch, static fn (array $row): bool => (int) $row['collectable'] > 0)),
            'variants' => (int) array_sum(array_column($batch, 'collectable')),
            'total' => \count($rows),
        ];
    }

    /**
     * Watched products the shop no longer shows: nothing a customer can buy,
     * nothing worth paying to compare.
     *
     * @return list<int>
     */
    public function offline(): array
    {
        return array_map('intval', array_column($this->fetch(
            'SELECT DISTINCT i.product_id FROM pw_product_identity i JOIN product p ON p.id = i.product_id WHERE i.monitored = 1 AND p.visible = 0 ORDER BY i.product_id',
            [],
        ), 'product_id'));
    }

    /**
     * Watched products without stock in any size.
     *
     * @return list<int>
     */
    public function outOfStock(): array
    {
        return array_map('intval', array_column($this->fetch(
            'SELECT DISTINCT i.product_id FROM pw_product_identity i
            WHERE i.monitored = 1 AND i.product_id IN (
                SELECT product_id FROM product_sale_elements GROUP BY product_id HAVING SUM(GREATEST(quantity, 0)) = 0
            )
            ORDER BY i.product_id',
            [],
        ), 'product_id'));
    }

    /**
     * When this product was last watched, null if never.
     */
    public function lastWatched(int $productId): ?\DateTimeImmutable
    {
        return $this->lastWatchedOf([$productId])[$productId] ?? null;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, \DateTimeImmutable> product id => last watched, for those of the referential ever watched
     */
    public function lastWatchedOf(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->fetch(
            'SELECT i.product_id, '.self::lastWatchedSql().' AS last_watched_at
            FROM pw_product_identity i
            LEFT JOIN ('.self::pagesReadSql().') pg ON pg.product_id = i.product_id
            WHERE i.product_id IN ('.implode(',', array_map('intval', $productIds)).')
            GROUP BY i.product_id',
            ['read' => PageOutcome::Read->value],
        );
        $lastWatched = [];

        foreach ($rows as $row) {
            if ($row['last_watched_at'] !== null) {
                $lastWatched[(int) $row['product_id']] = new \DateTimeImmutable((string) $row['last_watched_at']);
            }
        }

        return $lastWatched;
    }

    /**
     * One pass over the aggregate for every tab: each tab's condition is the
     * same as its HAVING clause in aggregate(), summed instead of filtered.
     *
     * @return array<string, int> view value => number of products, same search
     */
    public function counts(WatchedFilters $filters, string $locale, ?\DateTimeInterface $now = null): array
    {
        [$sql, $params] = $this->aggregate($filters, WatchedView::All, $locale, $now ?? new \DateTimeImmutable());
        $params['stale_before'] = LastWatch::staleBefore($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $row = $this->fetch(
            'SELECT
                COUNT(*) AS `all`,
                COALESCE(SUM(monitored > 0 AND last_watched_at IS NULL), 0) AS `never`,
                COALESCE(SUM(monitored > 0 AND last_watched_at < :stale_before), 0) AS `stale`,
                COALESCE(SUM(monitored > 0 AND collectable = 0), 0) AS `not_collectable`,
                COALESCE(SUM(compared > 0), 0) AS `compared`,
                COALESCE(SUM(monitored = 0), 0) AS `paused`
            FROM ('.$sql.') AS products',
            $params,
        )[0] ?? [];

        $counts = [];

        foreach (WatchedView::cases() as $view) {
            $counts[$view->value] = (int) ($row[$view->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function aggregate(WatchedFilters $filters, WatchedView $view, string $locale, \DateTimeInterface $now): array
    {
        $params = ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE, 'read' => PageOutcome::Read->value];
        $competitors = array_keys($this->comparator->competitors());
        $competitorList = $competitors === [] ? '0' : implode(',', array_map('intval', $competitors));
        $inGap = ComparatorView::inGapSql();
        $inReview = ComparatorView::inReviewSql();

        $where = '';

        if ($filters->search !== '') {
            $where = 'WHERE (p.ref LIKE :search OR loc.title LIKE :search OR fb.title LIKE :search)';
            $params['search'] = '%'.addcslashes($filters->search, '%_\\').'%';
        }

        $having = match ($view) {
            WatchedView::All => '',
            WatchedView::Never => 'HAVING monitored > 0 AND last_watched_at IS NULL',
            WatchedView::Stale => 'HAVING monitored > 0 AND last_watched_at < :stale_before',
            WatchedView::NotCollectable => 'HAVING monitored > 0 AND collectable = 0',
            WatchedView::Compared => 'HAVING compared > 0',
            WatchedView::Paused => 'HAVING monitored = 0',
        };

        if ($view === WatchedView::Stale) {
            $params['stale_before'] = LastWatch::staleBefore($now)->format('Y-m-d H:i:s');
        }

        $lastWatched = self::lastWatchedSql();
        $pagesRead = self::pagesReadSql();
        $sql = "SELECT
                i.product_id,
                p.ref,
                COALESCE(NULLIF(loc.title, ''), NULLIF(fb.title, ''), p.ref) AS title,
                COUNT(*) AS variants,
                SUM(i.monitored = 1) AS monitored,
                SUM(i.monitored = 1 AND i.gtin_valid = 1 AND i.gtin_shared = 0) AS collectable,
                MAX(i.last_collected_at) AS last_collected_at,
                $lastWatched AS last_watched_at,
                COALESCE(SUM(o.compared), 0) AS compared,
                COALESCE(SUM(o.review), 0) AS review,
                MIN(o.best_gap) AS best_gap
            FROM pw_product_identity i
            JOIN product p ON p.id = i.product_id
            LEFT JOIN product_i18n loc ON loc.id = p.id AND loc.locale = :locale
            LEFT JOIN product_i18n fb ON fb.id = p.id AND fb.locale = :fallback
            LEFT JOIN ($pagesRead) pg ON pg.product_id = i.product_id
            LEFT JOIN (
                SELECT
                    product_sale_elements_id,
                    SUM($inGap) AS compared,
                    SUM($inReview) AS review,
                    MIN(CASE WHEN $inGap THEN last_delta_pct END) AS best_gap
                FROM pw_competitor_offer
                WHERE active = 1 AND competitor_id IN ($competitorList)
                GROUP BY product_sale_elements_id
            ) o ON o.product_sale_elements_id = i.product_sale_elements_id
            $where
            GROUP BY i.product_id, p.ref, loc.title, fb.title
            $having";

        return [$sql, $params];
    }

    /**
     * Per product, over pw_product_identity i joined to pagesReadSql() as pg.
     * Shared with the suggestions, so both screens agree on "old reading".
     */
    public static function lastWatchedSql(): string
    {
        return "NULLIF(GREATEST(COALESCE(MAX(i.last_collected_at), '".self::NEVER."'), COALESCE(MAX(pg.last_read), '".self::NEVER."')), '".self::NEVER."')";
    }

    /**
     * When each product last had a competitor page read with a price: a page
     * that failed or showed nothing of ours watched nothing. Binds :read.
     */
    public static function pagesReadSql(): string
    {
        return 'SELECT product_id, MAX(last_fetched_at) AS last_read FROM pw_competitor_page WHERE last_outcome = :read GROUP BY product_id';
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(string $sql, array $params): array
    {
        $statement = Propel::getConnection('TheliaMain')->prepare($sql);

        foreach ($params as $name => $value) {
            $statement->bindValue(':'.$name, $value);
        }

        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
