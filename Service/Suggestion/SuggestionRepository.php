<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Comparator\PricePosition;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\PageOutcome;
use PriceWatch\Service\Watched\WatchedRepository;
use Propel\Runtime\Propel;

/**
 * The four reasons to collect a product now rather than when the rotation
 * reaches it: it sells, we were dearer last time, what a competitor showed
 * is getting old, or it is on a deep promotion. One grouped query each,
 * product ids out; then what the catalogue says of them.
 */
final readonly class SuggestionRepository
{
    /** Orders that are sales: paid, being prepared, sent. Not cancelled, refunded or unpaid. */
    public const SOLD_STATUSES = ['paid', 'processing', 'sent'];

    /**
     * Every category with the visible top-level category it sits under: a
     * hidden family (gift cards, here) is no family to suggest from.
     */
    private const FAMILY_TREE = 'WITH RECURSIVE tree AS ('
        .'SELECT c.id, c.id AS root FROM category c WHERE c.parent = 0 AND c.visible = 1'
        .' UNION ALL SELECT c.id, tree.root FROM category c JOIN tree ON c.parent = tree.id)';

    /**
     * @return array<int, array{quantity: int, revenue: float}> product id => units and revenue excluding tax, best first
     */
    public function bestSellers(\DateTimeInterface $since, int $limit): array
    {
        // order_product keeps the reference the product had when sold; the
        // sale element may have been deleted since, the product ref stays.
        $rows = $this->rows(
            'SELECT p.id AS product_id, SUM(op.quantity) AS quantity,'
            .' SUM(op.quantity * IF(op.was_in_promo = 1, op.promo_price, op.price)) AS revenue'
            .' FROM order_product op'
            .' JOIN `order` o ON o.id = op.order_id'
            .' JOIN order_status os ON os.id = o.status_id'
            .' JOIN product p ON p.ref = op.product_ref'
            .' WHERE o.created_at >= :since AND os.code IN ('.self::quoted(self::SOLD_STATUSES).')'
            .' GROUP BY p.id ORDER BY revenue DESC, p.id LIMIT '.max(1, $limit),
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $sellers = [];

        foreach ($rows as $row) {
            $sellers[(int) $row['product_id']] = ['quantity' => (int) $row['quantity'], 'revenue' => (float) $row['revenue']];
        }

        return $sellers;
    }

    /**
     * @return array<int, array{gapPct: float, competitors: int}> product id => widest gap against us, worst first
     */
    public function weWereDearer(int $limit): array
    {
        $rows = $this->rows(
            'SELECT pse.product_id, MIN(o.last_delta_pct) AS gap, COUNT(DISTINCT o.competitor_id) AS competitors'
            .' FROM pw_competitor_offer o'
            .' JOIN product_sale_elements pse ON pse.id = o.product_sale_elements_id'
            .' JOIN pw_competitor c ON c.id = o.competitor_id AND c.status = :watched'
            .' WHERE o.active = 1 AND o.last_comparable = 1'
            .' AND o.match_state IN ('.self::quoted(ComparatorView::MATCHED_STATES).')'
            .' AND o.last_delta_pct <= :dearer'
            .' GROUP BY pse.product_id ORDER BY gap ASC, pse.product_id LIMIT '.max(1, $limit),
            ['watched' => CompetitorStatus::Watched->value, 'dearer' => -PricePosition::EQUAL_BELOW],
        );

        $dearer = [];

        foreach ($rows as $row) {
            $dearer[(int) $row['product_id']] = ['gapPct' => (float) $row['gap'], 'competitors' => (int) $row['competitors']];
        }

        return $dearer;
    }

    /**
     * Products a whitelisted competitor was found selling, last watched
     * before $before (Google search or competitor page read, LastWatch):
     * what they showed may no longer hold.
     *
     * @return array<int, \DateTimeImmutable> product id => last collected, oldest first
     */
    public function stale(\DateTimeInterface $before, int $limit): array
    {
        $rows = $this->rows(
            'SELECT i.product_id, '.WatchedRepository::lastWatchedSql().' AS last_collected'
            .' FROM pw_product_identity i'
            .' LEFT JOIN ('.WatchedRepository::pagesReadSql().') pg ON pg.product_id = i.product_id'
            .' JOIN (SELECT DISTINCT pse.product_id FROM pw_competitor_offer o'
            .' JOIN product_sale_elements pse ON pse.id = o.product_sale_elements_id'
            .' JOIN pw_competitor c ON c.id = o.competitor_id AND c.status = :watched'
            .' WHERE o.active = 1 AND o.match_state IN ('.self::quoted(ComparatorView::MATCHED_STATES).')) found'
            .' ON found.product_id = i.product_id'
            .' WHERE i.monitored = 1 AND i.gtin_valid = 1 AND i.gtin_shared = 0'
            .' GROUP BY i.product_id HAVING last_collected < :before'
            .' ORDER BY last_collected ASC, i.product_id LIMIT '.max(1, $limit),
            ['watched' => CompetitorStatus::Watched->value, 'before' => $before->format('Y-m-d H:i:s'), 'read' => PageOutcome::Read->value],
        );

        $stale = [];

        foreach ($rows as $row) {
            $stale[(int) $row['product_id']] = new \DateTimeImmutable((string) $row['last_collected']);
        }

        return $stale;
    }

    /**
     * Products on a deep promotion we can sell now, the largest saving in
     * euros first: a discount on a dear product moves more money.
     *
     * @return list<int> product ids
     */
    public function onPromotion(float $minimumDiscountPct, int $limit): array
    {
        $rows = $this->rows(
            'SELECT pse.product_id, MAX(pp.price - pp.promo_price) AS saving'
            .' FROM product_sale_elements pse'
            .' JOIN product p ON p.id = pse.product_id AND p.visible = 1'
            .' JOIN product_price pp ON pp.product_sale_elements_id = pse.id'
            .' JOIN currency cur ON cur.id = pp.currency_id AND cur.by_default = 1'
            .' WHERE pse.promo = 1 AND pse.quantity > 0 AND pp.price > 0'
            .' AND pp.promo_price <= pp.price * (1 - :minimum / 100)'
            .' GROUP BY pse.product_id ORDER BY saving DESC, pse.product_id LIMIT '.max(1, $limit),
            ['minimum' => $minimumDiscountPct],
        );

        return array_map(static fn (array $row): int => (int) $row['product_id'], $rows);
    }

    /**
     * What the catalogue says of the products we can sell now: visible,
     * with a variant in stock. A product missing from the result is not
     * worth collecting. Only the variants in stock count.
     *
     * @param list<int> $productIds
     *
     * @return array<int, array{discountPct: float|null, isNew: bool, stockValue: float}> discount 0 to 100 on its deepest promotion, stock valued at the price excluding tax
     */
    public function catalogSignals(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT pse.product_id,'
            .' MAX(IF(pse.promo = 1 AND pp.price > 0, (1 - pp.promo_price / pp.price) * 100, NULL)) AS discount,'
            .' MAX(pse.newness) AS is_new,'
            .' SUM(pse.quantity * COALESCE(IF(pse.promo = 1, pp.promo_price, pp.price), 0)) AS stock_value'
            .' FROM product_sale_elements pse'
            .' JOIN product p ON p.id = pse.product_id AND p.visible = 1'
            .' LEFT JOIN product_price pp ON pp.product_sale_elements_id = pse.id'
            .' AND pp.currency_id = (SELECT id FROM currency WHERE by_default = 1 ORDER BY id LIMIT 1)'
            .' WHERE pse.product_id IN ('.implode(',', array_map('intval', $productIds)).') AND pse.quantity > 0'
            .' GROUP BY pse.product_id',
            [],
        );

        $signals = [];

        foreach ($rows as $row) {
            $signals[(int) $row['product_id']] = [
                'discountPct' => $row['discount'] === null ? null : (float) $row['discount'],
                'isNew' => (int) $row['is_new'] === 1,
                'stockValue' => (float) $row['stock_value'],
            ];
        }

        return $signals;
    }

    /**
     * Best sellers family by family: ranked across the shop, dear helmets
     * took 47 lines out of 50 (measured 2026-10-12) and no glove ever got in.
     *
     * @param list<int> $excludedFamilies
     *
     * @return array<int, array{quantity: int, revenue: float}> product id => units and revenue excluding tax
     */
    public function bestSellersByFamily(\DateTimeInterface $since, int $perFamily, array $excludedFamilies): array
    {
        $rows = $this->rows(
            self::FAMILY_TREE
            .', sold AS (SELECT p.id AS product_id, SUM(op.quantity) AS quantity,'
            .' SUM(op.quantity * IF(op.was_in_promo = 1, op.promo_price, op.price)) AS revenue'
            .' FROM order_product op'
            .' JOIN `order` o ON o.id = op.order_id'
            .' JOIN order_status os ON os.id = o.status_id'
            .' JOIN product p ON p.ref = op.product_ref'
            .' WHERE o.created_at >= :since AND os.code IN ('.self::quoted(self::SOLD_STATUSES).')'
            .' GROUP BY p.id),'
            .' ranked AS (SELECT sold.*, ROW_NUMBER() OVER (PARTITION BY tree.root ORDER BY sold.revenue DESC, sold.product_id) AS position'
            .' FROM sold'
            .' JOIN product_category pc ON pc.product_id = sold.product_id AND pc.default_category = 1'
            .' JOIN tree ON tree.id = pc.category_id'
            .self::excluding($excludedFamilies).')'
            .' SELECT product_id, quantity, revenue FROM ranked WHERE position <= '.max(1, $perFamily),
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $sellers = [];

        foreach ($rows as $row) {
            $sellers[(int) $row['product_id']] = ['quantity' => (int) $row['quantity'], 'revenue' => (float) $row['revenue']];
        }

        return $sellers;
    }

    /**
     * Deep promotions family by family, the largest saving in euros first.
     *
     * @param list<int> $excludedFamilies
     *
     * @return list<int> product ids
     */
    public function onPromotionByFamily(float $minimumDiscountPct, int $perFamily, array $excludedFamilies): array
    {
        $rows = $this->rows(
            self::FAMILY_TREE
            .', promoted AS (SELECT pse.product_id, MAX(pp.price - pp.promo_price) AS saving'
            .' FROM product_sale_elements pse'
            .' JOIN product p ON p.id = pse.product_id AND p.visible = 1'
            .' JOIN product_price pp ON pp.product_sale_elements_id = pse.id'
            .' JOIN currency cur ON cur.id = pp.currency_id AND cur.by_default = 1'
            .' WHERE pse.promo = 1 AND pse.quantity > 0 AND pp.price > 0'
            .' AND pp.promo_price <= pp.price * (1 - :minimum / 100)'
            .' GROUP BY pse.product_id),'
            .' ranked AS (SELECT promoted.product_id, ROW_NUMBER() OVER (PARTITION BY tree.root ORDER BY promoted.saving DESC, promoted.product_id) AS position'
            .' FROM promoted'
            .' JOIN product_category pc ON pc.product_id = promoted.product_id AND pc.default_category = 1'
            .' JOIN tree ON tree.id = pc.category_id'
            .self::excluding($excludedFamilies).')'
            .' SELECT product_id FROM ranked WHERE position <= '.max(1, $perFamily),
            ['minimum' => $minimumDiscountPct],
        );

        return array_map(static fn (array $row): int => (int) $row['product_id'], $rows);
    }

    /**
     * The top-level family of each product, through its default category.
     * A product whose family is hidden or excluded is left out: it is not
     * worth suggesting.
     *
     * @param list<int> $productIds
     * @param list<int> $excludedFamilies
     *
     * @return array<int, int> product id => family id
     */
    public function families(array $productIds, array $excludedFamilies): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = $this->rows(
            self::FAMILY_TREE
            .' SELECT pc.product_id, tree.root FROM product_category pc'
            .' JOIN tree ON tree.id = pc.category_id'
            .' WHERE pc.default_category = 1 AND pc.product_id IN ('.implode(',', array_map('intval', $productIds)).')'
            .self::excluding($excludedFamilies, 'AND'),
            [],
        );

        $families = [];

        foreach ($rows as $row) {
            $families[(int) $row['product_id']] = (int) $row['root'];
        }

        return $families;
    }

    /**
     * @param list<int> $excludedFamilies
     *
     * @return array<int, float> family id => revenue excluding tax since $since
     */
    public function familyRevenue(\DateTimeInterface $since, array $excludedFamilies): array
    {
        $rows = $this->rows(
            self::FAMILY_TREE
            .' SELECT tree.root, SUM(op.quantity * IF(op.was_in_promo = 1, op.promo_price, op.price)) AS revenue'
            .' FROM order_product op'
            .' JOIN `order` o ON o.id = op.order_id'
            .' JOIN order_status os ON os.id = o.status_id'
            .' JOIN product p ON p.ref = op.product_ref'
            .' JOIN product_category pc ON pc.product_id = p.id AND pc.default_category = 1'
            .' JOIN tree ON tree.id = pc.category_id'
            .' WHERE o.created_at >= :since AND os.code IN ('.self::quoted(self::SOLD_STATUSES).')'
            .self::excluding($excludedFamilies, 'AND')
            .' GROUP BY tree.root',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $revenue = [];

        foreach ($rows as $row) {
            $revenue[(int) $row['root']] = (float) $row['revenue'];
        }

        return $revenue;
    }

    /**
     * The visible top-level categories, for the filter and the settings.
     *
     * @return array<int, string> family id => title, by position
     */
    public function familyTitles(string $locale): array
    {
        $rows = $this->rows(
            'SELECT c.id, COALESCE(NULLIF(i18n.title, \'\'), NULLIF(fr.title, \'\'), CONCAT(\'#\', c.id)) AS title'
            .' FROM category c'
            .' LEFT JOIN category_i18n i18n ON i18n.id = c.id AND i18n.locale = :locale'
            .' LEFT JOIN category_i18n fr ON fr.id = c.id AND fr.locale = \'fr_FR\''
            .' WHERE c.parent = 0 AND c.visible = 1 ORDER BY c.position, c.id',
            ['locale' => $locale],
        );

        $titles = [];

        foreach ($rows as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        return $titles;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, array{title: string, ref: string, variants: int, watched: int, collectable: int}>
     */
    public function products(array $productIds, string $locale): array
    {
        if ($productIds === []) {
            return [];
        }

        $ids = implode(',', array_map('intval', $productIds));
        // The interface locale first, French as the catalogue's own language.
        $rows = $this->rows(
            'SELECT p.id, p.ref,'
            .' COALESCE(NULLIF(i18n.title, \'\'), NULLIF(fr.title, \'\'), p.ref) AS title,'
            .' (SELECT COUNT(*) FROM product_sale_elements pse WHERE pse.product_id = p.id) AS variants,'
            .' (SELECT COUNT(*) FROM pw_product_identity w WHERE w.product_id = p.id AND w.monitored = 1) AS watched,'
            .' (SELECT COUNT(*) FROM pw_product_identity c WHERE c.product_id = p.id AND c.monitored = 1 AND c.gtin_valid = 1 AND c.gtin_shared = 0) AS collectable'
            .' FROM product p'
            .' LEFT JOIN product_i18n i18n ON i18n.id = p.id AND i18n.locale = :locale'
            .' LEFT JOIN product_i18n fr ON fr.id = p.id AND fr.locale = \'fr_FR\''
            .' WHERE p.id IN ('.$ids.')',
            ['locale' => $locale],
        );

        $products = [];

        foreach ($rows as $row) {
            $products[(int) $row['id']] = [
                'title' => (string) $row['title'],
                'ref' => (string) $row['ref'],
                'variants' => (int) $row['variants'],
                'watched' => (int) $row['watched'],
                'collectable' => (int) $row['collectable'],
            ];
        }

        return $products;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, list<string>> product id => the EAN codes of its variants, as typed in the catalogue
     */
    public function eanCodes(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $codes = [];

        foreach ($this->rows('SELECT product_id, ean_code FROM product_sale_elements WHERE product_id IN ('.implode(',', array_map('intval', $productIds)).')', []) as $row) {
            $codes[(int) $row['product_id']][] = (string) $row['ean_code'];
        }

        return $codes;
    }

    /**
     * @param array<string, string|float> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = Propel::getConnection('TheliaMain')->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int> $families ids, cast here: they come from the settings
     */
    private static function excluding(array $families, string $keyword = 'WHERE'): string
    {
        $ids = array_values(array_filter(array_map('intval', $families), static fn (int $id): bool => $id > 0));

        return $ids === [] ? '' : ' '.$keyword.' tree.root NOT IN ('.implode(',', $ids).')';
    }

    /**
     * Constants of this code only, never user input.
     *
     * @param list<string> $values
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".addslashes($value)."'", $values));
    }
}
