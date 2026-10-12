<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches competitor pages the way a polite robot does: under its own name,
 * only where robots.txt allows, and leaving each site a few seconds between
 * two pages. Checked live (2026-10-12): Dafy and Motoblouz serve this name the
 * same pages as a browser, with no challenge.
 *
 * Holds per-host state for one pass: build one per pass.
 */
final class PageFetcher
{
    public const AGENT = 'PriceWatch';
    public const DELAY_SECONDS = 2;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; PriceWatch/1.0; competitor price monitoring)';
    private const TIMEOUT_SECONDS = 20;

    /** @var array<string, RobotsRules> */
    private array $robots = [];

    /** @var array<string, int> */
    private array $lastRequest = [];

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @var \Closure(): int */
    private \Closure $now;

    /**
     * @param (\Closure(int): void)|null $sleep injected so tests never wait
     * @param (\Closure(): int)|null     $now   injected so tests control time
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        ?\Closure $sleep = null,
        ?\Closure $now = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->now = $now ?? static fn (): int => time();
    }

    public function fetch(string $url): FetchedPage
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return new FetchedPage($url, 0, null, PageOutcome::HttpError, 'not a web address');
        }

        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        if (!$this->rules($origin)->allows($path)) {
            return new FetchedPage($url, 0, null, PageOutcome::Disallowed, 'robots.txt');
        }

        try {
            $response = $this->request($origin, $url);
            $status = $response->getStatusCode();
            $finalUrl = (string) ($response->getInfo('url') ?? $url);

            if ($status !== 200) {
                return new FetchedPage($finalUrl, $status, null, PageOutcome::HttpError, 'HTTP '.$status);
            }

            return new FetchedPage($finalUrl, $status, $response->getContent());
        } catch (ExceptionInterface $error) {
            return new FetchedPage($url, 0, null, PageOutcome::HttpError, mb_substr($error->getMessage(), 0, 200));
        }
    }

    /**
     * RFC 9309: no robots.txt (4xx) allows everything; one that cannot be
     * read (5xx, network) forbids everything.
     */
    private function rules(string $origin): RobotsRules
    {
        if (isset($this->robots[$origin])) {
            return $this->robots[$origin];
        }

        try {
            $response = $this->request($origin, $origin.'/robots.txt');
            $status = $response->getStatusCode();
            $rules = match (true) {
                $status === 200 => RobotsRules::parse($response->getContent(), self::AGENT),
                $status >= 400 && $status < 500 => RobotsRules::allowEverything(),
                default => RobotsRules::parse("User-agent: *\nDisallow: /", self::AGENT),
            };
        } catch (ExceptionInterface) {
            $rules = RobotsRules::parse("User-agent: *\nDisallow: /", self::AGENT);
        }

        return $this->robots[$origin] = $rules;
    }

    private function request(string $origin, string $url): ResponseInterface
    {
        if (isset($this->lastRequest[$origin])) {
            $wait = $this->lastRequest[$origin] + self::DELAY_SECONDS - ($this->now)();

            if ($wait > 0) {
                ($this->sleep)($wait);
            }
        }

        $response = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'fr-FR,fr;q=0.9'],
            'timeout' => self::TIMEOUT_SECONDS,
            'max_redirects' => 5,
        ]);
        // The status is what waits for the answer: the clock starts after it.
        $response->getStatusCode();
        $this->lastRequest[$origin] = ($this->now)();

        return $response;
    }
}
