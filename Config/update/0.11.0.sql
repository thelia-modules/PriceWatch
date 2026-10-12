-- Google Shopping is no longer searched by GTIN (ShoppingCollector): what
-- 0.10.0 remembered about GTINs Google did not know serves nothing.
ALTER TABLE `pw_product_identity`
    DROP COLUMN `gtin_unknown_at`;
