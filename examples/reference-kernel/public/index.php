<?php

declare(strict_types=1);

/**
 * The reference application's web front controller, for PHP's built-in server:
 * `php -S 127.0.0.1:8088 examples/reference-kernel/public/index.php` (the router script answers every path).
 */

use AgentsReference\ReferenceKernel;
use Symfony\Component\HttpFoundation\Request;

ini_set('memory_limit', '1G');
require dirname(__DIR__) . '/bootstrap.php';
$kernel = new ReferenceKernel((string)($_SERVER['APP_ENV'] ?? 'prod'), (bool)($_SERVER['APP_DEBUG'] ?? false));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
