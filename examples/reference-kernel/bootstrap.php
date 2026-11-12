<?php

declare(strict_types=1);

/**
 * The reference application's bootstrap, shared by `public/index.php`, `bin/console.php` and the package's smoke test.
 *
 * - Autoloader: the package's filtered loader ({@see \DDD\Tests\Agents\Support\PackageTestAutoloader}) — third-party
 *   libraries and the `mgamadeus/*` framework packages from a vendor directory (`DDD_AGENTS_VENDOR_DIR`), no class of
 *   any other application, the package from this repository — plus `AgentsReference\` from `src/`.
 * - Environment: the git-ignored `.env` of this folder (from `.env.dist`, which is loaded when there is no `.env`),
 *   overridden by a git-ignored `.env.local`; the process environment wins (the smoke test passes fake key values).
 * - Cache and log directories below the system temp directory, keyed by the newest file of the package and this host
 *   (the kernel runs without debug, so a changed file must lead to a fresh container).
 */

use Composer\Autoload\ClassLoader;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use Symfony\Component\Dotenv\Dotenv;

$referenceDirectory = __DIR__;
$packageDirectory = dirname(__DIR__, 2);
require_once $packageDirectory . '/tests/Support/PackageTestAutoloader.php';
if (!class_exists(ClassLoader::class, false) || ClassLoader::getRegisteredLoaders() === []) {
    PackageTestAutoloader::forPackageDirectory($packageDirectory)->register();
}
// this host's own namespace, on every registered loader (a fresh process: the one just registered)
foreach (ClassLoader::getRegisteredLoaders() as $registeredClassLoader) {
    $registeredClassLoader->addPsr4('AgentsReference\\', [$referenceDirectory . '/src']);
}
// The git-ignored `.env` (or `.env.dist` when there is none), then the git-ignored `.env.local`; the process environment
// wins. DDD's Config::getEnv() reads only $_ENV, so every name `.env.dist` lists is copied from the process environment
// into $_ENV when set there.
(new Dotenv())->usePutenv(false)->loadEnv($referenceDirectory . '/.env', null, 'prod', [], false);
foreach (array_keys((new Dotenv())->parse((string)file_get_contents($referenceDirectory . '/.env.dist'))) as $environmentVariableName) {
    $processEnvironmentValue = getenv($environmentVariableName);
    if ($processEnvironmentValue !== false) {
        $_SERVER[$environmentVariableName] = $_ENV[$environmentVariableName] = $processEnvironmentValue;
    }
}
(new \AgentsReference\Infrastructure\Kernel\ReferenceKernelDirectories($packageDirectory, $referenceDirectory))->apply();
if (!defined('APP_PREFIX')) {
    define('APP_PREFIX', 'app');
}
