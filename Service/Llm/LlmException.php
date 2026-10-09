<?php

declare(strict_types=1);

namespace PriceWatch\Service\Llm;

/**
 * Messages are built from status codes and provider error texts only: never
 * from request headers, so a key cannot leak into a log or onto a page.
 */
final class LlmException extends \RuntimeException
{
    public static function missingKey(): self
    {
        return new self('No OpenRouter API key is configured.');
    }

    public static function missingModel(): self
    {
        return new self('No model is selected for competitor search.');
    }

    public static function http(int $status, string $providerMessage): self
    {
        $providerMessage = trim(mb_substr(preg_replace('/\s+/', ' ', $providerMessage) ?? '', 0, 200));

        return new self(match ($status) {
            401, 403 => 'OpenRouter refused the API key.',
            402 => 'The OpenRouter account has no credit left.',
            429 => 'OpenRouter rate limit reached, try again in a moment.',
            default => \sprintf('OpenRouter answered HTTP %d%s', $status, $providerMessage === '' ? '.' : ': '.$providerMessage),
        });
    }

    public static function malformed(string $what): self
    {
        return new self('OpenRouter returned an unexpected answer: '.$what.'.');
    }
}
