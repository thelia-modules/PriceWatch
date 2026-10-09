-- Jev answers behind an offer's verdict, shown as proof on the comparator screen.
ALTER TABLE `pw_competitor_offer` ADD COLUMN `judgment_json` LONGTEXT NULL AFTER `match_reason`;
