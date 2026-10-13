<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

/**
 * The rules of a robots.txt that apply to us (RFC 9309): our own group if the
 * site wrote one, otherwise the group for every robot. The longest matching
 * rule wins, Allow on a tie; `*` and the `$` end anchor are understood.
 */
final readonly class RobotsRules
{
    /**
     * @param list<array{allow: bool, pattern: string}> $rules
     */
    private function __construct(private array $rules)
    {
    }

    public static function parse(string $robots, string $agent): self
    {
        $groups = [];
        $agents = [];
        $inRules = false;

        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                // A user-agent line after rules opens a new group.
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }

                $agents[] = strtolower($value);
                continue;
            }

            if ($field !== 'allow' && $field !== 'disallow') {
                continue;
            }

            $inRules = true;

            foreach ($agents as $name) {
                $groups[$name] ??= [];

                // An empty Disallow allows everything: no rule.
                if ($value !== '') {
                    $groups[$name][] = ['allow' => $field === 'allow', 'pattern' => $value];
                }
            }
        }

        return new self($groups[strtolower($agent)] ?? $groups['*'] ?? []);
    }

    /**
     * The sitemaps a robots.txt announces: a Sitemap line belongs to no
     * group, it applies whoever reads it.
     *
     * @return list<string>
     */
    public static function sitemaps(string $robots): array
    {
        preg_match_all('/^\s*sitemap\s*:\s*(\S+)/im', $robots, $matches);

        return array_values(array_unique($matches[1]));
    }

    public static function allowEverything(): self
    {
        return new self([]);
    }

    public function allows(string $path): bool
    {
        $best = null;

        foreach ($this->rules as $rule) {
            if (!self::matches($rule['pattern'], $path)) {
                continue;
            }

            $length = \strlen($rule['pattern']);

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote($anchored ? substr($pattern, 0, -1) : $pattern, '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
