<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

use PriceWatch\Model\PwJevJudgment;
use PriceWatch\Model\PwJevJudgmentQuery;

/**
 * Jev behind a cache keyed on (state hash, question, question version).
 *
 * The same facts give the same verdict, so a verdict already paid for is
 * reused, and the gap screen does not change its mind from one day to the
 * next unless a fact changed. Only the questions missing from the cache are
 * sent, together, in one request.
 */
final readonly class CachedJev
{
    public function __construct(
        private JevClient $client,
        private OfferState $offerState,
    ) {
    }

    /**
     * @param array<string, mixed>       $state
     * @param array<string, JevQuestion> $questions
     *
     * @return array{answers: array<string, JevAnswer>, requests: int, cacheHits: int, inputTokens: int, costUsd: float}
     *
     * @throws JevException
     */
    public function ask(array $state, array $questions): array
    {
        $hash = $this->offerState->hash($state);
        $answers = [];
        $missing = [];

        foreach ($questions as $id => $question) {
            $cached = PwJevJudgmentQuery::create()
                ->filterByStateHash($hash)
                ->filterByQuestionId($question->id)
                ->filterByQuestionVersion($question->version)
                ->findOne();

            if ($cached === null) {
                $missing[$id] = $question;
            } else {
                $answers[$id] = $this->fromRow($cached);
            }
        }

        $result = ['answers' => $answers, 'requests' => 0, 'cacheHits' => \count($answers), 'inputTokens' => 0, 'costUsd' => 0.0];

        if ($missing === []) {
            return $result;
        }

        $asked = $this->client->ask($state, $missing);
        $share = 1 / \count($missing);

        foreach ($missing as $id => $question) {
            $answer = $asked['answers'][$id];
            $this->store($hash, $question, $answer, $asked['model'], (int) round($asked['inputTokens'] * $share), $asked['costUsd'] * $share);
            $result['answers'][$id] = $answer;
        }

        $result['requests'] = 1;
        $result['inputTokens'] = $asked['inputTokens'];
        $result['costUsd'] = $asked['costUsd'];

        return $result;
    }

    private function store(string $hash, JevQuestion $question, JevAnswer $answer, ?string $model, int $inputTokens, float $costUsd): void
    {
        (new PwJevJudgment())
            ->setStateHash($hash)
            ->setQuestionId($question->id)
            ->setQuestionVersion($question->version)
            ->setAnswerType($answer->type)
            // DECIMAL columns take strings, never floats.
            ->setValueNum(\is_float($answer->value) ? number_format($answer->value, 6, '.', '') : null)
            ->setValueText(\is_string($answer->value) ? mb_substr($answer->value, 0, 40) : null)
            ->setBand($answer->band)
            ->setConfidence($answer->confidence === null ? null : number_format($answer->confidence, 5, '.', ''))
            ->setProbabilitiesJson($answer->probabilities === [] ? null : json_encode($answer->probabilities, \JSON_THROW_ON_ERROR))
            ->setModelReturned($model === null ? null : mb_substr($model, 0, 40))
            ->setInputTokens($inputTokens)
            ->setCostUsd(number_format($costUsd, 8, '.', ''))
            ->save();
    }

    private function fromRow(PwJevJudgment $row): JevAnswer
    {
        $probabilities = json_decode((string) $row->getProbabilitiesJson(), true);

        return (new JevAnswer(
            (string) $row->getAnswerType(),
            $row->getValueText() ?? (float) $row->getValueNum(),
            (string) $row->getBand(),
            $row->getConfidence() === null ? null : (float) $row->getConfidence(),
            \is_array($probabilities) ? $probabilities : [],
        ))->cached();
    }
}
