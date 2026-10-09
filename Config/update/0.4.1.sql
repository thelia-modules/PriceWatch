-- Variants collected before last_collected_at existed: the competitor offers
-- seen for them say when they were last searched. Without this they would
-- show as "never collected" next to the offers they already have.
UPDATE `pw_product_identity` i
JOIN (
    SELECT `product_sale_elements_id`, MAX(COALESCE(`last_seen_at`, `updated_at`)) AS `seen`
    FROM `pw_competitor_offer`
    GROUP BY `product_sale_elements_id`
) o ON o.`product_sale_elements_id` = i.`product_sale_elements_id`
SET i.`last_collected_at` = o.`seen`
WHERE i.`last_collected_at` IS NULL;
