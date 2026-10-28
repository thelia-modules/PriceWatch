<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Source;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Ai\ExternalSpend;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Source\DataForSeoClient;
use PriceWatch\Service\Source\DataForSeoException;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use PriceWatch\Tests\Doubles\RecordingCostRecorder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers replayed from recordings made on this account's sandbox
 * (Tests/Fixtures/dataforseo, 2026-10-09). No network.
 */
final class DataForSeoClientTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/dataforseo/';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $sent = [];

    private RecordingCostRecorder $costs;

    protected function setUp(): void
    {
        $this->costs = new RecordingCostRecorder();
    }

    public function testProductSearchRequestShape(): void
    {
        $client = $this->client([$this->fixture('products-task_post')]);

        $posted = $client->postProductSearches(['capture' => '3664191100832'], 2);

        $body = json_decode((string) $this->sent[0]['options']['body'], true);
        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://api.dataforseo.com/v3/merchant/google/products/task_post', $this->sent[0]['url']);
        self::assertSame([[
            'keyword' => '3664191100832',
            'location_name' => 'France',
            'language_code' => 'fr',
            'priority' => 2,
            'depth' => 40,
            'tag' => 'capture',
        ]], $body);
        self::assertContains('Authorization: Basic '.base64_encode('login@example.com:api-password'), $this->sent[0]['options']['headers']);
        self::assertCount(1, $posted['tasks'], 'the recorded answer created one task');
        self::assertArrayHasKey('capture', $posted['tasks'], 'task ids are found again through their tag');
    }

    public function testEachPostIsAuthorizedAtItsWorstCaseThenBilledAtItsRealCost(): void
    {
        $answer = static fn (float $cost): MockResponse => new MockResponse('{"status_code": 20000, "cost": '.$cost.', "tasks": []}');
        $client = $this->client([$answer(0.12), $answer(0.0000001)]);
        $keywords = [];

        for ($i = 0; $i < 101; ++$i) {
            $keywords['t'.$i] = (string) $i;
        }

        $client->postProductSearches($keywords, 2);

        self::assertSame(['authorize:dataforseo', 'record:dataforseo', 'authorize:dataforseo', 'record:dataforseo'], $this->costs->events);
        self::assertSame('pricewatch.shopping_collection', $this->costs->authorized[0]['usage']);
        self::assertEqualsWithDelta(0.2, (float) $this->costs->authorized[0]['maximum'], 1e-9, '100 priority tasks at 0.002');
        self::assertEqualsWithDelta(0.002, (float) $this->costs->authorized[1]['maximum'], 1e-9, 'the chunk of one');
        self::assertSame('0.12000000', $this->costs->recorded[0]->cost);
        self::assertSame('0.00000010', $this->costs->recorded[1]->cost, 'a float that prints as 1.0E-7 is written as a plain decimal');
        self::assertSame('merchant/google/products/task_post', $this->costs->recorded[0]->operation);
    }

    public function testAReachedBudgetRefusesThePostBeforeAnyRequest(): void
    {
        $this->costs->budgetReached = true;

        try {
            $this->client([$this->fixture('products-task_post')])->postProductSearches(['capture' => '1'], 1);
            self::fail('expected a refusal');
        } catch (DataForSeoException $refusal) {
            self::assertStringContainsString('budget', $refusal->getMessage());
        }

        self::assertSame([], $this->sent, 'nothing was posted, nothing is billed');
    }

    public function testTheFreeSandboxIsNeitherAuthorizedNorBilled(): void
    {
        $this->client([$this->fixture('products-task_post')])->postProductSearches(['capture' => '1'], 1, true);

        self::assertSame([], $this->costs->events);
    }

    public function testSandboxIsOnlyUsedWhenAsked(): void
    {
        $client = $this->client([$this->fixture('products-task_post')]);

        $client->postProductSearches(['capture' => '1'], 1, true);

        self::assertStringStartsWith('https://sandbox.dataforseo.com/v3/', $this->sent[0]['url']);
    }

    public function testProductCardsFromARecordedAnswer(): void
    {
        $result = $this->client([$this->fixture('products-task_get_advanced')])->productCards('any');

        // Recorded fact: task_get does not echo the tag, only task_post does.
        // Callers must keep the tag => task id map from the post.
        self::assertNull($result['tag']);
        // Recorded fact: 9 of the 40 items are single-seller listings without
        // product_id. No sellers lookup is possible for them, so they are not
        // cards.
        self::assertCount(31, $result['cards']);
        $first = $result['cards'][0];
        self::assertSame('3266006740984157066', $first->productId);
        self::assertSame('Apple iPhone 18 Pro', $first->title);
        self::assertSame('1199.00', $first->price, 'prices become strings for the DECIMAL setters');
        self::assertSame('USD', $first->currency);
        self::assertSame(1, $first->rank);
        self::assertStringStartsWith('https://google.com/search?ibp=oshop', (string) $first->googleUrl);
        self::assertStringStartsWith('https://encrypted-tbn3.gstatic.com/shopping', (string) $first->image);
    }

    public function testSellerOffersFromARecordedAnswer(): void
    {
        $result = $this->client([$this->fixture('sellers-task_get_advanced')])->sellerOffers('any');

        self::assertCount(10, $result['offers']);
        $first = $result['offers'][0];
        self::assertSame('Walmart - Wireless Source', $first->sellerName);
        self::assertSame('124.99', $first->basePrice);
        self::assertSame('124.99', $first->priceExcludingShipping());
        self::assertSame('in_stock', $first->availability);
        self::assertSame('Refurbished', $result['offers'][2]->condition, 'condition is carried through for the prefilter');
        self::assertStringStartsWith('https://www.walmart.com/ip/', (string) $result['pageUrl'], 'the result carries the first seller\'s product page');
        self::assertStringStartsWith('https://google.com/search?ibp=oshop', (string) $result['googleUrl']);
    }

    public function testOnlyTheResultCarriesAMerchantLinkInALiveAnswer(): void
    {
        $result = $this->client([new MockResponse((string) file_get_contents(self::FIXTURES.'dataforseo-live-sellers-task_get_advanced.json'))])->sellerOffers('any');

        self::assertSame('https://www.motoblouz.com/vente-blouson-moto-helstons-indy-cuir-rag-241159.html?color=Noir%2FNoir&size=S', $result['pageUrl']);
        self::assertSame('Motoblouz.com', $result['offers'][0]->sellerName, 'and it is the page of the seller listed first');
        self::assertStringStartsWith('file:///goto', (string) $result['offers'][0]->url, 'sellers only carry a Google redirect token');
    }

    public function testItemisedTaxIsAddedToThePriceShownBeforeDelivery(): void
    {
        $offer = new \PriceWatch\Service\Source\SellerOffer(null, 'x', 'x', null, null, '100.00', '20.00', '5.90', '125.90', 'EUR', null, null, null, 1);

        self::assertSame('120.00', $offer->priceExcludingShipping());
    }

    public function testUnverifiedAccountIsReportedInPlainWords(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'status_code' => 40104,
            'status_message' => 'Please verify your account before using the API.',
            'tasks' => null,
        ]), ['http_code' => 403])]);

        $this->expectException(DataForSeoException::class);
        $this->expectExceptionMessage('requires the account to be verified');

        $client->postProductSearches(['t' => '1'], 1);
    }

    public function testRefusedCredentialsPointToTheApiPassword(): void
    {
        $client = $this->client([new MockResponse('{"status_code": 40100, "status_message": "not authorized"}', ['http_code' => 401])]);

        try {
            $client->postProductSearches(['t' => '1'], 1);
            self::fail('expected a refusal');
        } catch (DataForSeoException $error) {
            self::assertStringContainsString('api-access', $error->getMessage());
            self::assertStringNotContainsString('api-password', $error->getMessage(), 'the password never reaches a message');
        }
    }

    public function testATaskRefusedAtThePostComesBackWithItsReason(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'status_code' => 20000,
            'cost' => 0,
            'tasks' => [
                ['id' => 'ok-1', 'status_code' => 20100, 'data' => ['tag' => 'p1']],
                ['id' => 'no-2', 'status_code' => 40200, 'status_message' => 'Payment Required.', 'data' => ['tag' => 'p2']],
            ],
        ]))]);

        $posted = $client->postProductSearches(['p1' => 'Casque A', 'p2' => 'Casque B'], 1);

        self::assertSame(['p1' => 'ok-1'], $posted['tasks']);
        self::assertSame(['p2'], array_keys($posted['refused']));
        self::assertStringContainsString('no funds left', $posted['refused']['p2']);
    }

    public function testReadyTaskIdsAreCollectedFromEveryResultEntry(): void
    {
        $client = $this->client([new MockResponse((string) json_encode([
            'status_code' => 20000,
            'tasks' => [['status_code' => 20000, 'result' => [['id' => 'a'], ['id' => 'b'], ['no_id' => true]]]],
        ]))]);

        self::assertSame(['a', 'b'], $client->readyTaskIds('products'));
    }

    public function testPostsAreSplitByOneHundredTasks(): void
    {
        $answer = static fn (): MockResponse => new MockResponse('{"status_code": 20000, "cost": 0.1, "tasks": []}');
        $client = $this->client([$answer(), $answer(), $answer()]);
        $keywords = [];

        for ($i = 0; $i < 250; ++$i) {
            $keywords['t'.$i] = (string) $i;
        }

        $posted = $client->postProductSearches($keywords, 1);

        self::assertCount(3, $this->sent);
        self::assertCount(100, json_decode((string) $this->sent[0]['options']['body'], true));
        self::assertCount(50, json_decode((string) $this->sent[2]['options']['body'], true));
        self::assertEqualsWithDelta(0.3, $posted['costUsd'], 1e-9);
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(self::FIXTURES.'dataforseo-sandbox-'.$name.'.json'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): DataForSeoClient
    {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setDataforseoLogin('login@example.com');
        $settings->storeSecret(SecretName::DataforseoPassword, 'api-password');

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new DataForSeoClient($http, $settings, new ExternalSpend($this->costs));
    }
}
