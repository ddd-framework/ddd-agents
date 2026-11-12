<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Discovery;

use DDD\DDDBundle;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoots;
use DDD\Infrastructure\Modules\DDDModule;
use DDD\Infrastructure\Modules\DDDModules;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The source roots the agent core's folder conventions read (the discovery contract): the application's `src/`
 * (`App\`) first, then the `src/` of every installed DDD module (`DDD\`) in module discovery order — the modules
 * Composer lists and the kernel's additional ones (`ddd.modules`, {@see DDDModules}). This package's own `src/` is
 * always a root, also when no module list can be read (a container-less test). Each directory counts once (real
 * path). Built once per instance; an application or a test overrides {@see buildSourceRoots()} or replaces the roots
 * with {@see replaceSourceRoots()}.
 */
class AgentSourceRootsService extends Service
{
    /** The roots of this process (null = not built yet). */
    protected ?AgentSourceRoots $agentSourceRoots = null;

    /** The roots, built on first use. */
    public function getSourceRoots(): AgentSourceRoots
    {
        return $this->agentSourceRoots ??= $this->buildSourceRoots();
    }

    /** Replaces the roots (a test with fixture roots); null rebuilds them on the next use. */
    public function replaceSourceRoots(?AgentSourceRoots $agentSourceRoots): void
    {
        $this->agentSourceRoots = $agentSourceRoots;
    }

    /** The application root, then every module root, then this package's root when no module named it. */
    protected function buildSourceRoots(): AgentSourceRoots
    {
        $agentSourceRoots = AgentSourceRoots::newInstance();
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(
            DDDService::APP_ROOT_NAMESPACE,
            DDDService::instance()->getRootDir() . '/src',
            true
        ));
        foreach ($this->findModuleClasses() as $moduleClass) {
            $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(DDDService::FRAMEWORK_ROOT_NAMESPACE, $moduleClass::getSourcePath(), false));
        }
        $agentSourceRoots->addSourceRootOnce(AgentSourceRoot::forDirectory(DDDService::FRAMEWORK_ROOT_NAMESPACE, $this->getPackageSourceDirectory(), false));
        return $agentSourceRoots;
    }

    /**
     * The installed DDD module classes in discovery order; [] when the container or the module list cannot be read.
     *
     * @return array<int, class-string<DDDModule>>
     */
    protected function findModuleClasses(): array
    {
        try {
            $moduleClasses = DDDModules::getModuleClasses(DDDBundle::getContainer(), DDDService::instance()->getRootDir());
        } catch (Throwable) {
            return [];
        }
        return array_values(array_filter(
            $moduleClasses,
            static fn (mixed $moduleClass): bool => is_string($moduleClass) && is_subclass_of($moduleClass, DDDModule::class)
        ));
    }

    /** This package's `src/` directory. */
    public function getPackageSourceDirectory(): string
    {
        return dirname(__DIR__, 5);
    }
}
