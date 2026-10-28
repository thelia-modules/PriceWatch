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
    private const LOGIN_MAX_LENGTH = 190;

    private const JEV_PROVIDER = 'jev_provider';
    private const DISCOVERY_ENABLED = 'discovery_enabled';
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
     * Off by default: the model has no web access, so its suggestions come from
     * training data (dated, shops that may not exist). Turned on by the merchant
     * once the gateway of the core has a model for the usage.
     */
    public function discoveryEnabled(): bool
    {
        return $this->store->get(self::DISCOVERY_ENABLED) === '1';
    }

    public function setDiscoveryEnabled(bool $enabled): void
    {
        $this->store->set(self::DISCOVERY_ENABLED, $enabled ? '1' : '0');
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
}
