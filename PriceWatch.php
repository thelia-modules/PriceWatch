<?php

declare(strict_types=1);

namespace PriceWatch;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\Finder\Finder;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class PriceWatch extends BaseModule
{
    public const DOMAIN_NAME = 'pricewatch';

    public function postActivation(?ConnectionInterface $con = null): void
    {
        // Everything here runs inside the same transaction as the activation
        // save, and must stay behind the guard: without it the SQL is replayed
        // on every reactivation.
        if (!self::getConfigValue('is_initialized', false)) {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);
            self::setConfigValue('is_initialized', true);
        }
    }

    public function update($currentVersion, $newVersion, ?ConnectionInterface $con = null): void
    {
        // Natural order: by name alone, 0.10.0 would run before 0.4.0 adds the
        // column it follows.
        $finder = Finder::create()->name('*.sql')->depth(0)->sortByName(true)->in(__DIR__.'/Config/update');
        $database = new Database($con);

        foreach ($finder as $file) {
            if (version_compare($currentVersion, $file->getBasename('.sql'), '<')) {
                $database->insertSql(null, [$file->getPathname()]);
            }
        }
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        // Without this override no class of the module enters the container at
        // all: BaseModule::configureServices() is a no-op.
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Config/*',
                __DIR__.'/Model/*',   // Propel models are not services.
                __DIR__.'/Tests/*',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        // One implementation each: declared explicitly so a test double never
        // ends up wired by accident.
        $servicesConfigurator->alias(Service\Settings\ConfigStore::class, Service\Settings\ModuleConfigStore::class);
        $servicesConfigurator->alias(Service\Settings\ServerEnvironment::class, Service\Settings\ProcessServerEnvironment::class);
        $servicesConfigurator->alias(Service\Competitor\DomainResolver::class, Service\Competitor\DnsDomainResolver::class);
        $servicesConfigurator->alias(Service\Run\StepRunner::class, Service\Run\CommandStepRunner::class);
        $servicesConfigurator->alias(Service\Run\BackgroundRunner::class, Service\Run\MessengerBackgroundRunner::class);
    }
}
