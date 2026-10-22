<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

/**
 * Every setting the module needs, set from its back-office page.
 *
 * Secrets are write-only from the outside: secret() hands the clear value to
 * the HTTP clients that need it, and nothing in this class ever returns it for
 * display. The page shows where a key comes from and when it was saved, never
 * a single character of it.
 */
final readonly class PriceWatchSettings
{
    private const MAX_SECRET_LENGTH = 512;
    private const MODEL_PATTERN = '/^[A-Za-z0-9._\/:-]{1,128}$/';
    private const LOGIN_MAX_LENGTH = 190;

    private const JEV_PROVIDER = 'jev_provider';
    private const LLM_MODEL = 'llm_model';
    private const REASONING_EFFORT = 'llm_reasoning_effort';
    private const WEB_SEARCH = 'llm_web_search';
    private const MODEL_LIST = 'llm_model_list';
    private const DATAFORSEO_LOGIN = 'dataforseo_login';
    private const DATAFORSEO_LOGIN_ENV = 'DATAFORSEO_USERNAME';
    private const UPDATED_SUFFIX = '_updated_at';
    private const SHOP_DESCRIPTION = 'shop_description';
    private const SHOP_DESCRIPTION_MAX_LENGTH = 500;
    private const EXCLUDED_FAMILIES = 'suggestions_excluded_families';
    private const MINIMUM_MARGIN = 'proposals_minimum_margin_pct';

    /** Asked 2026-10-20: 20 % of the selling price excluding tax. */
    public const DEFAULT_MINIMUM_MARGIN_PCT = 20.0;

    public function __construct(
        private ConfigStore $store,
        private SecretBox $secretBox,
        // Not named $environment: Thelia binds that name globally to
        // %kernel.environment%, and the bind wins over the type hint.
        private ServerEnvironment $serverEnvironment,
    ) {
    }

    public function secret(SecretName $name): ?string
    {
        $fromEnvironment = $this->serverEnvironment->get($name->environmentVariable());

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        $sealed = $this->store->get($name->value);

        return $sealed === '' ? null : $this->secretBox->decrypt($sealed);
    }

    public function secretSource(SecretName $name): SecretSource
    {
        if ($this->serverEnvironment->get($name->environmentVariable()) !== null) {
            return SecretSource::Environment;
        }

        $sealed = $this->store->get($name->value);

        if ($sealed === '') {
            return SecretSource::Missing;
        }

        return $this->secretBox->decrypt($sealed) === null ? SecretSource::Unreadable : SecretSource::Admin;
    }

    public function secretUpdatedAt(SecretName $name): ?\DateTimeImmutable
    {
        $stamp = $this->store->get($name->value.self::UPDATED_SUFFIX);

        return $stamp === '' ? null : (\DateTimeImmutable::createFromFormat(\DATE_ATOM, $stamp) ?: null);
    }

    /**
     * @throws InvalidSettingException
     */
    public function storeSecret(SecretName $name, string $value): void
    {
        $value = trim($value);

        if ($value === '' || \strlen($value) > self::MAX_SECRET_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
            // The message never echoes the value: it might be a real key
            // pasted with a stray character.
            throw new InvalidSettingException($name->value, 'An API key is a single token, without spaces or line breaks.');
        }

        $this->store->set($name->value, $this->secretBox->encrypt($value));
        $this->store->set($name->value.self::UPDATED_SUFFIX, (new \DateTimeImmutable())->format(\DATE_ATOM));
    }

    public function clearSecret(SecretName $name): void
    {
        $this->store->set($name->value, '');
        $this->store->set($name->value.self::UPDATED_SUFFIX, '');
    }

    public function dataforseoLogin(): ?string
    {
        $fromEnvironment = $this->serverEnvironment->get(self::DATAFORSEO_LOGIN_ENV);

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        $login = $this->store->get(self::DATAFORSEO_LOGIN);

        return $login === '' ? null : $login;
    }

    public function dataforseoLoginFromEnvironment(): bool
    {
        return $this->serverEnvironment->get(self::DATAFORSEO_LOGIN_ENV) !== null;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setDataforseoLogin(string $login): void
    {
        $login = trim($login);

        if (mb_strlen($login) > self::LOGIN_MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $login) === 1) {
            throw new InvalidSettingException(self::DATAFORSEO_LOGIN, 'The DataForSEO login is an e-mail address, without spaces.');
        }

        $this->store->set(self::DATAFORSEO_LOGIN, $login);
    }

    public function jevProvider(): JevProvider
    {
        return JevProvider::tryFrom($this->store->get(self::JEV_PROVIDER)) ?? JevProvider::Typesafe;
    }

    public function setJevProvider(JevProvider $provider): void
    {
        $this->store->set(self::JEV_PROVIDER, $provider->value);
    }

    /**
     * No default model on purpose: naming one here would hardcode a model id
     * nobody checked. The page loads the live list from OpenRouter instead.
     */
    public function llmModel(): ?string
    {
        $model = $this->store->get(self::LLM_MODEL);

        return $model === '' ? null : $model;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setLlmModel(string $model): void
    {
        $model = trim($model);

        if ($model !== '' && preg_match(self::MODEL_PATTERN, $model) !== 1) {
            throw new InvalidSettingException(self::LLM_MODEL, 'A model id looks like vendor/model-name.');
        }

        $this->store->set(self::LLM_MODEL, $model);
    }

    public function reasoningEffort(): ReasoningEffort
    {
        // Reasoning on by default: the reflection mode is the reason this
        // model is configured at all.
        return ReasoningEffort::tryFrom($this->store->get(self::REASONING_EFFORT)) ?? ReasoningEffort::High;
    }

    public function setReasoningEffort(ReasoningEffort $effort): void
    {
        $this->store->set(self::REASONING_EFFORT, $effort->value);
    }

    /**
     * On by default: without it, suggested competitors come from the model's
     * training data, which is dated and prone to invented domains.
     */
    public function webSearchEnabled(): bool
    {
        return $this->store->get(self::WEB_SEARCH) !== '0';
    }

    public function setWebSearchEnabled(bool $enabled): void
    {
        $this->store->set(self::WEB_SEARCH, $enabled ? '1' : '0');
    }

    /**
     * @return list<array{id: string, name: string, reasoning: bool}>
     */
    public function modelList(): array
    {
        $json = $this->store->get(self::MODEL_LIST);

        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? array_values(array_filter($decoded, \is_array(...))) : [];
    }

    /**
     * @param list<array{id: string, name: string, reasoning: bool}> $models
     */
    public function storeModelList(array $models): void
    {
        $this->store->set(self::MODEL_LIST, json_encode(array_values($models), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * A sentence or two the merchant writes about the shop. Given to the
     * model as context for competitor search; never treated as instructions.
     */
    public function shopDescription(): ?string
    {
        $description = $this->store->get(self::SHOP_DESCRIPTION);

        return $description === '' ? null : $description;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setShopDescription(string $description): void
    {
        $description = trim($description);

        if (mb_strlen($description) > self::SHOP_DESCRIPTION_MAX_LENGTH) {
            throw new InvalidSettingException(self::SHOP_DESCRIPTION, \sprintf('The shop description is limited to %d characters.', self::SHOP_DESCRIPTION_MAX_LENGTH));
        }

        $this->store->set(self::SHOP_DESCRIPTION, $description);
    }

    /**
     * Top-level categories the suggestions never draw from: what no
     * competitor sells as such (unavailable items, gift cards).
     *
     * @return list<int> category ids
     */
    public function excludedFamilies(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $this->store->get(self::EXCLUDED_FAMILIES))), static fn (int $id): bool => $id > 0));
    }

    /**
     * @param list<int> $categoryIds
     */
    public function setExcludedFamilies(array $categoryIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));
        sort($ids);
        $this->store->set(self::EXCLUDED_FAMILIES, implode(',', $ids));
    }

    /**
     * The margin no price proposal goes below, in percent of the selling
     * price excluding tax: (price - purchase) / price.
     */
    public function minimumMarginPct(): float
    {
        $stored = $this->store->get(self::MINIMUM_MARGIN);

        return is_numeric($stored) ? (float) $stored : self::DEFAULT_MINIMUM_MARGIN_PCT;
    }

    /**
     * @throws InvalidSettingException
     */
    public function setMinimumMarginPct(string $value): void
    {
        $value = str_replace(',', '.', trim($value));

        if ($value === '') {
            $this->store->set(self::MINIMUM_MARGIN, '');

            return;
        }

        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value >= 90.0) {
            throw new InvalidSettingException(self::MINIMUM_MARGIN, 'The minimum margin must be a number from 0 to 89.9.');
        }

        $this->store->set(self::MINIMUM_MARGIN, (string) round((float) $value, 1));
    }

    public function isJevReady(): bool
    {
        return $this->secret($this->jevProvider()->secret()) !== null;
    }

    public function isLlmReady(): bool
    {
        return $this->secret(SecretName::OpenrouterApiKey) !== null && $this->llmModel() !== null;
    }
}
