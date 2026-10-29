<?php

declare(strict_types=1);

/*
 * Boots just enough for integration tests: the THELIA_* constants, the
 * autoloader, then the project's .env files.
 *
 * bootstrap.php must run before the autoloader, because core/bootstrap.php
 * assumes core/ sits at the project root.
 *
 * Integration tests run against the development database and isolate
 * themselves with a transaction rolled back in tearDown (see
 * Thelia\Test\IntegrationTestCase). Do NOT run bin/test-prepare here or
 * anywhere near this suite: it calls createDatabase() on DATABASE_NAME, which
 * on this install is the development database.
 */

$projectRoot = dirname(__DIR__, 4);

// thelia-project keeps bootstrap.php at the root, the thelia/thelia repository under core/.
require_once is_file($projectRoot.'/bootstrap.php') ? $projectRoot.'/bootstrap.php' : $projectRoot.'/core/bootstrap.php';
require_once $projectRoot.'/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($projectRoot.'/.env');
