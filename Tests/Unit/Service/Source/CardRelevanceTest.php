<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Source\CardRelevance;

/**
 * Card titles are those of live Google Shopping answers (2026-10-09) to
 * searches for our GTINs.
 */
final class CardRelevanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cards(): iterable
    {
        yield 'right card, reworded' => ['Blouson Helstons Indy cuir noir', 'Blouson Cuir Moto Rag Helstons Indy', true];
        yield 'model name only, with punctuation' => ['Casque Nexx X.WED3 Keyo blanc jaune mat', 'Casque Nexx X.WED3 Keyo', true];
        yield 'unknown GTIN: a console controller' => ['Casque Bell Custom 500 Gloss Black', 'Manette PS5 DualSense Sony', false];
        yield 'shared trade word only' => ['Casque Bell Custom 500 Gloss Black', 'Casque Sony WH-CH520', false];
        yield 'shared English colour only' => ['Casque Bell Custom 500 Gloss Black', 'Apple iPhone 16 128GB Black', false];
        yield 'shared colour and material only' =>['Blouson Helstons Indy cuir noir', 'Veste cuir noir femme', false];
        yield 'no title on our side' => ['', 'Blouson Cuir Moto Rag Helstons Indy', false];
    }

    #[DataProvider('cards')]
    public function testOnlyADistinctiveSharedWordJustifiesASellersLookup(string $ours, string $card, bool $expected): void
    {
        self::assertSame($expected, (new CardRelevance(new TextNormalizer()))->isPlausible($ours, $card));
    }
}
