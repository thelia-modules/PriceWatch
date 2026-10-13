-- Competitor product pages tied to our products, read directly without
-- Google (Service/Direct).
CREATE TABLE IF NOT EXISTS `pw_competitor_page`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `competitor_id` INTEGER NOT NULL,
    `product_id` INTEGER NOT NULL,
    `url` TEXT NOT NULL,
    `url_key` CHAR(40) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'candidate' NOT NULL,
    `confirmed_by` VARCHAR(20),
    `discovered_by` VARCHAR(20),
    `last_fetched_at` TIMESTAMP NULL,
    `last_outcome` VARCHAR(30),
    `last_error` VARCHAR(255),
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_page_competitor_url` (`competitor_id`, `url_key`),
    INDEX `idx_pw_page_product_status` (`product_id`, `status`),
    CONSTRAINT `fk_pw_page_competitor`
        FOREIGN KEY (`competitor_id`)
        REFERENCES `pw_competitor` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_pw_page_product`
        FOREIGN KEY (`product_id`)
        REFERENCES `product` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;
