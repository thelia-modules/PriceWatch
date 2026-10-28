<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\DomainNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The product page addresses a competitor publishes in its sitemaps, kept a
 * week in var/: a sitemap is downloaded once, then searched for free.
 *
 * Read from the Sitemap lines of robots.txt. Seen on 2026-10-13: Motoblouz
 * announces one index per country (only the French one is kept) and Dafy
 * separate files for products, shops and categories (only the product files
 * are kept, when names say which ones they are).
 *
 * Why a site yields no address is kept beside its list, for the back office:
 * on 2026-10-20 speedway.fr announced no sitemap and martimotos.com and
 * motocard.com answered 403 to every sitemap, and nothing said so.
 */
final readonly class SitemapIndex
{
    public const MAX_AGE_DAYS = 7;

    /** robots.txt announces no sitemap, on the www host or the bare one. */
    public const NO_SITEMAP = 'no_sitemap';

    /** Sitemaps announced, none could be read. */
    public const UNREADABLE = 'unreadable';

    /** Sitemaps read, no address of the site in them. */
    public const NO_ADDRESS = 'no_address';

    /** A site that published nothing, or failed, is asked again sooner. */
    private const EMPTY_MAX_AGE_HOURS = 24;

    private const MAX_SITEMAPS = 100;

    /** Motoblouz publishes 215,678 product pages (2026-10-13): room for a shop twice that size, not for a wallpaper catalogue. */
    private const MAX_URLS = 500_000;
    private const DIRECTORY = '/var/pricewatch/sitemaps';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private DomainNormalizer $domains,
    ) {
    }

    /**
     * @return list<string>
     */
    public function urls(string $domain, PageFetcher $fetcher, ?\DateTimeInterface $now = null): array
    {
        $file = $this->file($domain, 'txt');
        $now ??= new \DateTimeImmutable();

        if (is_file($file)) {
            $urls = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
            $maxAge = $urls === [] ? self::EMPTY_MAX_AGE_HOURS * 3600 : self::MAX_AGE_DAYS * 86400;

            // An empty list saved before its reason was kept is asked again.
            if ($now->getTimestamp() - (int) filemtime($file) < $maxAge && ($urls !== [] || $this->diagnosis($domain) !== null)) {
                return $urls;
            }
        }

        [$urls, $problem, $detail] = $this->download($domain, $fetcher);

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o775, true);
        }

        file_put_contents($file.'.tmp', implode("\n", $urls));
        rename($file.'.tmp', $file);
        touch($file, $now->getTimestamp());
        file_put_contents($this->file($domain, 'json'), json_encode([
            'checkedAt' => $now->format(\DateTimeInterface::ATOM),
            'urls' => \count($urls),
            'problem' => $problem,
            'detail' => $detail,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return $urls;
    }

    /**
     * What the last download of the site's sitemaps gave, null before the first.
     *
     * @return array{checkedAt: \DateTimeImmutable, urls: int, problem: ?string, detail: ?string}|null
     */
    public function diagnosis(string $domain): ?array
    {
        $file = $this->file($domain, 'json');
        $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (!\is_array($saved) || !\is_string($saved['checkedAt'] ?? null) || !\is_int($saved['urls'] ?? null)) {
            return null;
        }

        $checkedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $saved['checkedAt']);

        return $checkedAt === false ? null : [
            'checkedAt' => $checkedAt,
            'urls' => $saved['urls'],
            'problem' => \is_string($saved['problem'] ?? null) ? $saved['problem'] : null,
            'detail' => \is_string($saved['detail'] ?? null) ? $saved['detail'] : null,
        ];
    }

    private function file(string $domain, string $extension): string
    {
        return $this->projectDir.self::DIRECTORY.'/'.preg_replace('/[^a-z0-9.-]/', '_', strtolower($domain)).'.'.$extension;
    }

    /**
     * @return array{0: list<string>, 1: ?string, 2: ?string} the addresses, then why there are none and what failed
     */
    private function download(string $domain, PageFetcher $fetcher): array
    {
        $roots = [];

        foreach (['https://www.'.$domain, 'https://'.$domain] as $origin) {
            $roots = $fetcher->sitemapsOf($origin);

            if ($roots !== []) {
                break;
            }
        }

        if ($roots === []) {
            return [[], self::NO_SITEMAP, null];
        }

        $leaves = [];
        $queue = self::ourCountry($roots);
        usort($queue, static fn (string $a, string $b): int => self::priority($a) <=> self::priority($b));
        $seen = [];
        /** @var array<string, int> $failures what failed => how many sitemaps */
        $failures = [];
        $read = 0;

        while ($queue !== [] && \count($seen) < self::MAX_SITEMAPS) {
            $sitemap = array_shift($queue);

            if (isset($seen[$sitemap])) {
                continue;
            }

            $seen[$sitemap] = true;
            $fetched = $fetcher->fetchText($sitemap);
            $xml = $fetched->html;

            if ($xml === null) {
                $failure = $fetched->failure === PageOutcome::Disallowed ? 'robots.txt' : ($fetched->error ?? 'HTTP '.$fetched->status);
                $failures[$failure] = ($failures[$failure] ?? 0) + 1;
                continue;
            }

            ++$read;
            $locs = self::locs($xml);

            if (preg_match('/<sitemapindex[\s>]/i', $xml) === 1) {
                // Product files first, then French ones: a blog index with
                // hundreds of files must not use up the limit before them.
                $queue = [...$queue, ...self::ourCountry($locs)];
                usort($queue, static fn (string $a, string $b): int => self::priority($a) <=> self::priority($b));
            } else {
                $leaves[$sitemap] = $locs;
            }
        }

        // Product files, when the names tell them apart from the rest.
        $products = array_filter($leaves, static fn (string $sitemap): bool => preg_match('/produ/i', $sitemap) === 1, \ARRAY_FILTER_USE_KEY);
        $urls = [];

        foreach ($products !== [] ? $products : $leaves as $locs) {
            foreach ($locs as $url) {
                if ($this->domains->registrableDomain($url) === $domain) {
                    $urls[$url] = true;
                }
            }

            if (\count($urls) >= self::MAX_URLS) {
                break;
            }
        }

        $failed = $failures === [] ? null : implode(', ', array_map(static fn (string $failure, int $count): string => $failure.' × '.$count, array_keys($failures), $failures));

        return match (true) {
            $urls !== [] => [array_keys($urls), null, null],
            $read === 0 => [[], self::UNREADABLE, $failed],
            default => [[], self::NO_ADDRESS, $failed],
        };
    }

    /**
     * Sitemaps marked with another country than France are left out; those
     * marked with none are kept.
     *
     * @param list<string> $sitemaps
     *
     * @return list<string>
     */
    private static function ourCountry(array $sitemaps): array
    {
        return array_values(array_filter($sitemaps, static fn (string $url): bool => preg_match('#[/_-]([a-z]{2})_([A-Z]{2})[/_.-]#', $url, $match) !== 1 || $match[1] === 'fr'));
    }

    private static function priority(string $sitemap): int
    {
        return match (true) {
            preg_match('/produ/i', $sitemap) === 1 => 0,
            preg_match('#[/_-]fr_FR[/_.-]#', $sitemap) === 1 => 1,
            default => 2,
        };
    }

    /**
     * @return list<string>
     */
    private static function locs(string $xml): array
    {
        preg_match_all('#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $matches);

        return array_map(static fn (string $url): string => html_entity_decode($url, \ENT_XML1 | \ENT_QUOTES, 'UTF-8'), $matches[1]);
    }
}
