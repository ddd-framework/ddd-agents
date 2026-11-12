<?php

declare(strict_types=1);

namespace AgentsReference;

use DDD\Modules\Agents\AgentsModule;
use DDD\Modules\AI\AIModule;
use DDD\Modules\Argus\ArgusModule;
use DDD\Modules\Money\MoneyModule;
use DDD\Symfony\Kernels\DDDKernel;
use ReflectionClass;

/**
 * The kernel of the reference application: a second application of the agents package, without the application the
 * package was moved out of. It boots the framework (`mgamadeus/ddd`), the modules the package requires (`ddd-argus`,
 * `ddd-common-money`, `ddd-ai`) and the package module, plus the few classes of this host below `src/` (namespace
 * `AgentsReference\`).
 *
 * The project directory is `examples/reference-kernel` (framework configuration, the host's config root, the env
 * file). The modules are registered through {@see getAdditionalModuleClasses()}: this host has no Composer install of
 * its own, so no `vendor/composer/installed.json` lists them.
 */
class ReferenceKernel extends DDDKernel
{
    /** @var string Kernel parameter: the `src` directory of `mgamadeus/ddd`, wherever the autoloader found it */
    public const string FRAMEWORK_SOURCE_DIRECTORY_PARAMETER = 'reference.framework_source_dir';

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $this->projectDir = dirname(__DIR__);
    }

    /** @return string[] The four modules of the package's dependency closure, in dependency order */
    public function getAdditionalModuleClasses(): array
    {
        return [ArgusModule::class, MoneyModule::class, AIModule::class, AgentsModule::class];
    }

    /** @return array<string, mixed> */
    protected function getKernelParameters(): array
    {
        $kernelParameters = parent::getKernelParameters();
        $kernelParameters[self::FRAMEWORK_SOURCE_DIRECTORY_PARAMETER] = static::getFrameworkSourceDirectory();
        return $kernelParameters;
    }

    /** @return string The `src` directory of `mgamadeus/ddd` (the directory above `Symfony/Kernels/DDDKernel.php`) */
    public static function getFrameworkSourceDirectory(): string
    {
        return dirname((string)(new ReflectionClass(DDDKernel::class))->getFileName(), 3);
    }
}
