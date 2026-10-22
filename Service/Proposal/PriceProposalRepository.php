<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

use PriceWatch\Service\Comparator\ComparatorView;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Suggestion\SuggestionRepository;
use Propel\Runtime\Propel;

/**
 * What the price proposals are reckoned from, for the watched variants: the
 * purchase price of the purchase tab, the competitor offers that compare,
 * and the units sold.
 *
 * Only matches count (matched_exact, matched_judged, matched_manual): an
 * offer still to check may be another size or another article, and a price
 * is not changed on a doubt. Plain SQL, one grouped query each.
 */
final readonly class PriceProposalRepository
{
    /**
     * Created by the ClassicRide module (purchase tab). The first row of a
     * variant, lowest id, is the one the tab reads and writes.
     */
    public const PURCHASE_TABLE = 'product_sale_elements_purchase';

    /**
     * Offline products left out: no customer sees their price (asked 2026-10-20).
     *
     * @param list<int>|null $productIds null for every watched product
     *
     * @return list<array{saleElementId: int, productId: int, ref: string, size: ?string, purchase: ?string}>
     */
    public function variants(?array $productIds = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $purchase = $this->hasPurchaseTable()
            ? '(SELECT psep.purchase FROM '.self::PURCHASE_TABLE.' psep WHERE psep.product_sale_elements_id = i.product_sale_elements_id ORDER BY psep.id LIMIT 1)'
            : 'NULL';

        $rows = $this->rows(
            'SELECT i.product_sale_elements_id, i.product_id, i.size_label, pse.ref, '.$purchase.' AS purchase'
            .' FROM pw_product_identity i'
            .' JOIN product_sale_elements pse ON pse.id = i.product_sale_elements_id'
            .' JOIN product p ON p.id = i.product_id AND p.visible = 1'
            .' WHERE i.monitored = 1'
            .($productIds === null ? '' : ' AND i.product_id IN ('.self::integers($productIds).')')
            .' ORDER BY i.product_id, i.product_sale_elements_id',
        );

        return array_map(static fn (array $row): array => [
            'saleElementId' => (int) $row['product_sale_elements_id'],
            'productId' => (int) $row['product_id'],
            'ref' => (string) $row['ref'],
            'size' => $row['size_label'] === null || $row['size_label'] === '' ? null : (string) $row['size_label'],
            'purchase' => $row['purchase'] === null ? null : (string) $row['purchase'],
        ], $rows);
    }

    /**
     * The cheapest matched offer of a whitelisted competitor, in euros, for
     * each variant that has one.
     *
     * @param list<int> $saleElementIds
     *
     * @return array<int, array{price: float, competitor: string, url: ?string, seenAt: ?string, competitors: int}> by sale element id
     */
    public function cheapestOffers(array $saleElementIds): array
    {
        if ($saleElementIds === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT o.product_sale_elements_id, o.last_price, o.url, o.last_seen_at, c.domain'
            .' FROM pw_competitor_offer o'
            .' JOIN pw_competitor c ON c.id = o.competitor_id AND c.status = :watched'
            .' WHERE o.product_sale_elements_id IN ('.self::integers($saleElementIds).')'
            .' AND o.active = 1 AND o.last_comparable = 1'
            .' AND o.match_state IN ('.self::quoted(ComparatorView::MATCHED_STATES).')'
            ." AND o.last_currency_code = 'EUR' AND o.last_price > 0"
            .' ORDER BY o.product_sale_elements_id, o.last_price, o.id',
            ['watched' => CompetitorStatus::Watched->value],
        );

        $cheapest = [];
        $competitors = [];

        foreach ($rows as $row) {
            $id = (int) $row['product_sale_elements_id'];
            $competitors[$id][(string) $row['domain']] = true;

            $cheapest[$id] ??= [
                'price' => (float) $row['last_price'],
                'competitor' => (string) $row['domain'],
                'url' => $row['url'] === null ? null : (string) $row['url'],
                'seenAt' => $row['last_seen_at'] === null ? null : (string) $row['last_seen_at'],
                'competitors' => 0,
            ];
        }

        foreach ($cheapest as $id => $offer) {
            $cheapest[$id]['competitors'] = \count($competitors[$id]);
        }

        return $cheapest;
    }

    /**
     * Per variant and reading day since $since: our price including tax as
     * the readings recorded it, and the cheapest offer that compares under
     * the rules of cheapestOffers(). Our price is read from every offer of
     * the variant, a rejected one included: it is our price all the same.
     *
     * @param list<int> $saleElementIds
     *
     * @return array<int, array<string, array{ours: ?float, cheapest: ?float}>> by sale element id, then Y-m-d
     */
    public function priceHistory(array $saleElementIds, \DateTimeImmutable $since): array
    {
        if ($saleElementIds === []) {
            return [];
        }

        $rows = $this->rows(
            'SELECT o.product_sale_elements_id, q.collected_day, MAX(q.our_price) AS ours,'
            .' MIN(CASE WHEN c.status = :watched AND o.active = 1'
            .' AND o.match_state IN ('.self::quoted(ComparatorView::MATCHED_STATES).')'
            ." AND q.comparable = 1 AND q.currency_code = 'EUR' AND q.price > 0 THEN q.price END) AS cheapest"
            .' FROM pw_price_quote q'
            .' JOIN pw_competitor_offer o ON o.id = q.offer_id'
            .' JOIN pw_competitor c ON c.id = o.competitor_id'
            .' WHERE o.product_sale_elements_id IN ('.self::integers($saleElementIds).')'
            .' AND q.collected_day >= :since'
            .' GROUP BY o.product_sale_elements_id, q.collected_day',
            ['watched' => CompetitorStatus::Watched->value, 'since' => $since->format('Y-m-d')],
        );

        $history = [];

        foreach ($rows as $row) {
            $history[(int) $row['product_sale_elements_id']][(string) $row['collected_day']] = [
                'ours' => $row['ours'] === null || (float) $row['ours'] <= 0 ? null : (float) $row['ours'],
                'cheapest' => $row['cheapest'] === null ? null : (float) $row['cheapest'],
            ];
        }

        return $history;
    }

    /**
     * Units sold over the last 90 days, the same 90 days a year earlier and
     * the 90 days before, counted as the suggestions count sales (paid,
     * being prepared, sent).
     *
     * @param list<int> $productIds
     *
     * @return array<int, SalesTrend> by product id, products that sold in none of the periods left out
     */
    public function salesTrends(array $productIds, \DateTimeImmutable $now): array
    {
        if ($productIds === []) {
            return [];
        }

        $days = new \DateInterval('P'.SalesTrend::DAYS.'D');
        $periodStart = $now->sub($days);
        $periodBeforeStart = $periodStart->sub($days);
        $yearBeforeEnd = $now->modify('-1 year');
        $yearBeforeStart = $yearBeforeEnd->sub($days);
        $format = static fn (\DateTimeImmutable $date): string => $date->format('Y-m-d H:i:s');

        // order_product keeps the reference the product had when sold.
        $rows = $this->rows(
            'SELECT p.id AS product_id,'
            .' SUM(IF(o.created_at >= :period_start, op.quantity, 0)) AS units,'
            .' SUM(IF(o.created_at >= :year_before_start AND o.created_at < :year_before_end, op.quantity, 0)) AS year_before,'
            .' SUM(IF(o.created_at >= :period_before_start AND o.created_at < :period_start_again, op.quantity, 0)) AS period_before'
            .' FROM order_product op'
            .' JOIN `order` o ON o.id = op.order_id'
            .' JOIN order_status os ON os.id = o.status_id'
            .' JOIN product p ON p.ref = op.product_ref'
            .' WHERE p.id IN ('.self::integers($productIds).')'
            .' AND os.code IN ('.self::quoted(SuggestionRepository::SOLD_STATUSES).')'
            .' AND o.created_at >= :earliest AND o.created_at < :now'
            .' GROUP BY p.id',
            [
                'period_start' => $format($periodStart),
                'period_start_again' => $format($periodStart),
                'year_before_start' => $format($yearBeforeStart),
                'year_before_end' => $format($yearBeforeEnd),
                'period_before_start' => $format($periodBeforeStart),
                'earliest' => $format(min($yearBeforeStart, $periodBeforeStart)),
                'now' => $format($now),
            ],
        );

        $trends = [];

        foreach ($rows as $row) {
            $trends[(int) $row['product_id']] = new SalesTrend((int) $row['units'], (int) $row['year_before'], (int) $row['period_before']);
        }

        return $trends;
    }

    private function hasPurchaseTable(): bool
    {
        $rows = $this->rows(
            'SELECT COUNT(*) AS found FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name',
            ['name' => self::PURCHASE_TABLE],
        );

        return (int) ($rows[0]['found'] ?? 0) > 0;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters = []): array
    {
        $statement = Propel::getConnection('TheliaMain')->prepare($sql);

        if ($statement === false || !$statement->execute($parameters)) {
            throw new \RuntimeException('Price proposals: the query could not run.');
        }

        return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param list<int> $values
     */
    private static function integers(array $values): string
    {
        return implode(', ', array_map('intval', $values));
    }

    /**
     * @param list<string> $values
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $value): string => "'".addslashes($value)."'", $values));
    }
}
