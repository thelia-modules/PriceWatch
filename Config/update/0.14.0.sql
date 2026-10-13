-- Each offer keeps the price of the pair's reading of an earlier day, any
-- source, and the change since (PriceChange): what the comparator and the
-- admin home show as a competitor's price moves (asked 2026-10-13).
ALTER TABLE `pw_competitor_offer`
    ADD COLUMN `previous_price` DECIMAL(16,6) NULL AFTER `last_seen_at`,
    ADD COLUMN `previous_price_day` DATE NULL AFTER `previous_price`,
    ADD COLUMN `price_change_pct` DECIMAL(9,3) NULL AFTER `previous_price_day`;

-- The offers already read: from the history kept since 2026-10-12.
UPDATE `pw_competitor_offer` o
    JOIN (
        SELECT cur.`id`,
            (SELECT q.`price` FROM `pw_price_quote` q JOIN `pw_competitor_offer` o2 ON o2.`id` = q.`offer_id`
             WHERE o2.`competitor_id` = cur.`competitor_id` AND o2.`product_sale_elements_id` = cur.`product_sale_elements_id`
               AND q.`price` IS NOT NULL AND q.`collected_day` < DATE(cur.`last_seen_at`)
             ORDER BY q.`collected_day` DESC, q.`collected_at` DESC LIMIT 1) AS `prev_price`,
            (SELECT q.`collected_day` FROM `pw_price_quote` q JOIN `pw_competitor_offer` o2 ON o2.`id` = q.`offer_id`
             WHERE o2.`competitor_id` = cur.`competitor_id` AND o2.`product_sale_elements_id` = cur.`product_sale_elements_id`
               AND q.`price` IS NOT NULL AND q.`collected_day` < DATE(cur.`last_seen_at`)
             ORDER BY q.`collected_day` DESC, q.`collected_at` DESC LIMIT 1) AS `prev_day`
        FROM `pw_competitor_offer` cur
        WHERE cur.`last_seen_at` IS NOT NULL
    ) p ON p.`id` = o.`id`
SET o.`previous_price` = p.`prev_price`,
    o.`previous_price_day` = p.`prev_day`,
    o.`price_change_pct` = IF(p.`prev_price` > 0 AND o.`last_price` IS NOT NULL, ROUND((o.`last_price` - p.`prev_price`) / p.`prev_price` * 100, 3), NULL)
WHERE p.`prev_price` IS NOT NULL;
