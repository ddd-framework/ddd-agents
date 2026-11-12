<?php

declare(strict_types=1);

/**
 * The reference application's console: `php examples/reference-kernel/bin/console.php lint:container`,
 * `debug:router`, `app:entity:show-sql`. Same autoloader and environment as the web front controller.
 */

use AgentsReference\ReferenceKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

ini_set('memory_limit', '1G');
require dirname(__DIR__) . '/bootstrap.php';
$kernel = new ReferenceKernel((string)($_SERVER['APP_ENV'] ?? 'prod'), (bool)($_SERVER['APP_DEBUG'] ?? false));
exit((new Application($kernel))->run(new ArgvInput()));
