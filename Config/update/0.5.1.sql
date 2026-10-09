-- The products of a running collect pass, each with where it stands, listed
-- under the comparator's progress bar.
ALTER TABLE `pw_collect_run`
    ADD COLUMN `progress_items` TEXT NULL AFTER `progress_total`;
