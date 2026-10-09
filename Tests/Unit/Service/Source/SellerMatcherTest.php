<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Source\SellerMatcher;
use PriceWatch\Service\Source\SellerOffer;

/**
 * Seller names are those of a live Google Shopping answer (2026-10-09), where
 * every seller came with an empty domain and a Google redirect as url.
 */
final class SellerMatcherTest extends TestCase
{
    private const COMPETITORS = [
        'dafy-moto.com' => 1,
        'fnac.com' => 2,
        'carrefour.fr' => 3,
        'son-video.com' => 4,
        'joybuy.fr' => 5,
        'moto-axxe.fr' => 6,
    ];

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function names(): iterable
    {
        yield 'brand name with a space' => ['Dafy Moto', 1];
        yield 'country store of a chain' => ['Dafy Moto FR', 1];
        yield 'a country word inside the name is kept' => ['France Moto', null];
        yield 'name written as a domain' => ['Fnac.com', 2];
        yield 'name and domain joined by a dash' => ['Carrefour - Carrefour.fr', 3];
        yield 'accent and hyphen' => ['Son-Vidéo.com', 4];
        yield 'lower-case domain' => ['joybuy.fr', 5];
        yield 'unwatched seller' => ['Darty', null];
        yield 'a shared word is not a match' => ['Moto', null];
        yield 'a longer name is not a match' => ['Dafy Moto Lyon Sud', null];
    }

    #[DataProvider('names')]
    public function testSellerNameMeetsTheDomainLabel(string $sellerName, ?int $expected): void
    {
        self::assertSame($expected, $this->matcher()->competitorOf($this->offer($sellerName, null, null)));
    }

    public function testAKnownDomainWinsOverTheName(): void
    {
        self::assertSame(2, $this->matcher()->competitorOf($this->offer('Dafy Moto', 'www.fnac.com', null)));
    }

    public function testTheGoogleRedirectIsNotADomain(): void
    {
        $redirect = 'file:///goto%3Furl=CAEScwHrOzAVeFKsMeVZEUUQ8YeIe1AdxPpabRBbvtDD7ksL';

        self::assertNull($this->matcher()->competitorOf($this->offer('Darty', null, $redirect)));
        self::assertNull($this->matcher()->competitorOf($this->offer('Darty', null, 'https://www.google.fr/aclk?sa=l')));
    }

    public function testAMerchantUrlIsUsedWhenGiven(): void
    {
        self::assertSame(6, $this->matcher()->competitorOf($this->offer('Axxe', null, 'https://www.moto-axxe.fr/casque-123')));
    }

    private function matcher(): SellerMatcher
    {
        return new SellerMatcher(self::COMPETITORS, new DomainNormalizer(), new TextNormalizer());
    }

    private function offer(string $sellerName, ?string $domain, ?string $url): SellerOffer
    {
        return new SellerOffer($domain, $sellerName, $sellerName, $url, null, '10.00', null, null, '10.00', 'EUR', null, null, null, 1);
    }
}
