<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts API keys before they reach module_config, so a database dump or a
 * back-office SQL export never carries them in clear.
 *
 * The key is derived from APP_SECRET with a module-specific label. Rotating
 * APP_SECRET therefore makes stored keys unreadable: decrypt() returns null
 * and the settings page asks for the key again, rather than failing open or
 * crashing.
 */
final readonly class SecretBox
{
    private const PREFIX = 'v1:';
    private const KEY_LABEL = 'pricewatch.settings.v1';

    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        if ($appSecret === '') {
            throw new \LogicException('APP_SECRET is empty: API keys cannot be stored encrypted.');
        }

        // HMAC rather than sodium_crypto_generichash: the latter rejects keys
        // shorter than 16 bytes, and APP_SECRET has no length guarantee.
        $this->key = hash_hmac('sha256', self::KEY_LABEL, $appSecret, true);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * null for anything that does not authenticate: a foreign format, a
     * truncated value, or a value sealed under a previous APP_SECRET.
     */
    public function decrypt(string $sealed): ?string
    {
        if (!str_starts_with($sealed, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($sealed, \strlen(self::PREFIX)), true);

        if ($raw === false || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        return $plaintext === false ? null : $plaintext;
    }
}
