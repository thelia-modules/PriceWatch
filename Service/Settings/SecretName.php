<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

enum SecretName: string
{
    case TypesafeApiKey = 'typesafe_api_key';
    case OpenrouterApiKey = 'openrouter_api_key';
    case DataforseoPassword = 'dataforseo_password';

    /**
     * When the server environment defines it, it wins over the back-office
     * value: operations keep the last word, and a key provisioned that way
     * never has to transit through a browser.
     */
    public function environmentVariable(): string
    {
        return match ($this) {
            self::TypesafeApiKey => 'TYPESAFE_API_KEY',
            self::OpenrouterApiKey => 'OPENROUTER_API_KEY',
            self::DataforseoPassword => 'DATAFORSEO_PASSWORD',
        };
    }
}
