-- Jev confirms competitor pages that show no GTIN (PageConfirmation): its
-- answers stay on the page, shown on the offers read on it.
ALTER TABLE `pw_competitor_page`
    ADD COLUMN `judgment_json` TEXT NULL AFTER `last_error`;
