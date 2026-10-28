<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

use PriceWatch\Service\Ai\ExternalSpend;
use PriceWatch\Service\Ai\SpendRefusedException;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AiTokenUsage;
use Thelia\Domain\Ai\AsAiUsage;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jev's typed judgment endpoint, reached directly or through OpenRouter.
 *
 * Request and answer shapes follow the jev-seo client, which ran against the
 * live service: body {state, model, questions}; answer {answers, usage,
 * model}; noul answers a probability, choice and score add a confidence and
 * a probability per option (jevseo/jev.py:355-436, and a recorded
 * audit.json). Independent questions on one state share one request: that is
 * the main cost lever.
 *
 * Jev is a typed endpoint of its own, not a chat: it stays a client of the
 * module and is not routed through the gateway of the core. Its cost goes to
 * the usage log all the same ({@see ExternalSpend}), and the budget of the
 * usage applies before each call. What it receives is facts about a product
 * and an offer (titles, sizes, prices, seller names), no personal data.
 */
#[AsAiUsage(
    id: self::USAGE,
    purpose: 'Judges whether a competitor offer sells the same product and variant as ours, and whether its price is comparable.',
    audience: AiAudience::System,
    tier: AiTier::Fast,
)]
final readonly class JevClient
{
    public const USAGE = 'pricewatch.offer_judgement';

    public const MODEL = 'jev-latest';

    /** USD per million input tokens; output is free (jevseo/jev.py:25, 2026-09-20). */
    public const USD_PER_MILLION_INPUT_TOKENS = 0.042;

    private const CEILING_FLOOR_TOKENS = 8000;
    private const RETRYABLE = [429, 502, 503, 529];
    private const ATTEMPTS = 5;
    private const MAX_RETRY_WAIT = 30;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep injected so tests never wait
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private PriceWatchSettings $settings,
        private ExternalSpend $spend,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param array<string, mixed>        $state     facts only; collected text is data, never instructions
     * @param array<string, JevQuestion>  $questions keyed by question id
     *
     * @return array{answers: array<string, JevAnswer>, inputTokens: int, costUsd: float, model: ?string}
     *
     * @throws JevException
     */
    public function ask(array $state, array $questions): array
    {
        if ($questions === []) {
            return ['answers' => [], 'inputTokens' => 0, 'costUsd' => 0.0, 'model' => null];
        }

        $provider = $this->settings->jevProvider();
        $key = $this->settings->secret($provider->secret()) ?? throw new JevException('No API key is configured for Jev.');

        $body = [
            'state' => $state,
            'model' => self::MODEL,
            'questions' => array_map(static fn (JevQuestion $question): array => $question->toRequest(), $questions),
        ];

        $spendProvider = $provider === JevProvider::Openrouter ? ExternalSpend::OPENROUTER : ExternalSpend::TYPESAFE;

        try {
            // Output is free. Jev adds its own instructions to the body (967 to 2 350 tokens
            // measured on bodies of about 1.5 kB), so the ceiling never falls under a floor.
            $this->spend->authorize(self::USAGE, $spendProvider, self::costOf(max(self::CEILING_FLOOR_TOKENS, (int) ceil(\strlen((string) json_encode($body)) / 2))));
        } catch (SpendRefusedException $refusal) {
            throw new JevSpendRefusedException($refusal->getMessage(), 0, $refusal);
        }

        $settled = false;

        try {
            $started = hrtime(true);
            $payload = $this->send($provider->endpoint(), $key, $body);
            $elapsed = intdiv(hrtime(true) - $started, 1_000_000);
            $inputTokens = (int) ($payload['usage']['input_tokens'] ?? 0);

            if ($inputTokens === 0) {
                // Keep the cost ledger honest when usage is missing, as jev-seo
                // does: a conservative estimate rather than zero.
                $inputTokens = (int) ceil(\strlen((string) json_encode($body)) / 3);
            }

            // Billed as soon as answered, whatever the answer turns out to be worth.
            $costUsd = self::costOf($inputTokens);
            $this->spend->record(self::USAGE, $spendProvider, self::MODEL, $costUsd, new AiTokenUsage($inputTokens, 0), $elapsed);
            $settled = true;

            $answers = \is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
            $missing = array_diff(array_keys($questions), array_keys($answers));

            if ($missing !== []) {
                throw new JevException('Jev left questions unanswered: '.implode(', ', $missing).'.');
            }

            $validated = [];

            foreach ($questions as $id => $question) {
                $validated[$id] = $this->validate($question, $answers[$id]);
            }

            return [
                'answers' => $validated,
                'inputTokens' => $inputTokens,
                'costUsd' => $costUsd,
                'model' => \is_string($payload['model'] ?? null) ? $payload['model'] : null,
            ];
        } catch (JevException $failure) {
            // Nothing was billed (the request failed): the budget reserved for the call is settled.
            if (!$settled) {
                $this->spend->fail(self::USAGE, $spendProvider, self::MODEL);
            }

            throw $failure;
        }
    }

    private static function costOf(int $inputTokens): float
    {
        return $inputTokens / 1_000_000 * self::USD_PER_MILLION_INPUT_TOKENS;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     *
     * @throws JevException
     */
    private function send(string $endpoint, string $key, array $body): array
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'auth_bearer' => $key,
                    'json' => $body,
                    'timeout' => 90,
                ]);
                $status = $response->getStatusCode();
                $content = $response->getContent(false);
                $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            } catch (ExceptionInterface $error) {
                if ($attempt < self::ATTEMPTS) {
                    ($this->sleep)(2 ** ($attempt - 1));
                    continue;
                }

                throw new JevException('Jev could not be reached ('.$error::class.').', 0, $error);
            }

            if (\in_array($status, self::RETRYABLE, true) && $attempt < self::ATTEMPTS) {
                ($this->sleep)(min(self::MAX_RETRY_WAIT, is_numeric($retryAfter) ? (int) $retryAfter : 2 ** ($attempt - 1)));
                continue;
            }

            $payload = json_decode($content, true);

            if ($status !== 200) {
                $message = \is_array($payload) ? (string) ($payload['error']['message'] ?? $payload['error'] ?? '') : '';

                throw new JevException(match ($status) {
                    401, 403 => 'Jev refused the API key.',
                    402 => 'No credit left for Jev.',
                    default => \sprintf('Jev answered HTTP %d%s', $status, $message === '' ? '.' : ': '.mb_substr($message, 0, 200)),
                });
            }

            if (!\is_array($payload)) {
                throw new JevException('Jev answered something that is not JSON.');
            }

            return $payload;
        }

        throw new JevException('Jev stayed unavailable after several attempts.');
    }

    /**
     * Strict, as in jevseo/jev.py:411-436: anything outside the asked shape
     * is a failure, never a guess.
     *
     * @throws JevException
     */
    private function validate(JevQuestion $question, mixed $answer): JevAnswer
    {
        if (!\is_array($answer)) {
            throw new JevException(\sprintf('Malformed answer to "%s".', $question->id));
        }

        $probabilities = \is_array($answer['probabilities'] ?? null)
            ? array_map(static fn ($p): float => (float) $p, $answer['probabilities'])
            : [];
        $confidence = isset($answer['confidence']) && is_numeric($answer['confidence']) ? (float) $answer['confidence'] : null;

        return match ($question->type) {
            JevQuestion::NOUL => $this->noul($question, $answer),
            JevQuestion::CHOICE => $this->choice($question, $answer, $confidence, $probabilities),
            JevQuestion::SCORE => $this->score($question, $answer, $confidence, $probabilities),
            default => throw new JevException('Unknown question type '.$question->type),
        };
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function noul(JevQuestion $question, array $answer): JevAnswer
    {
        $value = $answer['noul'] ?? null;

        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value > 1.0) {
            throw new JevException(\sprintf('Answer to "%s" is not a probability.', $question->id));
        }

        $value = (float) $value;
        $band = match (true) {
            $value >= JevAnswer::DECISIVE => JevAnswer::YES,
            $value <= JevAnswer::NOUL_NO => JevAnswer::NO,
            default => JevAnswer::REVIEW,
        };

        // A noul carries no confidence field: the probability is the verdict.
        return new JevAnswer(JevQuestion::NOUL, $value, $band, null, []);
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, float> $probabilities
     */
    private function choice(JevQuestion $question, array $answer, ?float $confidence, array $probabilities): JevAnswer
    {
        $choice = $answer['choice'] ?? null;

        if (!\is_string($choice) || !\array_key_exists($choice, $question->criteria)) {
            throw new JevException(\sprintf('Answer to "%s" is not one of the offered options.', $question->id));
        }

        $band = $confidence !== null && $confidence >= JevAnswer::DECISIVE ? JevAnswer::ACT : JevAnswer::REVIEW;

        return new JevAnswer(JevQuestion::CHOICE, $choice, $band, $confidence, $probabilities);
    }

    /**
     * Scores come back continuous (an expectation over the levels). Decisive
     * means most of the probability mass sits on one side of the middle, as
     * jev-seo judges it.
     *
     * @param array<string, mixed> $answer
     * @param array<string, float> $probabilities
     */
    private function score(JevQuestion $question, array $answer, ?float $confidence, array $probabilities): JevAnswer
    {
        $top = \count($question->criteria) - 1;
        $value = $answer['score'] ?? null;

        if (!is_numeric($value) || (float) $value < 0.0 || (float) $value > $top) {
            throw new JevException(\sprintf('Answer to "%s" is outside the score range.', $question->id));
        }

        $middle = $top / 2;
        $low = $high = 0.0;

        foreach ($probabilities as $level => $probability) {
            if ((float) $level < $middle) {
                $low += $probability;
            } elseif ((float) $level > $middle) {
                $high += $probability;
            }
        }

        $decisive = $probabilities !== []
            ? max($low, $high) >= JevAnswer::DECISIVE
            : ($confidence !== null && $confidence >= JevAnswer::DECISIVE);

        return new JevAnswer(JevQuestion::SCORE, (float) $value, $decisive ? JevAnswer::ACT : JevAnswer::REVIEW, $confidence, $probabilities);
    }
}
