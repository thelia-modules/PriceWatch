-- Google Shopping is searched by title first; a GTIN is searched only when
-- the title leads to no card of ours, and a GTIN Google answered with no
-- card of ours is not searched again for a while (ShoppingCollector).
ALTER TABLE `pw_product_identity`
    ADD COLUMN `gtin_unknown_at` TIMESTAMP NULL AFTER `last_collected_at`;
