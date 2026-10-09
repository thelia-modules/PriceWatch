
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- pw_product_identity
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_product_identity`;

CREATE TABLE `pw_product_identity`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `product_sale_elements_id` INTEGER NOT NULL,
    `product_id` INTEGER NOT NULL,
    `gtin_raw` VARCHAR(255),
    `gtin` VARCHAR(14),
    `gtin_valid` TINYINT(4) DEFAULT 0,
    `gtin_shared` TINYINT(4) DEFAULT 0,
    `gtin_source` VARCHAR(20),
    `mpn_raw` VARCHAR(255),
    `mpn_normalized` VARCHAR(64),
    `manufacturer_ref` VARCHAR(64),
    `brand_label` VARCHAR(128),
    `brand_normalized` VARCHAR(128),
    `size_label` VARCHAR(32),
    `size_normalized` VARCHAR(32),
    `color_label` VARCHAR(48),
    `color_normalized` VARCHAR(48),
    `variant_axis_json` TEXT,
    `feature_digest` TEXT,
    `target_price` DECIMAL(16,6),
    `currency_code` VARCHAR(3) DEFAULT 'EUR',
    `price_vat_included` TINYINT(4) DEFAULT 1,
    `monitored` TINYINT(4) DEFAULT 1,
    `locale` VARCHAR(5) DEFAULT 'fr_FR',
    `notes` VARCHAR(512),
    `source_checksum` VARCHAR(40),
    `import_batch_id` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_identity_pse` (`product_sale_elements_id`),
    INDEX `idx_pw_identity_gtin` (`gtin`),
    INDEX `idx_pw_identity_mpn_brand` (`mpn_normalized`, `brand_normalized`),
    INDEX `idx_pw_identity_monitored` (`monitored`, `product_id`),
    CONSTRAINT `fk_pw_identity_pse`
        FOREIGN KEY (`product_sale_elements_id`)
        REFERENCES `product_sale_elements` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_competitor
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_competitor`;

CREATE TABLE `pw_competitor`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `domain` VARCHAR(190) NOT NULL,
    `display_name` VARCHAR(190),
    `status` VARCHAR(20) DEFAULT 'proposed' NOT NULL,
    `discovery_source` VARCHAR(20),
    `discovery_evidence_json` TEXT,
    `products_covered` INTEGER DEFAULT 0,
    `median_rank` DECIMAL(8,3),
    `eur_share` DECIMAL(5,3),
    `merchant_type` VARCHAR(32),
    `merchant_relevance` DECIMAL(5,3),
    `merchant_judged_at` TIMESTAMP NULL,
    `robots_allowed` TINYINT(4),
    `robots_checked_at` TIMESTAMP NULL,
    `crawl_delay_ms` INTEGER DEFAULT 2000,
    `max_pages_per_pass` INTEGER DEFAULT 200,
    `blocked_until` TIMESTAMP NULL,
    `currency_code` VARCHAR(3) DEFAULT 'EUR',
    `price_vat_included` TINYINT(4) DEFAULT 1,
    `url_pattern` VARCHAR(255),
    `scrape_profile_json` TEXT,
    `expected_method` VARCHAR(20),
    `extraction_broken_streak` INTEGER DEFAULT 0,
    `validated_by` VARCHAR(100),
    `validated_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_competitor_domain` (`domain`),
    INDEX `idx_pw_competitor_status` (`status`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_competitor_offer
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_competitor_offer`;

CREATE TABLE `pw_competitor_offer`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `competitor_id` INTEGER NOT NULL,
    `product_sale_elements_id` INTEGER NOT NULL,
    `offer_key` VARCHAR(190) NOT NULL,
    `url` TEXT,
    `remote_title` VARCHAR(255),
    `remote_brand` VARCHAR(128),
    `remote_brand_normalized` VARCHAR(128),
    `remote_gtin` VARCHAR(14),
    `remote_gtin_valid` TINYINT(4) DEFAULT 0,
    `remote_mpn_normalized` VARCHAR(64),
    `remote_variant_label` VARCHAR(190),
    `remote_condition` VARCHAR(20),
    `match_state` VARCHAR(20) DEFAULT 'candidate' NOT NULL,
    `match_method` VARCHAR(20),
    `match_reason` VARCHAR(40),
    `judgment_json` TEXT,
    `identity_fingerprint` VARCHAR(40),
    `judged_fingerprint` VARCHAR(40),
    `last_price` DECIMAL(16,6),
    `last_total_price` DECIMAL(16,6),
    `last_currency_code` VARCHAR(3),
    `last_our_price` DECIMAL(16,6),
    `last_delta` DECIMAL(16,6),
    `last_delta_pct` DECIMAL(8,3),
    `last_comparable` TINYINT(4) DEFAULT 0,
    `exclusion_reason` VARCHAR(40),
    `first_seen_at` TIMESTAMP NULL,
    `last_seen_at` TIMESTAMP NULL,
    `active` TINYINT(4) DEFAULT 1,
    `reviewed_by` VARCHAR(100),
    `reviewed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_offer_competitor_key` (`competitor_id`, `offer_key`),
    INDEX `idx_pw_offer_screen` (`active`, `last_comparable`, `last_delta_pct`),
    INDEX `idx_pw_offer_competitor_state` (`competitor_id`, `match_state`),
    INDEX `idx_pw_offer_pse` (`product_sale_elements_id`, `match_state`),
    INDEX `idx_pw_offer_state` (`match_state`, `last_seen_at`),
    CONSTRAINT `fk_pw_offer_competitor`
        FOREIGN KEY (`competitor_id`)
        REFERENCES `pw_competitor` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE,
    CONSTRAINT `fk_pw_offer_pse`
        FOREIGN KEY (`product_sale_elements_id`)
        REFERENCES `product_sale_elements` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_price_quote
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_price_quote`;

CREATE TABLE `pw_price_quote`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `offer_id` INTEGER NOT NULL,
    `collected_day` DATE NOT NULL,
    `source` VARCHAR(20) NOT NULL,
    `collected_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `price` DECIMAL(16,6),
    `list_price` DECIMAL(16,6),
    `shipping_price` DECIMAL(16,6),
    `shipping_included` TINYINT(4) DEFAULT 0,
    `total_price` DECIMAL(16,6),
    `currency_code` VARCHAR(3) DEFAULT 'EUR',
    `price_vat_included` TINYINT(4) DEFAULT 1,
    `availability` VARCHAR(32),
    `condition_code` VARCHAR(20),
    `our_price` DECIMAL(16,6),
    `delta` DECIMAL(16,6),
    `delta_pct` DECIMAL(8,3),
    `comparable` TINYINT(4) DEFAULT 0,
    `exclusion_reason` VARCHAR(40),
    `extraction_method` VARCHAR(20),
    `extraction_confidence` DECIMAL(4,3),
    `raw_payload_json` TEXT,
    `evidence_excerpt` TEXT,
    `collect_run_id` INTEGER,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_quote_offer_day_source` (`offer_id`, `collected_day`, `source`),
    INDEX `idx_pw_quote_day` (`collected_day`),
    CONSTRAINT `fk_pw_quote_offer`
        FOREIGN KEY (`offer_id`)
        REFERENCES `pw_competitor_offer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_price_daily
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_price_daily`;

CREATE TABLE `pw_price_daily`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `offer_id` INTEGER NOT NULL,
    `collected_day` DATE NOT NULL,
    `min_price` DECIMAL(16,6),
    `max_price` DECIMAL(16,6),
    `avg_price` DECIMAL(16,6),
    `close_price` DECIMAL(16,6),
    `samples` INTEGER DEFAULT 0,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_daily_offer_day` (`offer_id`, `collected_day`),
    CONSTRAINT `fk_pw_daily_offer`
        FOREIGN KEY (`offer_id`)
        REFERENCES `pw_competitor_offer` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_jev_judgment
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_jev_judgment`;

CREATE TABLE `pw_jev_judgment`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `state_hash` VARCHAR(40) NOT NULL,
    `question_id` VARCHAR(40) NOT NULL,
    `question_version` INTEGER NOT NULL,
    `answer_type` VARCHAR(10),
    `value_num` DECIMAL(10,6),
    `value_text` VARCHAR(40),
    `band` VARCHAR(10),
    `confidence` DECIMAL(6,5),
    `probabilities_json` TEXT,
    `model_returned` VARCHAR(40),
    `input_tokens` INTEGER DEFAULT 0,
    `cost_usd` DECIMAL(12,8),
    `expires_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_pw_judgment_state_question` (`state_hash`, `question_id`, `question_version`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_collect_run
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_collect_run`;

CREATE TABLE `pw_collect_run`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `pass_name` VARCHAR(30) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'started' NOT NULL,
    `started_at` TIMESTAMP NULL,
    `finished_at` TIMESTAMP NULL,
    `targets_planned` INTEGER DEFAULT 0,
    `offers_seen` INTEGER DEFAULT 0,
    `quotes_written` INTEGER DEFAULT 0,
    `offers_matched` INTEGER DEFAULT 0,
    `offers_review` INTEGER DEFAULT 0,
    `offers_rejected` INTEGER DEFAULT 0,
    `jev_requests` INTEGER DEFAULT 0,
    `jev_cache_hits` INTEGER DEFAULT 0,
    `jev_failed` INTEGER DEFAULT 0,
    `jev_input_tokens` INTEGER DEFAULT 0,
    `jev_cost_usd` DECIMAL(12,8),
    `dataforseo_tasks` INTEGER DEFAULT 0,
    `dataforseo_cost_usd` DECIMAL(12,8),
    `llm_model` VARCHAR(100),
    `llm_prompt_tokens` INTEGER DEFAULT 0,
    `llm_completion_tokens` INTEGER DEFAULT 0,
    `llm_cost_usd` DECIMAL(12,8),
    `http_errors` INTEGER DEFAULT 0,
    `pages_fetched` INTEGER DEFAULT 0,
    `last_target_key` VARCHAR(190),
    `errors_json` TEXT,
    `dry_run` TINYINT(4) DEFAULT 0,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_pw_run_pass_started` (`pass_name`, `started_at`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_import_batch
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_import_batch`;

CREATE TABLE `pw_import_batch`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(255),
    `source_checksum` VARCHAR(40),
    `locale` VARCHAR(5) DEFAULT 'fr_FR',
    `dry_run` TINYINT(4) DEFAULT 0,
    `status` VARCHAR(20) DEFAULT 'started' NOT NULL,
    `lines_total` INTEGER DEFAULT 0,
    `lines_created` INTEGER DEFAULT 0,
    `lines_updated` INTEGER DEFAULT 0,
    `lines_unchanged` INTEGER DEFAULT 0,
    `lines_rejected` INTEGER DEFAULT 0,
    `competitors_seeded` INTEGER DEFAULT 0,
    `started_at` TIMESTAMP NULL,
    `finished_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- pw_import_reject
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `pw_import_reject`;

CREATE TABLE `pw_import_reject`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `batch_id` INTEGER NOT NULL,
    `line_number` INTEGER NOT NULL,
    `error_code` VARCHAR(40) NOT NULL,
    `message` VARCHAR(255),
    `raw_line` TEXT,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_pw_reject_batch` (`batch_id`),
    CONSTRAINT `fk_pw_reject_batch`
        FOREIGN KEY (`batch_id`)
        REFERENCES `pw_import_batch` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
