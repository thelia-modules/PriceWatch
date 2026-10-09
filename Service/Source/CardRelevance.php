<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Normalizer\TextNormalizer;

/**
 * Cheap gate before paying for a sellers lookup.
 *
 * Observed live (2026-10-09): a GTIN Google does not know still brings back
 * cards, unrelated ones. The GTIN of a Bell helmet returned a PS5 controller
 * and a Sony headset. A card is worth a lookup only if its title shares at
 * least one distinctive word with ours: a brand or a model name, not
 * "casque", "cuir" or "noir", which every card in the trade carries.
 *
 * This only spares money on the absurd. Deciding that the card is our
 * product remains Jev's job, in pricewatch:judge.
 */
final readonly class CardRelevance
{
    private const GENERIC_WORDS = [
        'blouson', 'veste', 'casque', 'gants', 'gant', 'bottes', 'botte', 'baskets', 'chaussures', 'pantalon', 'jean',
        'gilet', 'combinaison', 'sac', 'moto', 'motard', 'scooter', 'cuir', 'textile', 'tissu', 'mesh', 'air', 'ete',
        'hiver', 'homme', 'femme', 'lady', 'enfant', 'mixte', 'noir', 'blanc', 'rouge', 'bleu', 'gris', 'marron', 'vert',
        'jaune', 'orange', 'rose', 'violet', 'beige', 'camel', 'kaki', 'mat', 'brillant', 'taille', 'pour', 'avec', 'sans',
        'black', 'white', 'red', 'blue', 'grey', 'gray', 'green', 'yellow', 'brown', 'pink', 'silver', 'gold', 'gloss',
        'matt', 'matte', 'helmet', 'jacket', 'gloves', 'boots',
        'the', 'and', 'les', 'des', 'une', 'neuf', 'jet', 'integral', 'modulable',
    ];

    public function __construct(
        private TextNormalizer $text,
    ) {
    }

    public function isPlausible(string $ourTitle, string $cardTitle): bool
    {
        return array_intersect($this->distinctiveWords($ourTitle), $this->distinctiveWords($cardTitle)) !== [];
    }

    /**
     * @return list<string>
     */
    private function distinctiveWords(string $title): array
    {
        $words = explode(' ', $this->text->normalize($title));

        return array_values(array_filter(
            $words,
            static fn (string $word): bool => \strlen($word) >= 3 && !\in_array($word, self::GENERIC_WORDS, true),
        ));
    }
}
