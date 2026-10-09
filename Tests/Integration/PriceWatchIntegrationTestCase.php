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

    protected function setUp(): void
    {
        if (self::$kernel === null) {
            self::$kernel = new Kernel('dev', true);
            self::$kernel->boot();
            $this->initialiseSingletons();
        }

        $this->connection = Propel::getConnection('TheliaMain');
        $this->connection->beginTransaction();

        // Propel pools ActiveRecord instances per class. Across tests that
        // pool outlives the rollback and serves rows that no longer exist.
        Propel::disableInstancePooling();
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof ConnectionInterface && $this->connection->inTransaction()) {
            // A plain rollBack() would only decrement the nesting counter if
            // application code left a nested transaction open, keeping the real
            // transaction and its metadata locks alive for the rest of the run.
            $this->connection instanceof ConnectionWrapper
                ? $this->connection->forceRollBack()
                : $this->connection->rollBack();
        }

        $this->connection = null;
    }

    /**
     * Saving a title builds a rewritten URL through the URL and Translator
     * singletons, and some model listeners read the request's session
     * unconditionally. Same preparation as Thelia\Test\IntegrationTestCase.
     */
    private function initialiseSingletons(): void
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

    protected function createFixtureFactory(): FixtureFactory
    {
        return new FixtureFactory(Propel::getConnection('TheliaMain'));
    }
}
