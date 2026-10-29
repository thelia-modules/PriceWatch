<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The "Test" buttons of the settings page. Each check makes one free,
 * read-only call and reports in words a merchant can act on.
 *
 * Endpoints: OpenRouter GET /api/v1/key, used by the match checks when they
 * go through OpenRouter (openrouter.ai/docs, 2026-10-09);
 * TypeSafe GET /v1/models (jev-seo SKILL.md:174); DataForSEO
 * GET /v3/appendix/user_data, documented as free (checked 2026-10-08).
 */
final readonly class ConnectionCheck
{
    private const OPENROUTER_KEY = 'https://openrouter.ai/api/v1/key';
    private const TYPESAFE_MODELS = 'https://api.typesafe.ai/v1/models';
    private const DATAFORSEO_USER_DATA = 'https://api.dataforseo.com/v3/appendix/user_data';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PriceWatchSettings $settings,
    ) {
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function openRouter(): array
    {
        $key = $this->settings->secret(SecretName::OpenrouterApiKey);

        if ($key === null) {
            return ['ok' => false, 'message' => 'No OpenRouter API key is configured.'];
        }

        return $this->probe('OpenRouter', fn () => $this->httpClient->request('GET', self::OPENROUTER_KEY, [
            'auth_bearer' => $key,
            'timeout' => 20,
        ]));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function typesafe(): array
    {
        $key = $this->settings->secret(SecretName::TypesafeApiKey);

        if ($key === null) {
            return ['ok' => false, 'message' => 'No TypeSafe API key is configured.'];
        }

        return $this->probe('TypeSafe', fn () => $this->httpClient->request('GET', self::TYPESAFE_MODELS, [
            'auth_bearer' => $key,
            'timeout' => 20,
        ]));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function dataforseo(): array
    {
        $login = $this->settings->dataforseoLogin();
        $password = $this->settings->secret(SecretName::DataforseoPassword);

        if ($login === null || $password === null) {
            return ['ok' => false, 'message' => 'The DataForSEO login and password are both required.'];
        }

        return $this->probe('DataForSEO', fn () => $this->httpClient->request('GET', self::DATAFORSEO_USER_DATA, [
            'auth_basic' => [$login, $password],
            'timeout' => 20,
        ]));
    }

    /**
     * @param callable(): \Symfony\Contracts\HttpClient\ResponseInterface $send
     *
     * @return array{ok: bool, message: string}
     */
    private function probe(string $service, callable $send): array
    {
        try {
            $status = $send()->getStatusCode();
        } catch (ExceptionInterface $error) {
            return ['ok' => false, 'message' => $service.' could not be reached ('.$error::class.').'];
        }

        return match (true) {
            $status >= 200 && $status < 300 => ['ok' => true, 'message' => $service.' accepted the credentials.'],
            $status === 401 || $status === 403 => ['ok' => false, 'message' => $service.' refused the credentials.'],
            default => ['ok' => false, 'message' => \sprintf('%s answered HTTP %d.', $service, $status)],
        };
    }
}
