<?php

declare(strict_types=1);

namespace PriceWatch\Service\Llm;

final readonly class LlmAnswer
{
    /**
     * @param list<array{url: string, title: string}> $citations web sources the model was given
     */
    public function __construct(
        public string $content,
        public ?string $reasoning,
        public array $citations,
        public string $model,
        public int $promptTokens,
        public int $completionTokens,
    ) {
    }
}
