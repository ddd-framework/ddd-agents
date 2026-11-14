<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The console namespace of the module holds commands and their helpers only: a concrete class there is a command on
 * {@see AgentConsoleCommand} with an `#[AsCommand]` name of its own under the `app:` prefix, or a plain helper that the
 * container must not build (`#[Exclude]`). Abstract classes, traits and interfaces are no services.
 */
class PackageConsoleCommandsTest extends UnitTestCase
{
    /** @var string The prefix of every command name of the module */
    public const string COMMAND_NAME_PREFIX = 'app:';

    public function testEveryConcreteClassIsACommandOnTheBaseOrAnExcludedHelper(): void
    {
        $refusedClasses = [];
        $commandNames = [];
        foreach ($this->findClassNamesOfConsoleNamespace() as $className) {
            $reflectionClass = new ReflectionClass($className);
            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface() || $reflectionClass->isTrait()) {
                continue;
            }
            $asCommandAttributes = $reflectionClass->getAttributes(AsCommand::class);
            if ($asCommandAttributes === []) {
                if ($reflectionClass->getAttributes(Exclude::class) === []) {
                    $refusedClasses[] = $className . ': neither a command nor #[Exclude]';
                }
                continue;
            }
            if (!$reflectionClass->isSubclassOf(AgentConsoleCommand::class)) {
                $refusedClasses[] = $className . ': a command that does not extend ' . AgentConsoleCommand::class;
            }
            foreach (explode('|', $asCommandAttributes[0]->newInstance()->name) as $commandName) {
                if (!str_starts_with($commandName, self::COMMAND_NAME_PREFIX)) {
                    $refusedClasses[] = $className . ": the command name {$commandName} lacks the prefix " . self::COMMAND_NAME_PREFIX;
                }
                $commandNames[] = $commandName;
            }
        }

        self::assertSame([], $refusedClasses);
        self::assertNotSame([], $commandNames, 'the module ships at least one command');
        self::assertSame(count($commandNames), count(array_unique($commandNames)), 'every command name is declared once');
    }

    /** @return string[] the classes of the files below the directory of {@see AgentConsoleCommand} */
    protected function findClassNamesOfConsoleNamespace(): array
    {
        $consoleDirectory = dirname((string)(new ReflectionClass(AgentConsoleCommand::class))->getFileName());
        $classNames = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consoleDirectory, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativeClassName = str_replace('/', '\\', substr($file->getPathname(), strlen($consoleDirectory) + 1, -4));
                $classNames[] = 'DDD\\Symfony\\Commands\\Agents\\' . $relativeClassName;
            }
        }
        sort($classNames);
        return $classNames;
    }
}
