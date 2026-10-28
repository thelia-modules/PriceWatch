<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use PriceWatch\Service\Ai\ExternalSpend;
use PriceWatch\Service\Ai\SpendRefusedException;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretName;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Domain\Ai\AiAudience;
use Thelia\Domain\Ai\AiTier;
use Thelia\Domain\Ai\AsAiUsage;

/**
 * Google Shopping through the DataForSEO Merchant API.
 *
 * Shapes are those of answers recorded on this account (sandbox, 2026-10-09,
 * Tests/Fixtures/dataforseo): an envelope with status_code 20000, one entry per
 * task with status_code 20100 "Task Created" on post, and result[0].items on
 * read. The Merchant API has no live mode: post, wait for tasks_ready, read.
 * Posting is what costs money; reading is free for 30 days.
 *
 * Only task_post echoes the tag in tasks[].data; task_get does not. The
 * tag => task id map returned by the post methods is therefore the only link
 * between a task and what it was posted for: keep it.
 */
#[AsAiUsage(
    id: self::USAGE,
    purpose: 'Searches Google Shopping through DataForSEO for the competitor offers on the products we watch.',
    audience: AiAudience::System,
    tier: AiTier::Fast,
)]
final readonly class DataForSeoClient
{
    public const USAGE = 'pricewatch.shopping_collection';

    /** List prices per task, 2026-10-08: standard queue, priority queue. */
    private const MAX_COST_PER_TASK = [1 => 0.001, 2 => 0.002];

    public const BASE_URL = 'https://api.dataforseo.com/v3';
    public const SANDBOX_URL = 'https://sandbox.dataforseo.com/v3';

    private const MAX_TASKS_PER_POST = 100;
    private const LOCATION = 'France';
    private const LANGUAGE = 'fr';
    private const NO_RESULTS = 40102;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PriceWatchSettings $settings,
        private ExternalSpend $spend,
    ) {
    }

    /**
     * @param array<string, string> $keywordsByTag tag => keyword (our product title)
     *
     * @return array{tasks: array<string, string>, costUsd: float, refused: array<string, string>} tag => task id; refused: tag => reason
     *
     * @throws DataForSeoException
     */
    public function postProductSearches(array $keywordsByTag, int $priority, bool $sandbox = false): array
    {
        $tasks = [];

        foreach ($keywordsByTag as $tag => $keyword) {
            $tasks[] = [
                'keyword' => $keyword,
                'location_name' => self::LOCATION,
                'language_code' => self::LANGUAGE,
                'priority' => $priority,
                // Billed per 40 results: 40 is one unit and plenty for a GTIN query.
                'depth' => 40,
                'tag' => (string) $tag,
            ];
        }

        return $this->post('/merchant/google/products/task_post', $tasks, $sandbox);
    }

    /**
     * @param array<string, string> $productIdsByTag tag => Google product_id
     *
     * @return array{tasks: array<string, string>, costUsd: float, refused: array<string, string>}
     *
     * @throws DataForSeoException
     */
    public function postSellerLookups(array $productIdsByTag, int $priority, bool $sandbox = false): array
    {
        $tasks = [];

        foreach ($productIdsByTag as $tag => $productId) {
            $tasks[] = [
                'product_id' => $productId,
                'location_name' => self::LOCATION,
                'language_code' => self::LANGUAGE,
                'priority' => $priority,
                'tag' => (string) $tag,
            ];
        }

        return $this->post('/merchant/google/sellers/task_post', $tasks, $sandbox);
    }

    /**
     * Where a task stands, read directly (reading is free). A task that ends
     * in error never shows in tasks_ready: without this, a pass waits for it
     * until its deadline (seen 2026-10-09: 7 minutes for two 40101 errors).
     *
     * @return 'done'|'pending' done means readable now, as a result or as an error
     */
    public function taskState(string $function, string $taskId, bool $sandbox = false): string
    {
        try {
            $this->resultItems('/merchant/google/'.$function.'/task_get/advanced/'.$taskId, $sandbox);
        } catch (DataForSeoException $error) {
            // Not processed yet, or the network failed: ask again later.
            return $error->isNotReady() || $error->getCode() === 0 ? 'pending' : 'done';
        }

        return 'done';
    }

    /**
     * @return list<string> ids of tasks whose results can be read
     *
     * @throws DataForSeoException
     */
    public function readyTaskIds(string $function, bool $sandbox = false): array
    {
        $payload = $this->request('GET', '/merchant/google/'.$function.'/tasks_ready', null, $sandbox);
        $ids = [];

        foreach ($payload['tasks'] ?? [] as $task) {
            foreach (\is_array($task['result'] ?? null) ? $task['result'] : [] as $entry) {
                if (\is_string($entry['id'] ?? null)) {
                    $ids[] = $entry['id'];
                }
            }
        }

        return $ids;
    }

    /**
     * @return array{tag: ?string, cards: list<ShoppingCard>}
     *
     * @throws DataForSeoException
     */
    public function productCards(string $taskId, bool $sandbox = false): array
    {
        [$tag, $items] = $this->resultItems('/merchant/google/products/task_get/advanced/'.$taskId, $sandbox);
        $cards = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'google_shopping_serp' || !\is_string($item['product_id'] ?? null) || $item['product_id'] === '') {
                continue;
            }

            $cards[] = new ShoppingCard(
                productId: $item['product_id'],
                title: (string) ($item['title'] ?? ''),
                price: self::decimal($item['price'] ?? null),
                oldPrice: self::decimal($item['old_price'] ?? null),
                currency: \is_string($item['currency'] ?? null) ? $item['currency'] : null,
                seller: \is_string($item['seller'] ?? null) ? $item['seller'] : null,
                domain: \is_string($item['domain'] ?? null) ? $item['domain'] : null,
                rank: (int) ($item['rank_absolute'] ?? 0),
                googleUrl: \is_string($item['shopping_url'] ?? null) ? $item['shopping_url'] : null,
                image: \is_string($item['product_images'][0] ?? null) ? $item['product_images'][0] : null,
            );
        }

        return ['tag' => $tag, 'cards' => $cards];
    }

    /**
     * Seller items carry no merchant link, only a Google redirect token that
     * cannot be decoded. The result itself does (recorded live, 2026-10-09):
     * url is the product page of the seller Google lists first, check_url
     * the Google Shopping page listing them all.
     *
     * @return array{tag: ?string, offers: list<SellerOffer>, pageUrl: ?string, googleUrl: ?string}
     *
     * @throws DataForSeoException
     */
    public function sellerOffers(string $taskId, bool $sandbox = false): array
    {
        [$tag, $items, $result] = $this->resultItems('/merchant/google/sellers/task_get/advanced/'.$taskId, $sandbox);
        $offers = [];

        foreach ($items as $item) {
            if (($item['type'] ?? null) !== 'shops_list') {
                continue;
            }

            $sellerName = (string) ($item['seller_name'] ?? $item['title'] ?? '');

            $offers[] = new SellerOffer(
                domain: \is_string($item['domain'] ?? null) ? $item['domain'] : null,
                sellerName: $sellerName,
                title: (string) ($item['title'] ?? $sellerName),
                url: \is_string($item['url'] ?? null) ? $item['url'] : null,
                details: \is_string($item['details'] ?? null) ? $item['details'] : null,
                basePrice: self::decimal($item['base_price'] ?? null),
                tax: self::decimal($item['tax'] ?? null),
                shippingPrice: self::decimal($item['shipping_price'] ?? null),
                totalPrice: self::decimal($item['total_price'] ?? null),
                currency: \is_string($item['currency'] ?? null) ? $item['currency'] : null,
                condition: \is_string($item['product_condition'] ?? null) ? $item['product_condition'] : null,
                annotation: \is_string($item['product_annotation'] ?? null) ? $item['product_annotation'] : null,
                availability: \is_string($item['product_availability'] ?? null) ? $item['product_availability'] : null,
                rank: (int) ($item['rank_absolute'] ?? 0),
            );
        }

        return [
            'tag' => $tag,
            'offers' => $offers,
            'pageUrl' => \is_string($result['url'] ?? null) ? $result['url'] : null,
            'googleUrl' => \is_string($result['check_url'] ?? null) ? $result['check_url'] : null,
        ];
    }

    /**
     * A task DataForSEO refuses comes back with its reason, so the pass can
     * say it: on 2026-10-20 an empty balance (40200) refused every search and
     * the pass reported ten products Google did not know.
     *
     * @param list<array<string, mixed>> $tasks
     *
     * @return array{tasks: array<string, string>, costUsd: float, refused: array<string, string>} tag => task id; refused: tag => reason
     *
     * @throws DataForSeoException
     */
    private function post(string $path, array $tasks, bool $sandbox): array
    {
        $ids = [];
        $refused = [];
        $cost = 0.0;

        foreach (array_chunk($tasks, self::MAX_TASKS_PER_POST) as $chunk) {
            if (!$sandbox) {
                try {
                    $this->spend->authorize(self::USAGE, ExternalSpend::DATAFORSEO, self::worstCase($chunk));
                } catch (SpendRefusedException $refusal) {
                    throw new DataForSeoException($refusal->getMessage(), 0, $refusal);
                }
            }

            $started = hrtime(true);
            $payload = $this->request('POST', $path, $chunk, $sandbox);
            $billed = (float) ($payload['cost'] ?? 0);
            $cost += $billed;

            // Billed as soon as posted, whatever the tasks turn out to be worth.
            if (!$sandbox && $billed > 0.0) {
                $this->spend->record(self::USAGE, ExternalSpend::DATAFORSEO, ltrim($path, '/'), $billed, null, intdiv(hrtime(true) - $started, 1_000_000));
            }

            foreach ($payload['tasks'] ?? [] as $task) {
                $tag = $task['data']['tag'] ?? null;

                if (!\is_string($tag)) {
                    continue;
                }

                if ((int) ($task['status_code'] ?? 0) === 20100 && \is_string($task['id'] ?? null)) {
                    $ids[$tag] = $task['id'];
                } else {
                    $refused[$tag] = DataForSeoException::fromStatus((int) ($task['status_code'] ?? 0), (string) ($task['status_message'] ?? ''))->getMessage();
                }
            }
        }

        return ['tasks' => $ids, 'costUsd' => $cost, 'refused' => $refused];
    }

    /**
     * The most a chunk can cost: every task at the price of its queue.
     *
     * @param list<array<string, mixed>> $tasks
     */
    private static function worstCase(array $tasks): float
    {
        return array_sum(array_map(
            static fn (array $task): float => self::MAX_COST_PER_TASK[(int) ($task['priority'] ?? 1)] ?? self::MAX_COST_PER_TASK[2],
            $tasks,
        ));
    }

    /**
     * @return array{0: ?string, 1: list<array<string, mixed>>, 2: array<string, mixed>} tag, items, the result itself
     *
     * @throws DataForSeoException
     */
    private function resultItems(string $path, bool $sandbox): array
    {
        $payload = $this->request('GET', $path, null, $sandbox);
        $task = $payload['tasks'][0] ?? null;

        if (!\is_array($task)) {
            return [null, [], []];
        }

        $status = (int) ($task['status_code'] ?? 0);
        $tag = \is_string($task['data']['tag'] ?? null) ? $task['data']['tag'] : null;

        // "No Search Results": an empty answer, not a failure (DataForSEO
        // appendix, read 2026-10-09).
        if ($status === self::NO_RESULTS) {
            return [$tag, [], []];
        }

        if ($status !== 20000) {
            throw DataForSeoException::fromStatus($status, (string) ($task['status_message'] ?? ''));
        }

        $result = \is_array($task['result'][0] ?? null) ? $task['result'][0] : [];
        $items = $result['items'] ?? [];

        return [$tag, \is_array($items) ? array_values(array_filter($items, \is_array(...))) : [], $result];
    }

    /**
     * @param list<array<string, mixed>>|null $json
     *
     * @return array<string, mixed>
     *
     * @throws DataForSeoException
     */
    private function request(string $method, string $path, ?array $json, bool $sandbox): array
    {
        $login = $this->settings->dataforseoLogin();
        $password = $this->settings->secret(SecretName::DataforseoPassword);

        if ($login === null || $password === null) {
            throw new DataForSeoException('The DataForSEO login and password are not configured.');
        }

        try {
            $response = $this->httpClient->request($method, ($sandbox ? self::SANDBOX_URL : self::BASE_URL).$path, [
                'auth_basic' => [$login, $password],
                'timeout' => 60,
            ] + ($json === null ? [] : ['json' => $json]));
            $content = $response->getContent(false);
        } catch (ExceptionInterface $error) {
            throw new DataForSeoException('DataForSEO could not be reached ('.$error::class.').', 0, $error);
        }

        $payload = json_decode($content, true);

        if (!\is_array($payload)) {
            throw new DataForSeoException('DataForSEO answered something that is not JSON.');
        }

        $status = (int) ($payload['status_code'] ?? 0);

        if ($status !== 20000) {
            throw DataForSeoException::fromStatus($status, (string) ($payload['status_message'] ?? ''));
        }

        return $payload;
    }

    private static function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }
}
