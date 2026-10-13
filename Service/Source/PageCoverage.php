<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Direct\PageRepository;
use Propel\Runtime\Propel;

/**
 * The products Google Shopping has nothing new to tell about: every watched
 * competitor Google showed selling them has a confirmed page for them, read
 * straight and free by pricewatch:direct (asked 2026-10-13: direct first,
 * DataForSEO only where no page is known).
 *
 * Google is still asked every REDISCOVER_DAYS, for the sellers that appeared
 * since. An offer Jev or a human rejected was another article: that seller
 * does not need a page.
 */
final readonly class PageCoverage
{
    public const REDISCOVER_DAYS = 30;

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
        // One name per use: native prepared statements refuse a repeated one.
        $params = [
            'watched_page' => CompetitorStatus::Watched->value,
            'watched_offer' => CompetitorStatus::Watched->value,
            'confirmed_page' => PageRepository::CONFIRMED,
            'confirmed_other' => PageRepository::CONFIRMED,
            'since' => \DateTimeImmutable::createFromInterface($now)->modify('-'.self::REDISCOVER_DAYS.' days')->format('Y-m-d H:i:s'),
        ];

        $rows = $this->fetch(
            "SELECT s.product_id
            FROM (
                SELECT product_id FROM pw_product_identity
                WHERE product_id IN ($in)
                GROUP BY product_id
                HAVING MAX(last_collected_at) >= :since
            ) s
            JOIN pw_competitor_page pg ON pg.product_id = s.product_id AND pg.status = :confirmed_page
            JOIN pw_competitor c ON c.id = pg.competitor_id AND c.status = :watched_page
            WHERE NOT EXISTS (
                SELECT 1
                FROM pw_competitor_offer o
                JOIN product_sale_elements pse ON pse.id = o.product_sale_elements_id
                JOIN pw_competitor oc ON oc.id = o.competitor_id AND oc.status = :watched_offer
                WHERE pse.product_id = s.product_id
                  AND o.offer_key LIKE 'shopping:%'
                  AND o.match_state <> 'rejected'
                  AND NOT EXISTS (
                      SELECT 1 FROM pw_competitor_page p2
                      WHERE p2.product_id = s.product_id AND p2.competitor_id = o.competitor_id AND p2.status = :confirmed_other
                  )
            )
            GROUP BY s.product_id",
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
