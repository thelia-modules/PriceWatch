<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Settings;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Settings\SecretBox;

final class SecretBoxTest extends TestCase
{
    private const KEY = 'sk-or-v1-0123456789abcdef0123456789abcdef';

    public function testRoundTrip(): void
    {
        $box = new SecretBox('app-secret-for-tests');

        self::assertSame(self::KEY, $box->decrypt($box->encrypt(self::KEY)));
    }

    public function testSealedValueNeverContainsThePlaintext(): void
    {
        $sealed = (new SecretBox('app-secret-for-tests'))->encrypt(self::KEY);

        self::assertStringNotContainsString(self::KEY, $sealed);
        self::assertStringNotContainsString('0123456789abcdef', base64_decode(substr($sealed, 3)) ?: '');
        self::assertStringStartsWith('v1:', $sealed);
    }

    public function testSameValueSealsDifferentlyEachTime(): void
    {
        $box = new SecretBox('app-secret-for-tests');

        self::assertNotSame($box->encrypt(self::KEY), $box->encrypt(self::KEY), 'a fixed nonce would leak equality');
    }

    public function testRotatedAppSecretMakesTheValueUnreadableRatherThanWrong(): void
    {
        $sealed = (new SecretBox('previous-app-secret'))->encrypt(self::KEY);

        self::assertNull((new SecretBox('current-app-secret'))->decrypt($sealed));
    }

    public function testTamperedValueIsRejected(): void
    {
        $box = new SecretBox('app-secret-for-tests');
        $raw = base64_decode(substr($box->encrypt(self::KEY), 3)) ?: '';
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] === 'A' ? 'B' : 'A';

        self::assertNull($box->decrypt('v1:'.base64_encode($raw)));
    }

    public function testForeignOrTruncatedValuesAreRejected(): void
    {
        $box = new SecretBox('app-secret-for-tests');

        self::assertNull($box->decrypt(self::KEY), 'a clear value left by an older version is not trusted');
        self::assertNull($box->decrypt('v1:not-base64!!'));
        self::assertNull($box->decrypt('v1:'.base64_encode('short')));
        self::assertNull($box->decrypt(''));
    }

    public function testShortAppSecretIsAccepted(): void
    {
        // The .env.test of this install ships a 13-character APP_SECRET.
        $box = new SecretBox('$ecretf0rt3st');

        self::assertSame(self::KEY, $box->decrypt($box->encrypt(self::KEY)));
    }

    public function testEmptyAppSecretIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new SecretBox('');
    }
}
