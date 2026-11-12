<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Base class for tests that need the package kernel's container (replace a service, read a compiled definition).
 * The package counterpart of an application's integration test base: the kernel is {@see AgentsTestKernel}, the
 * authenticated account is cleared after every test, and the DDD bundle is pointed back at the bootstrap container.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return AgentsTestKernel::class;
    }

    protected static function bootTestKernel(): KernelInterface
    {
        return static::bootKernel([
            'environment' => (string)($_SERVER['APP_ENV'] ?? 'test'),
            'debug' => (bool)($_SERVER['APP_DEBUG'] ?? false),
        ]);
    }

    /** Replaces the container service behind a class (the id the DDD service map resolves for it) with a double. */
    protected function replaceService(string $class, object $double): void
    {
        if (!static::$booted) {
            static::bootTestKernel();
        }
        $serviceId = DDDService::instance()->getContainerServiceClassNameForClass($class);
        static::getContainer()->set($serviceId, $double);
    }

    /** Hook for subclasses: runs first in {@see tearDown()}. */
    protected function onTearDown(): void
    {
    }

    protected function tearDown(): void
    {
        $this->onTearDown();
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        StaticRegistry::$entityServices = [];
        parent::tearDown();
        TestContainerRegistry::restoreToBundle();
    }
}
