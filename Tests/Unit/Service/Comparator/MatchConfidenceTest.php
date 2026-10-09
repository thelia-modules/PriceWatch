<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Comparator;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Comparator\MatchConfidence;

final class MatchConfidenceTest extends TestCase
{
    public function testTheWeakestAnswerSetsTheLevel(): void
    {
        // Recorded on the Helstons offer: product 0.76 kept it out of the
        // decisive band, however sure the other two answers were.
        $confidence = MatchConfidence::of('jev', $this->judgment(0.76, 0.93, 'comparable', 0.89));

        self::assertSame(MatchConfidence::MEDIUM, $confidence['level']);
        self::assertSame(76, $confidence['score']);
        self::assertSame([76, 93, 89], array_column($confidence['gauges'], 'percent'));
        self::assertSame('comparable', $confidence['gauges'][2]['choice'], 'the price gauge shows the confidence of the option chosen');
    }

    public function testDecisiveAnswersGiveAHighLevel(): void
    {
        self::assertSame(MatchConfidence::HIGH, MatchConfidence::of('jev', $this->judgment(0.92, 0.89, 'comparable', 0.99))['level']);
        self::assertSame(MatchConfidence::HIGH, MatchConfidence::of('jev', $this->judgment(0.80, 0.80, 'comparable', 0.80))['level'], 'the decisive band starts at 0.80');
    }

    public function testAWrongSizeIsALowLevel(): void
    {
        self::assertSame(MatchConfidence::LOW, MatchConfidence::of('jev', $this->judgment(0.94, 0.03, 'comparable', 0.54))['level']);
    }

    public function testAHumanDecisionOutranksTheScore(): void
    {
        $confidence = MatchConfidence::of('human', $this->judgment(0.76, 0.93, 'comparable', 0.89));

        self::assertSame(MatchConfidence::HUMAN, $confidence['level']);
        self::assertCount(3, $confidence['gauges'], 'what Jev said stays visible');
    }

    public function testAHumanMatchAgainstAClearNoIsFlagged(): void
    {
        // Recorded on the Stormer offer: confirmed by hand, "same size" at 4 %.
        $confirmed = MatchConfidence::of('human', $this->judgment(0.78, 0.04, 'comparable', 0.71), 'matched_manual');
        $rejected = MatchConfidence::of('human', $this->judgment(0.78, 0.04, 'comparable', 0.71), 'rejected');

        self::assertSame(['same_variant'], $confirmed['disagreements']);
        self::assertSame([], $rejected['disagreements'], 'excluding what Jev said no to is agreement');
        self::assertSame([], MatchConfidence::of('human', $this->judgment(0.78, 0.40, 'comparable', 0.71), 'matched_manual')['disagreements'], 'an uncertain answer is no disagreement');
    }

    public function testRulesAndUnjudgedOffersHaveNoScore(): void
    {
        self::assertSame(MatchConfidence::RULE, MatchConfidence::of('code', [])['level']);
        self::assertSame(['level' => MatchConfidence::NONE, 'score' => null, 'gauges' => [], 'disagreements' => []], MatchConfidence::of(null, []));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function judgment(float $product, float $variant, string $price, float $confidence): array
    {
        $band = static fn (float $value): string => $value >= 0.8 ? 'yes' : ($value <= 0.2 ? 'no' : 'review');

        return [
            'same_product' => ['band' => $band($product), 'value' => $product, 'confidence' => null, 'probabilities' => []],
            'same_variant' => ['band' => $band($variant), 'value' => $variant, 'confidence' => null, 'probabilities' => []],
            'price_comparability' => ['band' => $confidence >= 0.8 ? 'act' : 'review', 'value' => $price, 'confidence' => $confidence, 'probabilities' => []],
        ];
    }
}
