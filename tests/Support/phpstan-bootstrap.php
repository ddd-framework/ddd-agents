<?php

declare(strict_types=1);

/**
 * PHPStan bootstrap of the package: the same filtered autoloader as the standalone PHPUnit run (third-party code from
 * the vendor directory, no application class, the package from this repository); no kernel is booted.
 */

use DDD\Tests\Agents\Support\PackageTestAutoloader;

require_once __DIR__ . '/PackageTestAutoloader.php';
PackageTestAutoloader::forPackageDirectory(dirname(__DIR__, 2))->register(false);
