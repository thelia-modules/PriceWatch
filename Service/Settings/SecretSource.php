<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

enum SecretSource: string
{
    case Environment = 'environment';
    case Admin = 'admin';
    /** Stored, but sealed under a previous APP_SECRET: it must be entered again. */
    case Unreadable = 'unreadable';
    case Missing = 'missing';

    public function isUsable(): bool
    {
        return $this === self::Environment || $this === self::Admin;
    }
}
