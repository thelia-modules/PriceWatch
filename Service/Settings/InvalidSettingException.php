<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

final class InvalidSettingException extends \InvalidArgumentException
{
    public function __construct(public readonly string $setting, string $message)
    {
        parent::__construct($message);
    }
}
