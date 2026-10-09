<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Llm;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Llm\LlmException;
use PriceWatch\Service\Llm\OpenRouterClient;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\ReasoningEffort;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenRouterClientTest extends TestCase
{
    private const KEY = 'sk-or-v1-secret-value-never-shown';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    public function testCompletionSendsModelReasoningAndWebSearch(): void
    {
        $client = $this->client([$this->completion('{"competitors": []}')]);

        $client->complete([['role' => 'user', 'content' => 'hello']], 'custom search prompt');

        $request = $this->sent[0];
        $body = json_decode((string) $request['options']['body'], true);

        self::assertSame('POST', $request['method']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $request['url']);
        self::assertSame('vendor/model', $body['model']);
        self::assertSame(['effort' => 'high'], $body['reasoning']);
        self::assertSame([['id' => 'web', 'max_results' => 10, 'search_prompt' => 'custom search prompt']], $body['plugins']);
        self::assertContains('Authorization: Bearer '.self::KEY, $request['options']['headers']);
    }

    public function testReasoningIsLeftOutWhenSetToNone(): void
    {
        $client = $this->client([$this->completion('{}')], static function (PriceWatchSettings $settings): void {
            $settings->setReasoningEffort(ReasoningEffort::None);
            $settings->setWebSearchEnabled(false);
        });

        $client->complete([['role' => 'user', 'content' => 'hello']]);
        $body = json_decode((string) $this->sent[0]['options']['body'], true);

        self::assertArrayNotHasKey('reasoning', $body, 'no reasoning field at all, not "none"');
        self::assertArrayNotHasKey('plugins', $body);
    }

    public function testAnswerCarriesContentReasoningAndDeduplicatedCitations(): void
    {
        $client = $this->client([new MockResponse(json_encode([
            'model' => 'vendor/model-2026',
            'choices' => [['message' => [
                'content' => '{"competitors": []}',
                'reasoning' => 'thinking...',
                'annotations' => [
                    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://a.example/p', 'title' => 'A']],
                    ['type' => 'url_citation', 'url_citation' => ['url' => 'https://a.example/p', 'title' => 'A again']],
                    ['type' => 'something_else'],
                ],
            ]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ]) ?: '')]);

        $answer = $client->complete([['role' => 'user', 'content' => 'hello']]);

        self::assertSame('{"competitors": []}', $answer->content);
        self::assertSame('thinking...', $answer->reasoning);
        self::assertSame([['url' => 'https://a.example/p', 'title' => 'A again']], $answer->citations);
        self::assertSame('vendor/model-2026', $answer->model);
        self::assertSame(120, $answer->promptTokens);
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $client = $this->client([], static fn (PriceWatchSettings $settings) => $settings->clearSecret(SecretName::OpenrouterApiKey));

        try {
            $client->complete([['role' => 'user', 'content' => 'hello']]);
            self::fail('expected a missing key error');
        } catch (LlmException $error) {
            self::assertStringContainsString('No OpenRouter API key', $error->getMessage());
        }

        self::assertSame([], $this->sent);
    }

    public function testMissingModelFailsBeforeAnyCall(): void
    {
        $client = $this->client([], static fn (PriceWatchSettings $settings) => $settings->setLlmModel(''));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('No model is selected');

        $client->complete([['role' => 'user', 'content' => 'hello']]);
    }

    public function testRefusedKeyIsReportedWithoutEverEchoingIt(): void
    {
        $client = $this->client([new MockResponse('{"error": {"message": "Invalid key '.self::KEY.'"}}', ['http_code' => 401])]);

        try {
            $client->complete([['role' => 'user', 'content' => 'hello']]);
            self::fail('expected an error');
        } catch (LlmException $error) {
            self::assertSame('OpenRouter refused the API key.', $error->getMessage());
            self::assertStringNotContainsString(self::KEY, $error->getMessage());
        }
    }

    public function testOtherErrorsCarryTheStatus(): void
    {
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 402]),
            new MockResponse('{"error": {"message": "upstream down"}}', ['http_code' => 503]),
        ]);

        foreach (['no credit left', 'HTTP 503: upstream down'] as $expected) {
            try {
                $client->complete([['role' => 'user', 'content' => 'hello']]);
                self::fail('expected an error');
            } catch (LlmException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
    }

    public function testAnswerWithoutContentIsRejected(): void
    {
        $client = $this->client([new MockResponse('{"choices": []}')]);

        $this->expectException(LlmException::class);

        $client->complete([['role' => 'user', 'content' => 'hello']]);
    }

    public function testModelListPutsReasoningModelsFirst(): void
    {
        $client = $this->client([new MockResponse(json_encode(['data' => [
            ['id' => 'b/plain', 'name' => 'Plain', 'supported_parameters' => ['temperature']],
            ['id' => 'a/thinker', 'name' => 'Thinker', 'supported_parameters' => ['reasoning', 'tools']],
            ['name' => 'no id, dropped'],
        ]]) ?: '')]);

        self::assertSame([
            ['id' => 'a/thinker', 'name' => 'Thinker', 'reasoning' => true],
            ['id' => 'b/plain', 'name' => 'Plain', 'reasoning' => false],
        ], $client->listModels());
    }

    public function testKeyInfoAcceptsBothDocumentedShapes(): void
    {
        $client = $this->client([
            new MockResponse('{"data": {"label": "pricewatch", "usage": 1.5, "limit_remaining": 8.5}}'),
            new MockResponse('{"label": "flat", "limit_remaining": null}'),
        ]);

        self::assertSame(['label' => 'pricewatch', 'usage' => 1.5, 'limitRemaining' => 8.5], $client->keyInfo());
        self::assertSame(['label' => 'flat', 'usage' => null, 'limitRemaining' => null], $client->keyInfo());
    }

    /**
     * @param list<MockResponse>                    $responses
     * @param (callable(PriceWatchSettings): mixed)|null $configure
     */
    private function client(array $responses, ?callable $configure = null): OpenRouterClient
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);
        $settings->setLlmModel('vendor/model');

        if ($configure !== null) {
            $configure($settings);
        }

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new OpenRouterClient($http, $settings);
    }

    private function completion(string $content): MockResponse
    {
        return new MockResponse(json_encode(['choices' => [['message' => ['content' => $content]]]]) ?: '');
    }
}
