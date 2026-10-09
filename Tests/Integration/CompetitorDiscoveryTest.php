<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Service\Competitor\CatalogSample;
use PriceWatch\Service\Competitor\CatalogSampleSource;
use PriceWatch\Service\Competitor\CompetitorDiscovery;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Competitor\DiscoveryPrompt;
use PriceWatch\Service\Competitor\DiscoveryResponseParser;
use PriceWatch\Service\Competitor\DomainResolver;
use PriceWatch\Service\Llm\LlmException;
use PriceWatch\Service\Llm\OpenRouterClient;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The model suggests, the code decides, a human arbitrates. No network: the
 * OpenRouter answer is canned and DNS is faked.
 */
final class CompetitorDiscoveryTest extends PriceWatchIntegrationTestCase
{
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Unique per test: real competitors may already exist in this database.
        $this->tag = bin2hex(random_bytes(4));
    }

    public function testSuggestionsLandAsProposedWithTheirEvidence(): void
    {
        $report = $this->discovery([
            ['domain' => 'https://www.'.$this->domain('alpha').'/casques', 'name' => 'Alpha Moto', 'reason' => 'Vend Ixon et Bell.'],
        ])->suggest('fr_FR');

        self::assertSame([$this->domain('alpha')], $report->proposed);

        $competitor = PwCompetitorQuery::create()->filterByDomain($this->domain('alpha'))->findOne();
        self::assertNotNull($competitor);
        self::assertSame(CompetitorStatus::Proposed->value, $competitor->getStatus(), 'never straight onto the whitelist');
        self::assertSame('llm', $competitor->getDiscoverySource());
        self::assertSame('Alpha Moto', $competitor->getDisplayName());

        $evidence = json_decode((string) $competitor->getDiscoveryEvidenceJson(), true);
        self::assertSame('Vend Ixon et Bell.', $evidence['reason']);
        self::assertSame([['url' => 'https://source.example/page', 'title' => 'Source']], $evidence['sources']);
    }

    public function testBlacklistedDomainIsNeverProposedAgain(): void
    {
        (new CompetitorRepository())->create($this->domain('banned'), CompetitorStatus::Rejected, 'manual');

        $report = $this->discovery([['domain' => $this->domain('banned')]])->suggest('fr_FR');

        self::assertSame([], $report->proposed);
        self::assertSame([$this->domain('banned')], $report->blacklisted);
        self::assertSame(
            CompetitorStatus::Rejected->value,
            PwCompetitorQuery::create()->filterByDomain($this->domain('banned'))->findOne()?->getStatus(),
            'the blacklist entry is left exactly as the human set it',
        );
    }

    public function testWhitelistedDomainIsNotDuplicated(): void
    {
        (new CompetitorRepository())->create($this->domain('known'), CompetitorStatus::Watched, 'manual');

        $report = $this->discovery([['domain' => 'shop.'.$this->domain('known')]])->suggest('fr_FR');

        self::assertSame([$this->domain('known')], $report->alreadyKnown);
        self::assertSame(1, PwCompetitorQuery::create()->filterByDomain($this->domain('known'))->count());
    }

    public function testOwnShopAndMalformedDomainsAreDropped(): void
    {
        $report = $this->discovery([
            ['domain' => 'classic-ride-'.$this->tag.'.example'],
            ['domain' => 'localhost'],
        ], shopUrl: 'https://www.classic-ride-'.$this->tag.'.example')->suggest('fr_FR');

        self::assertSame([], $report->proposed);
        self::assertCount(2, $report->invalid);
    }

    public function testDomainThatDoesNotResolveIsDropped(): void
    {
        $report = $this->discovery(
            [['domain' => $this->domain('ghost')], ['domain' => $this->domain('real')]],
            unresolvable: [$this->domain('ghost')],
        )->suggest('fr_FR');

        self::assertSame([$this->domain('ghost')], $report->unresolved);
        self::assertSame([$this->domain('real')], $report->proposed);
        self::assertSame(0, PwCompetitorQuery::create()->filterByDomain($this->domain('ghost'))->count());
    }

    public function testMaximumIsRespectedAndDuplicatesInOneAnswerCountOnce(): void
    {
        $report = $this->discovery([
            ['domain' => $this->domain('one')],
            ['domain' => 'www.'.$this->domain('one')],
            ['domain' => $this->domain('two')],
            ['domain' => $this->domain('three')],
        ])->suggest('fr_FR', 2);

        self::assertSame([$this->domain('one'), $this->domain('two')], $report->proposed);
        self::assertSame(0, PwCompetitorQuery::create()->filterByDomain($this->domain('three'))->count());
    }

    public function testExcludedDomainsAreToldToTheModel(): void
    {
        (new CompetitorRepository())->create($this->domain('banned'), CompetitorStatus::Rejected, 'manual');
        $sent = [];

        $this->discovery([], sent: $sent)->suggest('fr_FR');

        $body = json_decode((string) $sent[0]['body'], true);
        self::assertStringContainsString($this->domain('banned'), $body['messages'][1]['content']);
    }

    public function testUnparseableAnswerIsAnErrorNotAnEmptyList(): void
    {
        $this->expectException(LlmException::class);

        $this->discovery(rawContent: 'Je ne peux pas répondre.')->suggest('fr_FR');
    }

    private function domain(string $name): string
    {
        return $name.'-'.$this->tag.'.example';
    }

    /**
     * @param list<array<string, string>> $competitors
     * @param list<string>                $unresolvable
     * @param list<array<string, mixed>>  $sent
     */
    private function discovery(
        array $competitors = [],
        ?string $shopUrl = null,
        array $unresolvable = [],
        ?string $rawContent = null,
        array &$sent = [],
    ): CompetitorDiscovery {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->storeSecret(SecretName::OpenrouterApiKey, 'sk-or-test');
        $settings->setLlmModel('vendor/model');

        $content = $rawContent ?? (string) json_encode(['competitors' => $competitors]);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($content, &$sent): MockResponse {
            $sent[] = $options;

            return new MockResponse((string) json_encode([
                'model' => 'vendor/model',
                'choices' => [['message' => [
                    'content' => $content,
                    'annotations' => [['type' => 'url_citation', 'url_citation' => ['url' => 'https://source.example/page', 'title' => 'Source']]],
                ]]],
            ]));
        });

        $catalog = new class($shopUrl) implements CatalogSampleSource {
            public function __construct(private readonly ?string $shopUrl)
            {
            }

            public function sample(string $locale): CatalogSample
            {
                return new CatalogSample($this->shopUrl, ['Ixon', 'Bell'], ['Blousons', 'Casques'], ['Blouson Ixon Tracker']);
            }
        };

        $resolver = new class($unresolvable) implements DomainResolver {
            /** @param list<string> $unresolvable */
            public function __construct(private readonly array $unresolvable)
            {
            }

            public function resolves(string $domain): bool
            {
                return !\in_array($domain, $this->unresolvable, true);
            }
        };

        return new CompetitorDiscovery(
            new OpenRouterClient($http, $settings),
            $settings,
            $catalog,
            new CompetitorRepository(),
            new DomainNormalizer(),
            $resolver,
            new DiscoveryPrompt(),
            new DiscoveryResponseParser(),
        );
    }
}
