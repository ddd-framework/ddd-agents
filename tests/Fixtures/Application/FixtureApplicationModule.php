<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\Application;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * THE FIXTURE APPLICATION of the package tests: what an application that has spaces adds to the package, in the one
 * shape the framework supports.
 *
 * The application is a tool for bicycle workshops. What its agent works for is a workshop, so it puts a subclass of
 * the package's space at the same path under its own root namespace (`App\Domain\AI\…\AgentSpaces\`) with a column of
 * its own that every row must carry (`workshopId`), and with it its own set, repositories, generated model and
 * service. Its own entity (`App\Domain\Workshops\…`) decides who may read a space and answers the facts the agent
 * core asks about one.
 *
 * The classes live below `src/` of this directory and are loaded only when the run asks for them
 * ({@see \DDD\Tests\Agents\Support\PackageTestEnvironment::isRunWithFixtureApplication()}): the framework resolves
 * a package class to the application's class of the same path as soon as that class can be loaded, for the whole
 * process. A run with the fixture application is therefore a run of its own — never mixed with the run of the package
 * alone.
 *
 * Registered as a module by the package kernel, so its services reach the container and its entities reach the schema
 * the way an application's do.
 */
class FixtureApplicationModule extends DDDModule
{
    /** @var string The root namespace of the fixture application's classes: the framework's application root */
    public const string ROOT_NAMESPACE = 'App\\';

    public static function getSourcePath(): string
    {
        return __DIR__ . '/src';
    }

    /** @return string[] */
    public static function getPublicServiceNamespaces(): array
    {
        return [
            'App\\Domain\\AI\\Services\\AgentSpaces\\',
            'App\\Domain\\Workshops\\Services\\',
        ];
    }
}
