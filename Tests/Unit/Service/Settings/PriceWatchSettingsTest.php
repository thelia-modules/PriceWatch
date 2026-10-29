<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Settings\InvalidSettingException;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Settings\SecretSource;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;

final class PriceWatchSettingsTest extends TestCase
{
    private const KEY = 'sk-or-v1-0123456789abcdef0123456789abcdef';

    private InMemoryConfigStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigStore();
    }

    public function testStoredKeyReadsBackButIsNeverStoredInClear(): void
    {
        $settings = $this->settings();
        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);

        self::assertSame(self::KEY, $settings->secret(SecretName::OpenrouterApiKey));
        self::assertSame(SecretSource::Admin, $settings->secretSource(SecretName::OpenrouterApiKey));

        foreach ($this->store->values as $key => $value) {
            self::assertStringNotContainsString(self::KEY, $value, 'module_config entry '.$key.' holds the key in clear');
        }
    }

    public function testSurroundingWhitespaceFromAPasteIsTrimmed(): void
    {
        $settings = $this->settings();
        $settings->storeSecret(SecretName::TypesafeApiKey, '  '.self::KEY."\n");

        self::assertSame(self::KEY, $settings->secret(SecretName::TypesafeApiKey));
    }

    public function testServerEnvironmentWinsOverTheBackOffice(): void
    {
        $settings = $this->settings(['OPENROUTER_API_KEY' => 'from-environment']);
        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);

        self::assertSame('from-environment', $settings->secret(SecretName::OpenrouterApiKey));
        self::assertSame(SecretSource::Environment, $settings->secretSource(SecretName::OpenrouterApiKey));
    }

    public function testMissingKeyIsReportedAsMissing(): void
    {
        $settings = $this->settings();

        self::assertNull($settings->secret(SecretName::TypesafeApiKey));
        self::assertSame(SecretSource::Missing, $settings->secretSource(SecretName::TypesafeApiKey));
        self::assertFalse($settings->secretSource(SecretName::TypesafeApiKey)->isUsable());
    }

    public function testKeySealedUnderAPreviousAppSecretIsReportedUnreadable(): void
    {
        $this->settings('previous-secret')->storeSecret(SecretName::TypesafeApiKey, self::KEY);
        $settings = $this->settings();

        self::assertNull($settings->secret(SecretName::TypesafeApiKey));
        self::assertSame(SecretSource::Unreadable, $settings->secretSource(SecretName::TypesafeApiKey));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'inner space' => ['sk-or v1-abc'];
        yield 'inner line break' => ["sk-or\nv1-abc"];
        yield 'tab' => ["sk-or\tv1"];
        yield 'too long' => [str_repeat('a', 513)];
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeyIsRefusedWithoutEchoingIt(string $value): void
    {
        try {
            $this->settings()->storeSecret(SecretName::OpenrouterApiKey, $value);
            self::fail('a malformed key must be refused');
        } catch (InvalidSettingException $error) {
            self::assertSame('openrouter_api_key', $error->setting);
            if (trim($value) !== '') {
                self::assertStringNotContainsString(trim($value), $error->getMessage());
            }
        }

        self::assertSame('', $this->store->get('openrouter_api_key'), 'nothing is written on refusal');
    }

    public function testClearingAKeyRemovesItAndItsTimestamp(): void
    {
        $settings = $this->settings();
        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);
        self::assertNotNull($settings->secretUpdatedAt(SecretName::OpenrouterApiKey));

        $settings->clearSecret(SecretName::OpenrouterApiKey);

        self::assertNull($settings->secret(SecretName::OpenrouterApiKey));
        self::assertNull($settings->secretUpdatedAt(SecretName::OpenrouterApiKey));
    }

    public function testJevProviderDefaultsToTypesafeAndPicksTheMatchingKey(): void
    {
        $settings = $this->settings();

        self::assertSame(JevProvider::Typesafe, $settings->jevProvider());
        self::assertFalse($settings->isJevReady());

        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);
        self::assertFalse($settings->isJevReady(), 'an OpenRouter key does not make TypeSafe ready');

        $settings->setJevProvider(JevProvider::Openrouter);
        self::assertTrue($settings->isJevReady());
        self::assertSame('https://openrouter.ai/api/v1/systemone', $settings->jevProvider()->endpoint());
    }

    public function testDataforseoLoginFromEnvironmentWins(): void
    {
        $settings = $this->settings(['DATAFORSEO_USERNAME' => 'ops@example.com']);
        $settings->setDataforseoLogin('admin@example.com');

        self::assertSame('ops@example.com', $settings->dataforseoLogin());
        self::assertTrue($settings->dataforseoLoginFromEnvironment());
    }

    public function testDataforseoLoginWithSpacesIsRefused(): void
    {
        $this->expectException(InvalidSettingException::class);

        $this->settings()->setDataforseoLogin('admin @example.com');
    }

    public function testTheMinimumMarginDefaultsTo20AndReadsADecimalComma(): void
    {
        $settings = $this->settings();
        self::assertSame(20.0, $settings->minimumMarginPct());

        $settings->setMinimumMarginPct('17,5');
        self::assertSame(17.5, $settings->minimumMarginPct());

        $settings->setMinimumMarginPct('');
        self::assertSame(20.0, $settings->minimumMarginPct(), 'emptied: back to the default');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMargins(): iterable
    {
        yield 'negative' => ['-5'];
        yield 'the whole price' => ['90'];
        yield 'not a number' => ['vingt'];
    }

    #[DataProvider('refusedMargins')]
    public function testAMarginOutsideZeroTo90IsRefused(string $value): void
    {
        $this->expectException(InvalidSettingException::class);

        $this->settings()->setMinimumMarginPct($value);
    }

    /**
     * @param array<string, string>|string $environmentOrSecret
     */
    private function settings(array|string $environmentOrSecret = []): PriceWatchSettings
    {
        $appSecret = \is_string($environmentOrSecret) ? $environmentOrSecret : 'current-secret';
        $environment = \is_array($environmentOrSecret) ? $environmentOrSecret : [];

        return new PriceWatchSettings($this->store, new SecretBox($appSecret), new ArrayServerEnvironment($environment));
    }
}
