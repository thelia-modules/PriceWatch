<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

/**
 * The facts Jev judges, with every collected text capped and its truncation
 * flagged. Prices travel as numbers the code has already computed: Jev reads
 * them for context and is never asked to calculate.
 */
final readonly class OfferState
{
    private const TITLE = 200;
    private const VARIANT = 120;
    private const DESCRIPTION = 1500;
    private const SHORT = 60;

    /**
     * @param array{title: string, brand: ?string, size: ?string, variant: ?string, gtin: ?string, price_incl_tax: ?string} $ours
     * @param array{title: string, seller: ?string, variant: ?string, description: ?string, price: ?string, list_price: ?string, shipping: ?string, currency: ?string, availability: ?string, condition: ?string, annotation: ?string} $theirs
     *
     * @return array<string, mixed>
     */
    public function build(array $ours, array $theirs): array
    {
        return [
            'ours' => [
                'title' => $this->cap($ours['title'], self::TITLE),
                'brand' => $this->cap($ours['brand'], self::SHORT),
                'size' => $this->cap($ours['size'], self::SHORT),
                'variant' => $this->cap($ours['variant'], self::VARIANT),
                'gtin' => $ours['gtin'],
                'price_incl_tax' => $ours['price_incl_tax'],
            ],
            'theirs' => [
                'title' => $this->cap($theirs['title'], self::TITLE),
                'title_truncated' => mb_strlen($theirs['title']) > self::TITLE,
                'seller' => $this->cap($theirs['seller'], self::SHORT),
                'variant' => $this->cap($theirs['variant'], self::VARIANT),
                'description' => $this->cap($theirs['description'], self::DESCRIPTION),
                'description_truncated' => mb_strlen((string) $theirs['description']) > self::DESCRIPTION,
                'price' => $theirs['price'],
                'list_price' => $theirs['list_price'],
                'shipping' => $theirs['shipping'],
                'currency' => $theirs['currency'],
                'availability' => $this->cap($theirs['availability'], self::SHORT),
                'condition' => $this->cap($theirs['condition'], self::SHORT),
                'annotation' => $this->cap($theirs['annotation'], self::SHORT),
            ],
        ];
    }

    /**
     * The cache key: identical facts give an identical verdict, so a verdict
     * already paid for is never asked again.
     *
     * @param array<string, mixed> $state
     */
    public function hash(array $state): string
    {
        return sha1((string) json_encode($state, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    private function cap(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
