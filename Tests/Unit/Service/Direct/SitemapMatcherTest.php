<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\SitemapMatcher;
use PriceWatch\Service\Normalizer\TextNormalizer;

/**
 * Addresses taken from the sitemaps of Motoblouz, Dafy and iCasque on
 * 2026-10-13.
 */
final class SitemapMatcherTest extends TestCase
{
    public function testTheColourwayNamedInTheAddressComesFirst(): void
    {
        $found = $this->matcher()->match('Casque Bell Custom 500 Flake Rootbeer', 'Bell', [
            'https://www.icasque.com/casque-jet-bell-custom-500-flake-purple.html',
            'https://www.icasque.com/casque-jet-bell-custom-500-flake-rootbeer.html',
            'https://www.icasque.com/casque-jet-bell-custom-500-black.html',
            'https://www.icasque.com/casque-jet-bell-custom-500-flake-emerald.html',
        ]);

        self::assertSame('https://www.icasque.com/casque-jet-bell-custom-500-flake-rootbeer.html', $found[0]);
        self::assertCount(SitemapMatcher::PER_COMPETITOR, $found, 'three at most per competitor');
    }

    public function testFrenchColoursMatchTheEnglishAddress(): void
    {
        $found = $this->matcher()->match('Casque Exklusiv Duke 2 crème', 'Exklusiv', [
            'https://www.icasque.com/casque-integral-exklusiv-duke-2-matt-black.html',
            'https://www.icasque.com/casque-integral-exklusiv-duke-2-cream.html',
        ]);

        self::assertSame('https://www.icasque.com/casque-integral-exklusiv-duke-2-cream.html', $found[0]);
    }

    public function testAnotherFamilyOrASparePartIsNeverOurs(): void
    {
        $found = $this->matcher()->match('Veste Segura Mojo gris clair marron', 'Segura', [
            'https://www.motoblouz.com/vente-gants-segura-mojo-321481.html',
            'https://www.motoblouz.com/vente-pantalon-moto-segura-mojo-225713.html',
            'https://www.motoblouz.com/vente-blouson-moto-segura-mojo-225714.html',
        ]);

        self::assertSame(['https://www.motoblouz.com/vente-blouson-moto-segura-mojo-225714.html'], $found, 'a blouson is a veste; gloves and trousers are not');

        $helmet = $this->matcher()->match('Casque Stormer History noir nacré uni', 'Stormer', [
            'https://www.motoblouz.com/vente-ecran-casque-stormer-history-426411.html',
            'https://www.motoblouz.com/vente-mousse-de-joues-et-coiffe-stormer-history-426448.html',
            'https://www.motoblouz.com/vente-casque-integral-stormer-history-solid-416961.html',
        ]);

        self::assertSame(['https://www.motoblouz.com/vente-casque-integral-stormer-history-solid-416961.html'], $helmet);
    }

    public function testTheBrandAndTheModelMustBeInTheAddress(): void
    {
        $found = $this->matcher()->match('Casque Arai SZ-R VAS EVO Diamond black, noir brillant', 'Arai', [
            'https://www.dafy-moto.com/casque-tour-x5-diamond-arai.html',
            'https://www.dafy-moto.com/casque-sz-r-vas-evo-shoei.html',
            'https://www.dafy-moto.com/casque-sz-r-vas-evo-modern-arai.html',
            'https://www.dafy-moto.com/casque-sz-r-vas-evo-diamond-arai.html',
        ]);

        self::assertSame(['https://www.dafy-moto.com/casque-sz-r-vas-evo-diamond-arai.html', 'https://www.dafy-moto.com/casque-sz-r-vas-evo-modern-arai.html'], $found, 'one model word may be missing, not more');
        self::assertSame([], $this->matcher()->match('Casque Arai SZ-R VAS EVO', null, ['https://www.dafy-moto.com/casque-sz-r-vas-evo-arai.html']), 'no brand, no guess');
    }

    private function matcher(): SitemapMatcher
    {
        return new SitemapMatcher(new TextNormalizer());
    }
}
