<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use Propel\Runtime\Propel;

/**
 * The reasons to add a product to the watch now: it sells (over the month,
 * 90 days or the year), or it is on a deep promotion. One grouped query
 * each, product ids out; then what the catalogue says of them. A product
 * already watched is never suggested: the rotation and the watched tab
 * collect it (asked 2026-10-20).
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
     * The rank is the product's among every product its family sold, watched
     * ones included; only the lines are left to the products not watched.
     * An offline product is no line and no rank (asked 2026-10-20): left out
     * here, it took a family's line before the catalogue signals dropped it.
     *
     * @param list<int> $excludedFamilies
     *
     * @return array<int, array{quantity: int, revenue: float, rank: int}> product id => units, revenue excluding tax, rank in its family
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
            .' JOIN product p ON p.ref = op.product_ref AND p.visible = 1'
            .' WHERE o.created_at >= :since AND os.code IN ('.self::quoted(self::SOLD_STATUSES).')'
            .' GROUP BY p.id),'
            .' ranked AS (SELECT sold.*, tree.root, ROW_NUMBER() OVER (PARTITION BY tree.root ORDER BY sold.revenue DESC, sold.product_id) AS family_rank'
            .' FROM sold'
            .' JOIN product_category pc ON pc.product_id = sold.product_id AND pc.default_category = 1'
            .' JOIN tree ON tree.id = pc.category_id'
            .self::excluding($excludedFamilies).'),'
            .' unwatched AS (SELECT ranked.*, ROW_NUMBER() OVER (PARTITION BY ranked.root ORDER BY ranked.family_rank) AS position'
            .' FROM ranked WHERE '.self::notWatched('ranked.product_id').')'
            .' SELECT product_id, quantity, revenue, family_rank FROM unwatched WHERE position <= '.max(1, $perFamily),
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $sellers = [];

        foreach ($rows as $row) {
            $sellers[(int) $row['product_id']] = ['quantity' => (int) $row['quantity'], 'revenue' => (float) $row['revenue'], 'rank' => (int) $row['family_rank']];
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
            .' AND '.self::notWatched('pse.product_id')
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
     * @return array<int, array{title: string, ref: string, variants: int}>
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
            .' (SELECT COUNT(*) FROM product_sale_elements pse WHERE pse.product_id = p.id) AS variants'
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
     * Left out before each family is ranked, so a watched product takes no
     * family's line. A paused product is not watched: collecting resumes it.
     */
    private static function notWatched(string $productIdColumn): string
    {
        return 'NOT EXISTS (SELECT 1 FROM pw_product_identity w WHERE w.product_id = '.$productIdColumn.' AND w.monitored = 1)';
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
