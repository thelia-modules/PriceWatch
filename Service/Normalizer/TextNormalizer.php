<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * Lowercase, accent-folded, punctuation-free, single-spaced.
 *
 * The accent table is explicit on purpose: iconv ASCII//TRANSLIT depends on the
 * runtime locale and would make normalisation vary between the web container
 * and the console.
 */
final readonly class TextNormalizer
{
    private const ACCENT_FOLDING = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ñ' => 'n', 'ń' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
        'š' => 's', 'ś' => 's',
        'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th', 'ł' => 'l',
    ];

    public function normalize(?string $raw): string
    {
        $text = mb_strtolower(trim((string) $raw), 'UTF-8');
        $text = strtr($text, self::ACCENT_FOLDING);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
