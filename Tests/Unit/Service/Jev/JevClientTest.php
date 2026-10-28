<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Jev;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Ai\ExternalSpend;
use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\JevClient;
use PriceWatch\Service\Jev\JevException;
use PriceWatch\Service\Jev\JevSpendRefusedException;
use PriceWatch\Service\Jev\JevQuestion;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use Thelia\Domain\Ai\AiJurisdiction;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use PriceWatch\Tests\Doubles\RecordingCostRecorder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JevClientTest extends TestCase
{
    private const KEY = 'sk-or-v1-never-shown';

    /** @var list<array{url: string, options: array<string, mixed>}> */
    private array $sent = [];

    /** @var list<int> */
    private array $slept = [];

    private RecordingCostRecorder $costs;

    protected function setUp(): void
    {
        $this->costs = new RecordingCostRecorder();
    }

    public function testRequestShapeAndEndpointFollowTheProvider(): void
    {
        $client = $this->client([$this->answers(['same' => ['noul' => 0.93]])]);

        $client->ask(['ours' => ['title' => 'Blouson']], ['same' => $this->noul('same')]);

        $body = json_decode((string) $this->sent[0]['options']['body'], true);
        self::assertSame('https://openrouter.ai/api/v1/systemone', $this->sent[0]['url']);
        self::assertSame('jev-latest', $body['model']);
        self::assertSame(['ours' => ['title' => 'Blouson']], $body['state']);
        self::assertSame('noul', $body['questions']['same']['type']);
        self::assertSame(['true' => 'yes', 'false' => 'no'], $body['questions']['same']['criteria']);
        self::assertContains('Authorization: Bearer '.self::KEY, $this->sent[0]['options']['headers']);
    }

    public function testTheCostIsAskedBeforeTheCallAndWrittenAfterIt(): void
    {
        $client = $this->client([$this->answers(['same' => ['noul' => 0.93]])]);

        $client->ask(['ours' => ['title' => 'Blouson']], ['same' => $this->noul('same')]);

        self::assertSame(['authorize:openrouter', 'record:openrouter'], $this->costs->events);
        self::assertSame('pricewatch.offer_judgement', $this->costs->authorized[0]['usage']);
        self::assertSame(AiJurisdiction::OutsideEuropeanUnion, $this->costs->authorized[0]['jurisdiction']);
        self::assertGreaterThanOrEqual(1200 / 1_000_000 * 0.042, (float) $this->costs->authorized[0]['maximum'], 'the ceiling covers the call');
        $cost = $this->costs->recorded[0];
        self::assertSame('0.00005040', $cost->cost, '1 200 tokens at 0.042 USD per million, never a float written as 5.04E-5');
        self::assertSame('jev-latest', $cost->operation);
        self::assertSame(1200, $cost->tokenUsage?->inputTokens);
        self::assertNotNull($cost->latencyMilliseconds);
    }

    public function testTheTypesafeRouteIsDeclaredAsTypesafe(): void
    {
        $client = $this->client([$this->answers(['same' => ['noul' => 0.93]])], static function (PriceWatchSettings $settings): void {
            $settings->setJevProvider(JevProvider::Typesafe);
            $settings->storeSecret(SecretName::TypesafeApiKey, 'ts-key');
        });

        $client->ask([], ['same' => $this->noul('same')]);

        self::assertSame('typesafe', $this->costs->recorded[0]->provider);
    }

    public function testAReachedBudgetStopsTheCallBeforeAnyRequest(): void
    {
        $this->costs->budgetReached = true;

        try {
            $this->client([$this->answers(['same' => ['noul' => 0.93]])])->ask([], ['same' => $this->noul('same')]);
            self::fail('expected a refusal');
        } catch (JevSpendRefusedException $refusal) {
            self::assertStringContainsString('budget', $refusal->getMessage());
        }

        self::assertSame([], $this->sent, 'nothing was sent, nothing was spent');
        self::assertSame([], $this->costs->recorded);
    }

    public function testACallThatFailsCostsNothingInTheJournal(): void
    {
        try {
            $this->client([new MockResponse('{"error":"boom"}', ['http_code' => 400])])->ask([], ['same' => $this->noul('same')]);
            self::fail('expected a failure');
        } catch (JevException) {
        }

        self::assertSame(['authorize:openrouter'], $this->costs->events);
    }

    public function testNoulBands(): void
    {
        $client = $this->client([$this->answers([
            'high' => ['noul' => 0.80],
            'low' => ['noul' => 0.20],
            'middle' => ['noul' => 0.5],
        ])]);

        $answers = $client->ask([], ['high' => $this->noul('high'), 'low' => $this->noul('low'), 'middle' => $this->noul('middle')])['answers'];

        self::assertSame(JevAnswer::YES, $answers['high']->band);
        self::assertSame(JevAnswer::NO, $answers['low']->band);
        self::assertSame(JevAnswer::REVIEW, $answers['middle']->band);
        self::assertFalse($answers['middle']->isDecisive(), 'the middle band is never a pass');
        self::assertNull($answers['high']->confidence, 'a noul has no confidence field');
    }

    public function testChoiceIsDecisiveOnlyWithHighConfidence(): void
    {
        $question = JevQuestion::choice('price', 1, 'What is `theirs.price`?', ['comparable' => 'a', 'promotional' => 'b', 'unclear' => 'c']);
        $client = $this->client([$this->answers([
            'sure' => ['choice' => 'comparable', 'confidence' => 0.91, 'probabilities' => ['comparable' => 0.91, 'promotional' => 0.09]],
            'unsure' => ['choice' => 'promotional', 'confidence' => 0.55],
        ])]);

        $answers = $client->ask([], ['sure' => $question, 'unsure' => $question])['answers'];

        self::assertSame('comparable', $answers['sure']->value);
        self::assertSame(JevAnswer::ACT, $answers['sure']->band);
        self::assertSame(JevAnswer::REVIEW, $answers['unsure']->band);
    }

    public function testScoreDecisivenessComesFromTheMassOnOneSide(): void
    {
        $question = JevQuestion::score('relevance', 1, 'How relevant is `candidate`?', ['none', 'weak', 'good', 'strong']);
        $client = $this->client([$this->answers([
            'clear' => ['score' => 2.9, 'confidence' => 0.5, 'probabilities' => ['0' => 0.02, '1' => 0.03, '2' => 0.05, '3' => 0.90]],
            'split' => ['score' => 1.5, 'confidence' => 0.95, 'probabilities' => ['0' => 0.45, '1' => 0.05, '2' => 0.05, '3' => 0.45]],
        ])]);

        $answers = $client->ask([], ['clear' => $question, 'split' => $question])['answers'];

        self::assertSame(JevAnswer::ACT, $answers['clear']->band, 'mass on one side decides, not the confidence field');
        self::assertSame(JevAnswer::REVIEW, $answers['split']->band);
        self::assertSame(2.9, $answers['clear']->value);
    }

    public function testAnswersOutsideTheAskedShapeAreRejected(): void
    {
        foreach ([
            ['same' => ['noul' => 1.4]],
            ['same' => ['noul' => 'yes']],
            ['other' => ['noul' => 0.9]],
        ] as $answers) {
            try {
                $this->client([$this->answers($answers)])->ask([], ['same' => $this->noul('same')]);
                self::fail('expected a rejection for '.json_encode($answers));
            } catch (JevException) {
                self::addToAssertionCount(1);
            }
        }

        $choice = JevQuestion::choice('price', 1, 'x', ['comparable' => 'a', 'unclear' => 'b']);

        $this->expectException(JevException::class);
        $this->client([$this->answers(['price' => ['choice' => 'invented', 'confidence' => 1.0]])])->ask([], ['price' => $choice]);
    }

    public function testChoiceWithoutAWayOutIsRefusedAtDesignTime(): void
    {
        $this->expectException(\LogicException::class);

        JevQuestion::choice('forced', 1, 'x', ['a' => 'first', 'b' => 'second']);
    }

    public function testRateLimitIsRetriedWithTheAnnouncedDelay(): void
    {
        $client = $this->client([
            new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after' => '7']]),
            new MockResponse('{}', ['http_code' => 503]),
            $this->answers(['same' => ['noul' => 0.9]]),
        ]);

        $result = $client->ask([], ['same' => $this->noul('same')]);

        self::assertSame(JevAnswer::YES, $result['answers']['same']->band);
        self::assertSame([7, 2], $this->slept);
    }

    public function testRefusedKeyIsReportedWithoutEchoingIt(): void
    {
        $client = $this->client([new MockResponse('{"error": {"message": "bad key '.self::KEY.'"}}', ['http_code' => 401])]);

        try {
            $client->ask([], ['same' => $this->noul('same')]);
            self::fail('expected a refusal');
        } catch (JevException $error) {
            self::assertSame('Jev refused the API key.', $error->getMessage());
        }
    }

    public function testCostCountsInputTokensOnly(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'answers' => ['same' => ['noul' => 0.9]],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 999_999],
            'model' => 'typesafe/jev-1.13',
        ]))]);

        $result = $client->ask([], ['same' => $this->noul('same')]);

        self::assertSame(1_000_000, $result['inputTokens']);
        self::assertEqualsWithDelta(0.042, $result['costUsd'], 1e-9);
        self::assertSame('typesafe/jev-1.13', $result['model']);
    }

    public function testMissingKeyFailsBeforeAnyCall(): void
    {
        $client = $this->client([], static fn (PriceWatchSettings $settings) => $settings->clearSecret(SecretName::OpenrouterApiKey));

        try {
            $client->ask([], ['same' => $this->noul('same')]);
            self::fail('expected a missing key error');
        } catch (JevException) {
            self::assertSame([], $this->sent);
        }
    }

    private function noul(string $id): JevQuestion
    {
        return JevQuestion::noul($id, 1, 'Is `ours` the same as `theirs`?', 'yes', 'no');
    }

    /**
     * @param array<string, mixed> $answers
     */
    private function answers(array $answers): MockResponse
    {
        return new MockResponse((string) json_encode(['answers' => $answers, 'usage' => ['input_tokens' => 1200], 'model' => 'typesafe/jev-test']));
    }

    /**
     * @param list<MockResponse>                         $responses
     * @param (callable(PriceWatchSettings): mixed)|null $configure
     */
    private function client(array $responses, ?callable $configure = null): JevClient
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setJevProvider(JevProvider::Openrouter);
        $settings->storeSecret(SecretName::OpenrouterApiKey, self::KEY);

        if ($configure !== null) {
            $configure($settings);
        }

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new JevClient($http, $settings, new ExternalSpend($this->costs), function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }
}
