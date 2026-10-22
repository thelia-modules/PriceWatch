<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

use PriceWatch\Service\Direct\PageOutcome;
use PriceWatch\Service\Suggestion\SuggestionRepository;
use PriceWatch\Service\Watched\WatchedRepository;
use Propel\Runtime\Propel;

/**
 * The tiers of the watched products and when a scheduled pass last took
 * them (pw_product_schedule). A product without a row is a base one.
 */
final readonly class ScheduleRepository
{
    /** Stands for "never" inside GREATEST(), which NULL would swallow. */
    private const NEVER = '1000-01-01 00:00:00';

    /**
     * Watched products the shop shows: a product it no longer sells is not
     * worth paying to compare.
     *
     * @return list<ScheduledProduct>
     */
    public function watched(): array
    {
        $rows = $this->fetch(
            "SELECT i.product_id, p.ref, s.tier,
                SUM(i.monitored = 1 AND i.gtin_valid = 1 AND i.gtin_shared = 0) AS collectable,
                NULLIF(GREATEST(
                    COALESCE(MAX(i.last_collected_at), '".self::NEVER."'),
                    COALESCE(MAX(pg.last_read), '".self::NEVER."'),
                    COALESCE(s.scheduled_at, '".self::NEVER."')
                ), '".self::NEVER."') AS last_read
            FROM pw_product_identity i
            JOIN product p ON p.id = i.product_id AND p.visible = 1
            LEFT JOIN pw_product_schedule s ON s.product_id = i.product_id
            LEFT JOIN (".WatchedRepository::pagesReadSql().') pg ON pg.product_id = i.product_id
            GROUP BY i.product_id, p.ref, s.tier, s.scheduled_at
            HAVING SUM(i.monitored = 1) > 0
            ORDER BY i.product_id',
            ['read' => PageOutcome::Read->value],
        );

        return array_map(static fn (array $row): ScheduledProduct => new ScheduledProduct(
            (int) $row['product_id'],
            (string) $row['ref'],
            WatchTier::fromStored($row['tier'] === null ? null : (string) $row['tier']),
            (int) $row['collectable'],
            $row['last_read'] === null ? null : new \DateTimeImmutable((string) $row['last_read']),
        ), $rows);
    }

    public function setTier(int $productId, WatchTier $tier): void
    {
        $this->execute(
            // From product: an id that no longer exists writes nothing.
            'INSERT INTO pw_product_schedule (product_id, tier, created_at, updated_at) SELECT id, :tier, NOW(), NOW() FROM product WHERE id = :product
            ON DUPLICATE KEY UPDATE tier = VALUES(tier), updated_at = NOW()',
            ['product' => $productId, 'tier' => $tier->value],
        );
    }

    /**
     * A scheduled pass took these products, whatever it finds.
     *
     * @param list<int> $productIds
     */
    public function markScheduled(array $productIds, \DateTimeInterface $at): void
    {
        foreach ($productIds as $productId) {
            $this->execute(
                'INSERT INTO pw_product_schedule (product_id, tier, scheduled_at, created_at, updated_at) VALUES (:product, :tier, :at, NOW(), NOW())
                ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), updated_at = NOW()',
                ['product' => $productId, 'tier' => WatchTier::DEFAULT->value, 'at' => $at->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * A pass row still open from the last hours: between two commands of a
     * chain no lock is held for a second, the row tells. Older open rows are
     * passes that died, closed by the next pass of their kind.
     */
    public function hasOpenRun(\DateTimeInterface $since): bool
    {
        return (int) ($this->fetch(
            "SELECT COUNT(*) AS open FROM pw_collect_run WHERE status = 'started' AND pass_name IN ('collect', 'direct', 'judge') AND started_at >= :since",
            ['since' => $since->format('Y-m-d H:i:s')],
        )[0]['open'] ?? 0) > 0;
    }

    /**
     * Revenue excluding tax of each watched product since $since, as the
     * suggestions count it: orders paid, processing or sent.
     *
     * @param list<int> $productIds
     *
     * @return array<int, float> product id => revenue, for those that sold
     */
    public function revenues(array $productIds, \DateTimeInterface $since): array
    {
        if ($productIds === []) {
            return [];
        }

        $statuses = implode(',', array_map(static fn (string $code): string => "'".addslashes($code)."'", SuggestionRepository::SOLD_STATUSES));
        $rows = $this->fetch(
            'SELECT p.id AS product_id, SUM(op.quantity * IF(op.was_in_promo = 1, op.promo_price, op.price)) AS revenue
            FROM order_product op
            JOIN `order` o ON o.id = op.order_id
            JOIN order_status os ON os.id = o.status_id
            JOIN product p ON p.ref = op.product_ref
            WHERE o.created_at >= :since AND os.code IN ('.$statuses.')
              AND p.id IN ('.implode(',', array_map('intval', $productIds)).')
            GROUP BY p.id',
            ['since' => $since->format('Y-m-d H:i:s')],
        );
        $revenues = [];

        foreach ($rows as $row) {
            $revenues[(int) $row['product_id']] = (float) $row['revenue'];
        }

        return $revenues;
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

    /**
     * @param array<string, mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        $statement = Propel::getConnection('TheliaMain')->prepare($sql);

        foreach ($params as $name => $value) {
            $statement->bindValue(':'.$name, $value);
        }

        $statement->execute();
    }
}
