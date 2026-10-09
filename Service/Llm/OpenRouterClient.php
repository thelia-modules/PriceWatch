<?php

declare(strict_types=1);

namespace PriceWatch\Service\Llm;

use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\ReasoningEffort;
use PriceWatch\Service\Settings\SecretName;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The reasoning model behind competitor search.
 *
 * Request and response shapes follow openrouter.ai/docs, read 2026-10-09:
 * POST /api/v1/chat/completions, `reasoning.effort`, the `web` plugin with
 * citations returned as `annotations` of type `url_citation`,
 * GET /api/v1/key and GET /api/v1/models.
 */
final readonly class OpenRouterClient
{
    public const BASE_URL = 'https://openrouter.ai/api/v1';

    private const WEB_MAX_RESULTS = 10;

    /** Reasoning plus web search routinely takes more than a minute. */
    private const COMPLETION_MAX_SECONDS = 240;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PriceWatchSettings $settings,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     *
     * @throws LlmException
     */
    public function complete(array $messages, ?string $webSearchPrompt = null): LlmAnswer
    {
        $model = $this->settings->llmModel() ?? throw LlmException::missingModel();
        $body = ['model' => $model, 'messages' => $messages];

        $effort = $this->settings->reasoningEffort();

        // Left out entirely rather than sent as "none": a model without
        // reasoning support then behaves exactly as if asked plainly.
        if ($effort !== ReasoningEffort::None) {
            $body['reasoning'] = ['effort' => $effort->value];
        }

        if ($this->settings->webSearchEnabled()) {
            $plugin = ['id' => 'web', 'max_results' => self::WEB_MAX_RESULTS];

            if ($webSearchPrompt !== null) {
                $plugin['search_prompt'] = $webSearchPrompt;
            }

            $body['plugins'] = [$plugin];
        }

        $payload = $this->request('POST', '/chat/completions', ['json' => $body, 'max_duration' => self::COMPLETION_MAX_SECONDS]);
        $message = $payload['choices'][0]['message'] ?? null;

        if (!\is_array($message) || !\is_string($message['content'] ?? null)) {
            throw LlmException::malformed('no message content');
        }

        return new LlmAnswer(
            content: $message['content'],
            reasoning: \is_string($message['reasoning'] ?? null) ? $message['reasoning'] : null,
            citations: $this->citations($message['annotations'] ?? []),
            model: \is_string($payload['model'] ?? null) ? $payload['model'] : $model,
            promptTokens: (int) ($payload['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($payload['usage']['completion_tokens'] ?? 0),
            // Always in the response since OpenRouter made usage accounting
            // automatic (docs read 2026-10-09).
            costUsd: is_numeric($payload['usage']['cost'] ?? null) ? (float) $payload['usage']['cost'] : null,
        );
    }

    /**
     * @return array{label: string, usage: ?float, limitRemaining: ?float}
     *
     * @throws LlmException
     */
    public function keyInfo(): array
    {
        $payload = $this->request('GET', '/key');
        // Documented fields; whether they sit under "data" is not stated, so
        // both shapes are accepted.
        $data = \is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        return [
            'label' => \is_string($data['label'] ?? null) ? $data['label'] : '',
            'usage' => is_numeric($data['usage'] ?? null) ? (float) $data['usage'] : null,
            'limitRemaining' => is_numeric($data['limit_remaining'] ?? null) ? (float) $data['limit_remaining'] : null,
        ];
    }

    /**
     * @return list<array{id: string, name: string, reasoning: bool}>
     *
     * @throws LlmException
     */
    public function listModels(): array
    {
        $payload = $this->request('GET', '/models');
        $models = [];

        foreach (\is_array($payload['data'] ?? null) ? $payload['data'] : [] as $model) {
            if (!\is_array($model) || !\is_string($model['id'] ?? null)) {
                continue;
            }

            $parameters = \is_array($model['supported_parameters'] ?? null) ? $model['supported_parameters'] : [];

            $models[] = [
                'id' => $model['id'],
                'name' => \is_string($model['name'] ?? null) ? $model['name'] : $model['id'],
                'reasoning' => \in_array('reasoning', $parameters, true),
            ];
        }

        usort($models, static fn (array $a, array $b): int => [$b['reasoning'], $a['id']] <=> [$a['reasoning'], $b['id']]);

        return $models;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws LlmException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $key = $this->settings->secret(SecretName::OpenrouterApiKey) ?? throw LlmException::missingKey();

        try {
            $response = $this->httpClient->request($method, self::BASE_URL.$path, $options + [
                'auth_bearer' => $key,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $error) {
            throw new LlmException('OpenRouter could not be reached: '.$error::class.'.', 0, $error);
        }

        $payload = json_decode($content, true);

        if ($status >= 400) {
            $providerMessage = \is_array($payload) ? (string) ($payload['error']['message'] ?? '') : '';

            throw LlmException::http($status, $providerMessage);
        }

        if (!\is_array($payload)) {
            throw LlmException::malformed('not JSON');
        }

        return $payload;
    }

    /**
     * @return list<array{url: string, title: string}>
     */
    private function citations(mixed $annotations): array
    {
        $citations = [];

        foreach (\is_array($annotations) ? $annotations : [] as $annotation) {
            $url = $annotation['url_citation']['url'] ?? null;

            if (($annotation['type'] ?? null) === 'url_citation' && \is_string($url)) {
                $citations[$url] = [
                    'url' => $url,
                    'title' => (string) ($annotation['url_citation']['title'] ?? ''),
                ];
            }
        }

        return array_values($citations);
    }
}
