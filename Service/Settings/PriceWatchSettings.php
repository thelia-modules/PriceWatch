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

    public function __construct(
        private ConfigStore $store,
        private SecretBox $secretBox,
        private ServerEnvironment $environment,
    ) {
    }

    public function secret(SecretName $name): ?string
    {
        $fromEnvironment = $this->environment->get($name->environmentVariable());

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        $sealed = $this->store->get($name->value);

        return $sealed === '' ? null : $this->secretBox->decrypt($sealed);
    }

    public function secretSource(SecretName $name): SecretSource
    {
        if ($this->environment->get($name->environmentVariable()) !== null) {
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
        $fromEnvironment = $this->environment->get(self::DATAFORSEO_LOGIN_ENV);

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        $login = $this->store->get(self::DATAFORSEO_LOGIN);

        return $login === '' ? null : $login;
    }

    public function dataforseoLoginFromEnvironment(): bool
    {
        return $this->environment->get(self::DATAFORSEO_LOGIN_ENV) !== null;
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

    public function isJevReady(): bool
    {
        return $this->secret($this->jevProvider()->secret()) !== null;
    }

    public function isLlmReady(): bool
    {
        return $this->secret(SecretName::OpenrouterApiKey) !== null && $this->llmModel() !== null;
    }
}
