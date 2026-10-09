<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

/**
 * The three typed primitives Jev answers, in the exact request shape of the
 * jev-seo client (jevseo/jev.py:31-40).
 *
 * The version is ours, not Jev's: bumping it when the wording changes makes
 * every cached verdict for that question stale, so nobody keeps a verdict
 * given to a question that is no longer asked.
 */
final readonly class JevQuestion
{
    public const NOUL = 'noul';
    public const CHOICE = 'choice';
    public const SCORE = 'score';

    /**
     * @param array<string, string>|list<string> $criteria
     */
    private function __construct(
        public string $id,
        public int $version,
        public string $type,
        public string $instructions,
        public array $criteria,
    ) {
    }

    public static function noul(string $id, int $version, string $instructions, string $true, string $false): self
    {
        return new self($id, $version, self::NOUL, $instructions, ['true' => $true, 'false' => $false]);
    }

    /**
     * Every choice must offer a way out ("unclear", "other"): forcing a pick
     * among wrong options manufactures confident nonsense.
     *
     * @param array<string, string> $options
     */
    public static function choice(string $id, int $version, string $instructions, array $options): self
    {
        if (!isset($options['unclear']) && !isset($options['other'])) {
            throw new \LogicException(\sprintf('Choice question "%s" needs an "unclear" or "other" option.', $id));
        }

        return new self($id, $version, self::CHOICE, $instructions, $options);
    }

    /**
     * Levels describe concrete situations, lowest first, never a stretched
     * 1-to-10 scale.
     *
     * @param list<string> $levels
     */
    public static function score(string $id, int $version, string $instructions, array $levels): self
    {
        if (\count($levels) < 2) {
            throw new \LogicException(\sprintf('Score question "%s" needs at least two levels.', $id));
        }

        return new self($id, $version, self::SCORE, $instructions, array_values($levels));
    }

    /**
     * @return array{type: string, instructions: string, criteria: array<string, string>|list<string>}
     */
    public function toRequest(): array
    {
        return ['type' => $this->type, 'instructions' => $this->instructions, 'criteria' => $this->criteria];
    }
}
