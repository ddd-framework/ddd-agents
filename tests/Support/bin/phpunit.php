<?php

declare(strict_types=1);

/**
 * PHPUnit for the package's standalone run: `php tests/Support/bin/phpunit.php [phpunit arguments]`.
 *
 * `vendor/bin/phpunit` requires the vendor directory's full Composer autoloader first — inside a host application that
 * also registers the application's classes and runs its autoload files before the test bootstrap can stop it. This
 * runner registers only the filtered {@see \DDD\Tests\Agents\Support\PackageTestAutoloader} and then starts PHPUnit.
 */

use DDD\Tests\Agents\Support\PackageTestAutoloader;
use PHPUnit\TextUI\Application;

$packageDirectory = dirname(__DIR__, 3);
require_once $packageDirectory . '/tests/Support/PackageTestAutoloader.php';
PackageTestAutoloader::forPackageDirectory($packageDirectory)->register();
exit((new Application())->run($_SERVER['argv']));
