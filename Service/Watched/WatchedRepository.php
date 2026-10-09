<?php

declare(strict_types=1);

namespace PriceWatch\Service\Watched;

use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\ComparatorView;
use Propel\Runtime\Propel;

/**
 * Every product of the price watch referential, one row each, whether or not
 * it was ever collected: the referential is the starting point, competitor
 * offers are joined to it, never the other way round.
 *
 * One aggregate query, because the figures are per product while the data
 * is per variant (identities) and per variant and competitor (offers). Only
 * offers of whitelisted competitors count, as on the comparator.
 */
final readonly class WatchedRepository
{
    private const FALLBACK_LOCALE = 'fr_FR';

    public function __construct(
        private ComparatorRepository $comparator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(WatchedFilters $filters, string $locale): array
    {
        [$sql, $params] = $this->aggregate($filters, $filters->view, $locale);
        $order = match ($filters->sort) {
            'title' => 'title ASC, product_id ASC',
            'collected' => 'last_collected_at IS NULL ASC, last_collected_at DESC, title ASC',
            default => 'best_gap IS NULL ASC, best_gap ASC, title ASC',
        };

        return $this->fetch($sql.' ORDER BY '.$order.' LIMIT '.WatchedFilters::PAGE_SIZE.' OFFSET '.$filters->offset(), $params);
    }

    /**
     * One pass over the aggregate for every tab: each tab's condition is the
     * same as its HAVING clause in aggregate(), summed instead of filtered.
     *
     * @return array<string, int> view value => number of products, same search
     */
    public function counts(WatchedFilters $filters, string $locale): array
    {
        [$sql, $params] = $this->aggregate($filters, WatchedView::All, $locale);
        $row = $this->fetch(
            'SELECT
                COUNT(*) AS `all`,
                COALESCE(SUM(monitored > 0 AND last_collected_at IS NULL), 0) AS `never`,
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
    private function aggregate(WatchedFilters $filters, WatchedView $view, string $locale): array
    {
        $params = ['locale' => $locale, 'fallback' => self::FALLBACK_LOCALE];
        $competitors = array_keys($this->comparator->competitors());
        $competitorList = $competitors === [] ? '0' : implode(',', array_map('intval', $competitors));
        $matched = "'".implode("','", ComparatorView::MATCHED_STATES)."'";

        $where = '';

        if ($filters->search !== '') {
            $where = 'WHERE (p.ref LIKE :search OR loc.title LIKE :search OR fb.title LIKE :search)';
            $params['search'] = '%'.addcslashes($filters->search, '%_\\').'%';
        }

        $having = match ($view) {
            WatchedView::All => '',
            WatchedView::Never => 'HAVING monitored > 0 AND last_collected_at IS NULL',
            WatchedView::NotCollectable => 'HAVING monitored > 0 AND collectable = 0',
            WatchedView::Compared => 'HAVING compared > 0',
            WatchedView::Paused => 'HAVING monitored = 0',
        };

        $sql = "SELECT
                i.product_id,
                p.ref,
                COALESCE(NULLIF(loc.title, ''), NULLIF(fb.title, ''), p.ref) AS title,
                COUNT(*) AS variants,
                SUM(i.monitored = 1) AS monitored,
                SUM(i.monitored = 1 AND i.gtin_valid = 1 AND i.gtin_shared = 0) AS collectable,
                MAX(i.last_collected_at) AS last_collected_at,
                COALESCE(SUM(o.compared), 0) AS compared,
                COALESCE(SUM(o.review), 0) AS review,
                MIN(o.best_gap) AS best_gap
            FROM pw_product_identity i
            JOIN product p ON p.id = i.product_id
            LEFT JOIN product_i18n loc ON loc.id = p.id AND loc.locale = :locale
            LEFT JOIN product_i18n fb ON fb.id = p.id AND fb.locale = :fallback
            LEFT JOIN (
                SELECT
                    product_sale_elements_id,
                    SUM(match_state IN ($matched) AND last_comparable = 1) AS compared,
                    SUM(match_state = 'needs_review') AS review,
                    MIN(CASE WHEN match_state IN ($matched) AND last_comparable = 1 THEN last_delta_pct END) AS best_gap
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
