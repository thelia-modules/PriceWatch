<?php

declare(strict_types=1);

namespace PriceWatch\Service\Settings;

/**
 * Values accepted by OpenRouter's unified `reasoning.effort` parameter
 * (openrouter.ai/docs/use-cases/reasoning-tokens, read 2026-10-09).
 */
enum ReasoningEffort: string
{
    case None = 'none';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case ExtraHigh = 'xhigh';
    case Max = 'max';
}
