<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Test\FixtureFactory;

/**
 * Integration base class for this module.
 *
 * Why not Thelia\Test\IntegrationTestCase: it extends KernelTestCase, which
 * requires framework.test: true, which only exists under APP_ENV=test. That
 * environment has neither its Propel models generated (var/propel/test) nor
 * the core console commands, and no test in this install has ever run through
 * it. Rather than build that environment out as a side quest, this boots the
 * development kernel directly - the one that demonstrably works - and keeps
 * the part that matters: one transaction per test, rolled back in tearDown.
 *
 * The module's own services are final classes with explicit constructor
 * injection, so a test instantiates them itself and never needs a container.
 *
 * Constraints inherited from the core base class, and just as real here:
 *   - auto-increment values are NOT rolled back: never hardcode an id;
 *   - the kernel is shared across tests: do not mutate the container.
 */
abstract class PriceWatchIntegrationTestCase extends TestCase
{
    private static ?Kernel $kernel = null;

    private ?ConnectionInterface $connection = null;

    private int $sentinelId = 0;

    /**
     * Booted before the first test, not inside it: booting installs error and
     * exception handlers, which PHPUnit reports as a risky test when it sees
     * them appear during one.
     */
    public static function setUpBeforeClass(): void
    {
        if (self::$kernel === null) {
            self::$kernel = new Kernel('dev', true);
            self::$kernel->boot();
            self::initialiseSingletons();
        }
    }

    protected function setUp(): void
    {
        $this->connection = Propel::getConnection('TheliaMain');
        $this->connection->beginTransaction();
        // A row that only this transaction knows: if it is still there after
        // the rollback, the transaction was committed behind Propel's back.
        $this->connection->exec("INSERT INTO pw_collect_run (pass_name, status, started_at, created_at, updated_at) VALUES ('test-sentinel', 'started', NOW(), NOW(), NOW())");
        $this->sentinelId = (int) $this->connection->lastInsertId();

        // Propel pools ActiveRecord instances per class. Across tests that
        // pool outlives the rollback and serves rows that no longer exist.
        Propel::disableInstancePooling();
    }

    protected function tearDown(): void
    {
        // Propel's inTransaction() only counts its own calls. A statement
        // that commits implicitly (ANALYZE, any DDL) ends the real
        // transaction without telling it, and everything the test wrote
        // stays in the development database. Seen on 2026-10-09: 2,000 test
        // offers and a demoted whitelist, committed by an ANALYZE TABLE.
        if ($this->connection instanceof ConnectionWrapper && $this->connection->isInTransaction()) {
            // After an implicit commit PDO knows the transaction is over, but
            // Propel's counter does not: left as is, the next test's
            // beginTransaction() would only count and that test would write
            // for real. An empty transaction gives the rollback something to
            // end, and resets the counter.
            if (!$this->connection->inTransaction()) {
                $this->connection->getWrappedConnection()->beginTransaction();
            }

            // A plain rollBack() would only decrement the nesting counter if
            // application code left a nested transaction open, keeping the real
            // transaction and its metadata locks alive for the rest of the run.
            $this->connection->forceRollBack();
        } elseif ($this->connection instanceof ConnectionInterface && $this->connection->inTransaction()) {
            $this->connection->rollBack();
        }

        $committed = $this->connection instanceof ConnectionInterface
            && (int) $this->connection->query('SELECT COUNT(*) FROM pw_collect_run WHERE id = '.$this->sentinelId)->fetchColumn() > 0;

        if ($committed) {
            $this->connection?->exec('DELETE FROM pw_collect_run WHERE id = '.$this->sentinelId);
        }

        $this->connection = null;

        if ($committed) {
            self::fail('This test committed its transaction (implicit commit?): what it wrote is now in the development database and must be cleaned up by hand.');
        }
    }

    /**
     * Saving a title builds a rewritten URL through the URL and Translator
     * singletons, and some model listeners read the request's session
     * unconditionally. Same preparation as Thelia\Test\IntegrationTestCase.
     */
    private static function initialiseSingletons(): void
    {
        $container = self::$kernel?->getContainer();

        if ($container === null) {
            return;
        }

        foreach (['thelia.translator', 'thelia.url.manager'] as $id) {
            if ($container->has($id)) {
                $container->get($id);
            }
        }

        if ($container->has('request_stack')) {
            $stack = $container->get('request_stack');

            if ($stack->getCurrentRequest() === null) {
                $request = Request::create('http://localhost');
                $request->setSession(new Session(new MockArraySessionStorage()));
                $stack->push($request);
            }
        }
    }

    /**
     * For the few core services a test cannot build by hand (the event
     * dispatcher behind image processing).
     */
    protected static function coreService(string $id): object
    {
        $service = self::$kernel?->getContainer()->get($id);
        self::assertIsObject($service);

        return $service;
    }

    protected function createFixtureFactory(): FixtureFactory
    {
        return new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
