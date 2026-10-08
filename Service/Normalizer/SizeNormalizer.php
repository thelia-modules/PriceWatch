<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * Motorcycle gear sizes come in incompatible notations whose numeric ranges
 * overlap: garment numbers 44-62, helmet head circumferences 53-65, boot sizes
 * 36-50, glove sizes 6-13. A bare "52" cannot be assigned to a family without
 * knowing the product type, so this normaliser refuses to guess one: numeric
 * sizes stay numeric and only compare to other numeric sizes.
 *
 * Deciding that a 52 garment number equals an M is a business call on a
 * per-manufacturer basis. Until that table is validated, such pairs fall
 * through to a Jev variant judgment, which is the safe outcome.
 */
final readonly class SizeNormalizer
{
    private const ALPHA_TOKENS = [
        'xxxs' => 'XXXS', '3xs' => 'XXXS',
        'xxs' => 'XXS', '2xs' => 'XXS',
        'xs' => 'XS',
        's' => 'S',
        'm' => 'M',
        'l' => 'L',
        'xl' => 'XL',
        'xxl' => 'XXL', '2xl' => 'XXL',
        'xxxl' => 'XXXL', '3xl' => 'XXXL',
        'xxxxl' => 'XXXXL', '4xl' => 'XXXXL',
        '5xl' => 'XXXXXL',
    ];

    private const ONE_SIZE_PHRASES = ['tu', 'taille unique', 'one size', 'onesize', 'os', 'unique'];

    private const PLAUSIBLE_MINIMUM = 2;
    private const PLAUSIBLE_MAXIMUM = 200;

    public function __construct(private TextNormalizer $textNormalizer)
    {
    }

    public function normalize(?string $raw): NormalizedSize
    {
        $folded = $this->textNormalizer->normalize($raw);

        if ($folded === '') {
            return NormalizedSize::unknown($raw);
        }

        if (\in_array($folded, self::ONE_SIZE_PHRASES, true)) {
            return new NormalizedSize($raw, SizeFamily::Alpha, 'TU');
        }

        $number = null;
        $alpha = null;

        foreach (explode(' ', $folded) as $token) {
            if ($number === null && ctype_digit($token) && $this->isPlausible((int) $token)) {
                $number = (string) (int) $token;
                continue;
            }

            if ($alpha === null && isset(self::ALPHA_TOKENS[$token])) {
                $alpha = self::ALPHA_TOKENS[$token];
            }
        }

        // The number wins when both are present: it is strictly more
        // discriminating, and the letter is kept as a hint rather than lost.
        if ($number !== null) {
            return new NormalizedSize($raw, SizeFamily::Numeric, $number, $alpha);
        }

        if ($alpha !== null) {
            return new NormalizedSize($raw, SizeFamily::Alpha, $alpha);
        }

        return NormalizedSize::unknown($raw);
    }

    private function isPlausible(int $value): bool
    {
        return $value >= self::PLAUSIBLE_MINIMUM && $value <= self::PLAUSIBLE_MAXIMUM;
    }
}
