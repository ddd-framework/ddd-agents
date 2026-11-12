<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use DDD\DDDBundle;
use DDD\Infrastructure\Services\DDDService;
use LogicException;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The container the bootstrap booted (the package kernel's). A test that boots its own kernel through
 * {@see IntegrationTestCase} points the DDD bundle back at this container afterwards, so the next test's
 * `DDDService::instance()` resolves against the shared container again.
 */
class TestContainerRegistry
{
    protected static ?ContainerInterface $bootstrapContainer = null;

    public static function set(ContainerInterface $bootstrapContainer): void
    {
        static::$bootstrapContainer = $bootstrapContainer;
    }

    public static function get(): ContainerInterface
    {
        if (static::$bootstrapContainer === null) {
            throw new LogicException('TestContainerRegistry::set() was not called — tests/bootstrap.php did not run');
        }
        return static::$bootstrapContainer;
    }

    /** Points `DDDBundle::$defaultContainer` back at the bootstrap container and drops the cached `DDDService` instance. */
    public static function restoreToBundle(): void
    {
        $bootstrapContainer = static::get();
        $defaultContainerProperty = (new ReflectionClass(DDDBundle::class))->getProperty('defaultContainer');
        $defaultContainerProperty->setValue(null, $bootstrapContainer);
        $instanceProperty = (new ReflectionClass(DDDService::class))->getProperty('instance');
        $instanceProperty->setValue(null, null);
        DDDService::$noCache = false;
    }
}
