<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\PageRepository;
use Propel\Runtime\Propel;

/**
 * The products Google Shopping is not asked about: those with a confirmed
 * page at a watched competitor, read straight and free by pricewatch:direct
 * (asked 2026-10-13: sitemap first, DataForSEO only for a product no page
 * covers).
 *
 * Google is still asked every REDISCOVER_DAYS, counted from its last search
 * or, for a product it never searched, from the first page found: the
 * sellers outside our list show up there.
 */
final readonly class PageCoverage
{
    public const REDISCOVER_DAYS = 90;

    /**
     * @param list<int> $productIds
     *
     * @return array<int, true> product id => covered
     */
    public function covered(array $productIds, \DateTimeInterface $now): array
    {
        if ($productIds === []) {
            return [];
        }

        $in = implode(',', array_map('intval', array_unique($productIds)));
        $params = [
            'watched' => CompetitorStatus::Watched->value,
            'confirmed' => PageRepository::CONFIRMED,
            'since' => \DateTimeImmutable::createFromInterface($now)->modify('-'.self::REDISCOVER_DAYS.' days')->format('Y-m-d H:i:s'),
        ];

        $rows = $this->fetch(
            "SELECT s.product_id
            FROM (
                SELECT product_id, MAX(last_collected_at) AS searched_at
                FROM pw_product_identity
                WHERE product_id IN ($in)
                GROUP BY product_id
            ) s
            JOIN pw_competitor_page pg ON pg.product_id = s.product_id AND pg.status = :confirmed
            JOIN pw_competitor c ON c.id = pg.competitor_id AND c.status = :watched
            GROUP BY s.product_id, s.searched_at
            HAVING GREATEST(COALESCE(s.searched_at, '1000-01-01 00:00:00'), MIN(pg.created_at)) >= :since",
            $params,
        );

        $covered = [];

        foreach ($rows as $row) {
            $covered[(int) $row['product_id']] = true;
        }

        return $covered;
    }

    /**
     * @param array<string, string> $params
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
