<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwCollectRunQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Service\Competitor\CatalogSample;
use PriceWatch\Service\Competitor\CatalogSampleSource;
use PriceWatch\Service\Competitor\CompetitorDiscovery;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Competitor\DiscoveryException;
use PriceWatch\Service\Competitor\DiscoveryPrompt;
use PriceWatch\Service\Competitor\DiscoveryResponseParser;
use PriceWatch\Service\Competitor\DomainResolver;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretBox;
use PriceWatch\Service\Usage\UsageLedger;
use PriceWatch\Tests\Doubles\ArrayServerEnvironment;
use PriceWatch\Tests\Doubles\FakeAiGateway;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Thelia\Domain\Ai\Exception\AiBudgetExceededException;

/**
 * The model suggests, the code decides, a human arbitrates. No network: the
 * gateway answer is canned and DNS is faked.
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
        self::assertSame('vendor/model', $evidence['model']);
        self::assertArrayNotHasKey('sources', $evidence, 'the model has no web access: nothing to cite');
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
        $gateway = new FakeAiGateway('{"competitors":[]}');

        $this->discovery(gateway: $gateway)->suggest('fr_FR');

        self::assertStringContainsString($this->domain('banned'), $gateway->requests[0]->messages[0]->content);
    }

    public function testTheRequestNamesItsUsageAndAsksForTheSchemaAndNothingElse(): void
    {
        $gateway = new FakeAiGateway('{"competitors":[]}');

        $this->discovery(gateway: $gateway)->suggest('fr_FR');

        $request = $gateway->requests[0];
        self::assertSame('pricewatch.competitor_discovery', $request->usageId);
        self::assertSame('competitors', $request->outputSchema?->name);
        self::assertSame([], $request->tools, 'no tool, and no provider-side web search');
        self::assertSame([], $request->providerOptions, 'no way out of the governed path');
    }

    public function testTheFunctionIsOffUntilTheMerchantTurnsItOn(): void
    {
        $gateway = new FakeAiGateway('{"competitors":[]}');

        try {
            $this->discovery(gateway: $gateway, enabled: false)->suggest('fr_FR');
            self::fail('expected a refusal');
        } catch (DiscoveryException $off) {
            self::assertStringContainsString('switched off', $off->getMessage());
        }

        self::assertSame([], $gateway->requests, 'no call, no cost');
    }

    public function testARefusalOfTheAiLayerIsReportedInItsOwnWords(): void
    {
        $gateway = new FakeAiGateway('{}', new AiBudgetExceededException('pricewatch.competitor_discovery'));
        $before = $this->ledgerRows();

        try {
            $this->discovery(gateway: $gateway)->suggest('fr_FR');
            self::fail('expected a refusal');
        } catch (DiscoveryException $refused) {
            self::assertStringContainsString('monthly budget', $refused->getMessage());
        }

        self::assertSame($before, $this->ledgerRows(), 'refused before the call: nothing was paid');
    }

    public function testUnparseableAnswerIsAnErrorNotAnEmptyList(): void
    {
        $before = $this->ledgerRows();

        try {
            $this->discovery(rawContent: 'Je ne peux pas répondre.')->suggest('fr_FR');
            self::fail('expected an error');
        } catch (DiscoveryException) {
        }

        self::assertSame($before + 1, $this->ledgerRows(), 'a useless answer is still paid for, and counted');
    }

    public function testWhatTheModelConsumedGoesToTheLedger(): void
    {
        $this->discovery([['domain' => $this->domain('ledger'), 'name' => 'Ledger', 'reason' => 'x']])->suggest('fr_FR');

        $run = PwCollectRunQuery::create()->filterByPassName(CompetitorDiscovery::PASS)->orderById('desc')->findOne();
        self::assertNotNull($run);
        self::assertSame('vendor/model', $run->getLlmModel());
        self::assertSame(1234, $run->getLlmPromptTokens());
        self::assertSame(567, $run->getLlmCompletionTokens());
        self::assertEqualsWithDelta(0.00421, (float) $run->getLlmCostUsd(), 1e-9, 'the cost the log of the core holds, not an estimate');
    }

    private function ledgerRows(): int
    {
        return PwCollectRunQuery::create()->filterByPassName(CompetitorDiscovery::PASS)->count();
    }

    private function domain(string $name): string
    {
        return $name.'-'.$this->tag.'.example';
    }

    /**
     * @param list<array<string, string>> $competitors
     * @param list<string>                $unresolvable
     */
    private function discovery(
        array $competitors = [],
        ?string $shopUrl = null,
        array $unresolvable = [],
        ?string $rawContent = null,
        ?FakeAiGateway $gateway = null,
        bool $enabled = true,
    ): CompetitorDiscovery {
        $settings = new PriceWatchSettings(new InMemoryConfigStore(), new SecretBox('test-secret'), new ArrayServerEnvironment());
        $settings->setDiscoveryEnabled($enabled);

        $gateway ??= new FakeAiGateway($rawContent ?? (string) json_encode(['competitors' => $competitors]));

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
            $gateway,
            $settings,
            $catalog,
            new CompetitorRepository(),
            new DomainNormalizer(),
            $resolver,
            new DiscoveryPrompt(),
            new DiscoveryResponseParser(),
            new UsageLedger(),
        );
    }
}
