<?php

declare(strict_types=1);

/**
 * The package test bootstrap.
 *
 * Default (standalone): the package runs alone. {@see \DDD\Tests\Agents\Support\PackageTestAutoloader} loads the
 * framework packages and libraries from a vendor directory (the package's own after `composer install`, or the one
 * named by DDD_AGENTS_VENDOR_DIR) with no application class resolvable, and the package's classes from this repository.
 * The package kernel {@see \DDD\Tests\Agents\Support\AgentsTestKernel} is booted once; every test shares its container.
 * The compiled container goes to a directory below the system temp directory (outside the repository), keyed by the
 * kernel configuration and the package source ({@see \DDD\Tests\Agents\Support\PackageKernelDirectories}).
 *
 * Hosted (legacy): DDD_AGENTS_APP_TEST_BOOTSTRAP names an application's test bootstrap (it loads that application's
 * autoloader and boots its kernel); the package tests then run inside the application.
 */

use DDD\Tests\Agents\Support\AgentsTestKernel;
use DDD\Tests\Agents\Support\PackageKernelDirectories;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\PackageTestEnvironment;
use DDD\Tests\Agents\Support\TestContainerRegistry;

ini_set('memory_limit', '1G');

$applicationTestBootstrapFile = getenv('DDD_AGENTS_APP_TEST_BOOTSTRAP') ?: null;
if ($applicationTestBootstrapFile !== null) {
    require $applicationTestBootstrapFile;
    require_once __DIR__ . '/Support/PackageTestAutoloader.php';
    spl_autoload_register('dddAgentsTestsAutoload');
    return;
}

require_once __DIR__ . '/Support/PackageTestAutoloader.php';
PackageTestAutoloader::forPackageDirectory(dirname(__DIR__))->register();

(new PackageTestEnvironment(dirname(__DIR__)))->apply();

(new PackageKernelDirectories(dirname(__DIR__)))->apply();

$kernel = new AgentsTestKernel((string)($_SERVER['APP_ENV'] ?? 'test'), (bool)($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();
TestContainerRegistry::set($kernel->getContainer());

/** PSR-4 for `DDD\Tests\Agents\` → `tests/` in the hosted mode (the application's autoloader does not know it). */
function dddAgentsTestsAutoload(string $className): void
{
    $prefix = 'DDD\\Tests\\Agents\\';
    if (!str_starts_with($className, $prefix)) {
        return;
    }
    $classFile = __DIR__ . '/' . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
    if (is_file($classFile)) {
        require $classFile;
    }
}
