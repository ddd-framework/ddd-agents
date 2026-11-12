<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Infrastructure\Modules\DDDModules;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\Agents\AgentsModule;
use DDD\Modules\AI\AIModule;
use DDD\Modules\Argus\ArgusModule;
use DDD\Modules\Money\MoneyModule;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Support\AgentsTestKernel;
use DDD\Tests\Agents\Support\PackageTestAutoloader;
use DDD\Tests\Agents\Support\TestContainerRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The package runs alone: the bootstrap booted {@see AgentsTestKernel} with the four modules of the dependency closure,
 * the loop's central services resolve from its container, and no application class is loaded or loadable.
 */
class PackageKernelStandaloneTest extends UnitTestCase
{
    public function testTheKernelRegistersExactlyTheFourModulesOfTheDependencyClosure(): void
    {
        $moduleClasses = DDDModules::getModuleClasses(TestContainerRegistry::get(), DDDService::instance()->getRootDir());
        self::assertSame([ArgusModule::class, MoneyModule::class, AIModule::class, AgentsModule::class], $moduleClasses);
    }

    public function testTheLoopServicesResolveFromThePackageContainer(): void
    {
        foreach ([AIConversationsService::class, AIConversationAgentService::class, MCPToolExecutorService::class] as $serviceClass) {
            $service = DDDService::instance()->getService($serviceClass);
            self::assertInstanceOf($serviceClass, $service, $serviceClass);
        }
    }

    public function testNoApplicationClassIsLoadedOrLoadable(): void
    {
        $applicationClasses = array_values(array_filter(
            get_declared_classes(),
            static fn (string $className): bool => str_starts_with($className, PackageTestAutoloader::APPLICATION_ROOT_NAMESPACE)
        ));
        self::assertSame([], $applicationClasses);
        self::assertFalse(class_exists('App\\Infrastructure\\Services\\AppService'));
    }

    public function testThePackageClassesLoadFromThisRepositoryNotFromAVendorCopy(): void
    {
        $classFile = (string)(new \ReflectionClass(AgentsModule::class))->getFileName();
        self::assertStringStartsWith(realpath(dirname(__DIR__, 2) . '/src') . '/', $classFile);
    }
}
