<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

/**
 * Jev answers the same typed request whichever way it is reached; only the
 * endpoint and the key change. Endpoints taken from the jev-seo client
 * (jevseo/jev.py:21-22), where OpenRouter maps bare model ids to typesafe/.
 */
enum JevProvider: string
{
    case Typesafe = 'typesafe';
    case Openrouter = 'openrouter';

    public function endpoint(): string
    {
        return match ($this) {
            self::Typesafe => 'https://api.typesafe.ai/v1/systemone',
            self::Openrouter => 'https://openrouter.ai/api/v1/systemone',
        };
    }

    public function secret(): SecretName
    {
        return match ($this) {
            self::Typesafe => SecretName::TypesafeApiKey,
            self::Openrouter => SecretName::OpenrouterApiKey,
        };
    }
}
