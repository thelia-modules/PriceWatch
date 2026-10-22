-- Scheduled passes (asked 2026-10-20): each product gets a tier, flagship
-- every day, base every week, simple every 15 days. No row means base, so
-- the products already watched need nothing here.
CREATE TABLE IF NOT EXISTS `pw_product_schedule`
(
    `product_id` INTEGER NOT NULL,
    `tier` VARCHAR(16) DEFAULT 'base' NOT NULL,
    `scheduled_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`product_id`),
    CONSTRAINT `fk_pw_schedule_product`
        FOREIGN KEY (`product_id`)
        REFERENCES `product` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;
