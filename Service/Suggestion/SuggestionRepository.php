<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Comparator\PricePosition;
use PriceWatch\Service\Competitor\CompetitorStatus;
use Propel\Runtime\Propel;

/**
 * The three reasons to collect a product now rather than when the rotation
 * reaches it: it sells, we were dearer last time, or what a competitor
 * showed is getting old. One grouped query each, product ids out.
 */
final readonly class SuggestionRepository
{
    /** Orders that are sales: paid, being prepared, sent. Not cancelled, refunded or unpaid. */
    public const SOLD_STATUSES = ['paid', 'processing', 'sent'];

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
     * Products a whitelisted competitor was found selling, last collected
     * before $before: what they showed may no longer hold.
     *
     * @return array<int, \DateTimeImmutable> product id => last collected, oldest first
     */
    public function stale(\DateTimeInterface $before, int $limit): array
    {
        $rows = $this->rows(
            'SELECT i.product_id, MAX(i.last_collected_at) AS last_collected'
            .' FROM pw_product_identity i'
            .' JOIN (SELECT DISTINCT pse.product_id FROM pw_competitor_offer o'
            .' JOIN product_sale_elements pse ON pse.id = o.product_sale_elements_id'
            .' JOIN pw_competitor c ON c.id = o.competitor_id AND c.status = :watched'
            .' WHERE o.active = 1 AND o.match_state IN ('.self::quoted(ComparatorView::MATCHED_STATES).')) found'
            .' ON found.product_id = i.product_id'
            .' WHERE i.monitored = 1 AND i.gtin_valid = 1 AND i.gtin_shared = 0'
            .' GROUP BY i.product_id HAVING MAX(i.last_collected_at) < :before'
            .' ORDER BY last_collected ASC, i.product_id LIMIT '.max(1, $limit),
            ['watched' => CompetitorStatus::Watched->value, 'before' => $before->format('Y-m-d H:i:s')],
        );

        $stale = [];

        foreach ($rows as $row) {
            $stale[(int) $row['product_id']] = new \DateTimeImmutable((string) $row['last_collected']);
        }

        return $stale;
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
     * Constants of this code only, never user input.
     *
     * @param list<string> $values
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".addslashes($value)."'", $values));
    }
}
