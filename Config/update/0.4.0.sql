-- When each variant was last searched, so that collection passes rotate
-- through the referential instead of always taking its first rows.
ALTER TABLE `pw_product_identity`
    ADD COLUMN `last_collected_at` TIMESTAMP NULL AFTER `import_batch_id`,
    ADD INDEX `idx_pw_identity_rotation` (`monitored`, `last_collected_at`);
