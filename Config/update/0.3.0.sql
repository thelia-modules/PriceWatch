-- Tokens and cost of the reasoning model (competitor suggestions), next to Jev and DataForSEO.
ALTER TABLE `pw_collect_run`
    ADD COLUMN `llm_model` VARCHAR(100) NULL AFTER `dataforseo_cost_usd`,
    ADD COLUMN `llm_prompt_tokens` INTEGER DEFAULT 0 AFTER `llm_model`,
    ADD COLUMN `llm_completion_tokens` INTEGER DEFAULT 0 AFTER `llm_prompt_tokens`,
    ADD COLUMN `llm_cost_usd` DECIMAL(12,8) NULL AFTER `llm_completion_tokens`;
