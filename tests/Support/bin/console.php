<?php

declare(strict_types=1);

/**
 * The package kernel's console (no application on the class path): `php tests/Support/bin/console.php lint:container`,
 * `debug:container`, `app:generate-doctrine-models-for-entities`. Same autoloader and environment as the PHPUnit run.
 */

use DDD\Tests\Agents\Support\AgentsTestKernel;
use DDD\Tests\Agents\Support\PackageKernelDirectories;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

ini_set('memory_limit', '1G');
$packageDirectory = dirname(__DIR__, 3);
require_once $packageDirectory . '/tests/Support/PackageTestAutoloader.php';
PackageTestAutoloader::forPackageDirectory($packageDirectory)->register();
(new PackageTestEnvironment($packageDirectory))->apply();
(new PackageKernelDirectories($packageDirectory))->apply();
if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'app');
}
$kernel = new AgentsTestKernel((string)($_SERVER['APP_ENV'] ?? 'test'), (bool)($_SERVER['APP_DEBUG'] ?? false));
exit((new Application($kernel))->run(new ArgvInput()));
