-- Where a running pass stands (step, done out of total), so the back office
-- can show a progress bar while it runs.
ALTER TABLE `pw_collect_run`
    ADD COLUMN `progress_step` VARCHAR(30) NULL AFTER `last_target_key`,
    ADD COLUMN `progress_done` INTEGER DEFAULT 0 AFTER `progress_step`,
    ADD COLUMN `progress_total` INTEGER DEFAULT 0 AFTER `progress_done`;
