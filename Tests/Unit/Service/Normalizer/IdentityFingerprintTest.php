<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Normalizer;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\IdentityFingerprint;
use PriceWatch\Service\Normalizer\TextNormalizer;

final class IdentityFingerprintTest extends TestCase
{
    private IdentityFingerprint $fingerprint;

    protected function setUp(): void
    {
        $text = new TextNormalizer();
        $this->fingerprint = new IdentityFingerprint($text, new BrandNormalizer($text), new GtinNormalizer());
    }

    public function testCosmeticRewordingDoesNotChangeTheFingerprint(): void
    {
        $before = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker - Noir', 'IXON', '4006381333931', 'Taille L');
        $after = $this->fingerprint->forRemoteOffer('blouson  ixon tracker, noir', 'Ixon ', '4006-381-333931', 'taille l');

        self::assertSame($before, $after);
    }

    public function testChangedTitleChangesTheFingerprint(): void
    {
        $before = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker', 'IXON', '4006381333931', 'L');
        $after = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker 2027', 'IXON', '4006381333931', 'L');

        self::assertNotSame($before, $after, 'a new model year must invalidate the match');
    }

    public function testChangedVariantChangesTheFingerprint(): void
    {
        $before = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker', 'IXON', '4006381333931', 'L');
        $after = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker', 'IXON', '4006381333931', 'XL');

        self::assertNotSame($before, $after);
    }

    public function testDisappearingGtinChangesTheFingerprint(): void
    {
        $before = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker', 'IXON', '4006381333931', 'L');
        $after = $this->fingerprint->forRemoteOffer('Blouson IXON Tracker', 'IXON', null, 'L');

        self::assertNotSame($before, $after);
    }

    public function testFieldsCannotBleedIntoOneAnother(): void
    {
        // Without a separator, ('ab', 'c') and ('a', 'bc') would collide.
        $first = $this->fingerprint->forRemoteOffer('ab', 'c', null, null);
        $second = $this->fingerprint->forRemoteOffer('a', 'bc', null, null);

        self::assertNotSame($first, $second);
    }

    public function testFingerprintIsAStableFortyCharacterHexString(): void
    {
        $value = $this->fingerprint->forRemoteOffer('Blouson', 'IXON', '4006381333931', 'L');

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $value);
        self::assertSame($value, $this->fingerprint->forRemoteOffer('Blouson', 'IXON', '4006381333931', 'L'));
    }

    public function testAllFieldsEmptyStillProducesAFingerprint(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            $this->fingerprint->forRemoteOffer(null, null, null, null),
        );
    }
}
