<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Support;

use DDD\Modules\Agents\AgentsModule;
use DDD\Modules\AI\AIModule;
use DDD\Modules\Argus\ArgusModule;
use DDD\Modules\Money\MoneyModule;
use DDD\Symfony\Kernels\DDDKernel;
use DDD\Tests\Agents\Fixtures\Application\FixtureApplicationModule;
use ReflectionClass;

/**
 * The package's own kernel: the framework (`mgamadeus/ddd`) with the modules the package requires (`ddd-argus`,
 * `ddd-common-money`, `ddd-ai`) and the package module itself — no application class.
 *
 * The project directory is `tests/Support/kernel` (framework configuration, an empty application config root, the
 * test environment file). The modules are registered through {@see getAdditionalModuleClasses()}: the project
 * directory has no `vendor/composer/installed.json`, and a package's own vendor never lists the package itself.
 * The framework's own services are registered by `config/symfony/default/services.yaml` from the framework source
 * directory, which the kernel passes as the parameter {@see self::FRAMEWORK_SOURCE_DIRECTORY_PARAMETER}.
 */
class AgentsTestKernel extends DDDKernel
{
    /** @var string Kernel parameter: the `src` directory of `mgamadeus/ddd`, wherever the autoloader found it */
    public const string FRAMEWORK_SOURCE_DIRECTORY_PARAMETER = 'agents_test.framework_source_dir';

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $this->projectDir = __DIR__ . '/kernel';
    }

    /**
     * @return string[] The four modules of the package's dependency closure, in dependency order; in a run with the
     * fixture application ({@see PackageTestEnvironment::isRunWithFixtureApplication()}) that application after them
     */
    public function getAdditionalModuleClasses(): array
    {
        $moduleClasses = [ArgusModule::class, MoneyModule::class, AIModule::class, AgentsModule::class];
        if (PackageTestEnvironment::isRunWithFixtureApplication()) {
            $moduleClasses[] = FixtureApplicationModule::class;
        }
        return $moduleClasses;
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
