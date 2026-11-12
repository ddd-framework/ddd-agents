<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFolderClassFinder;
use LogicException;
use ReflectionClass;

/**
 * The data-source registry of the `ComposedView` kit: every concrete {@see ComposedViewDataSource}
 * in the AI core's `Widgets/Composed/DataSources/` folder and every domain's `Entities/AI/Conversations/DataSources/`
 * folder (any depth), keyed by `NAME`, built once per process — a lane adds a source with one new file. The readers
 * accept a `$source` binding only for a registered name and its declared parameters.
 */
class ComposedViewDataSourceClasses
{
    public const string DATA_SOURCE_CLASS_SHORT_NAME_SUFFIX = 'ComposedViewDataSource';

    /** @var array<string, class-string<ComposedViewDataSource>>|null */
    protected static ?array $dataSourceClassesByName = null;

    /** @return class-string<ComposedViewDataSource>|null */
    public static function getDataSourceClassForName(string $dataSourceName): ?string
    {
        return static::getDataSourceClassesByName()[$dataSourceName] ?? null;
    }

    /** @return array<string, class-string<ComposedViewDataSource>> */
    public static function getDataSourceClassesByName(): array
    {
        return static::$dataSourceClassesByName ??= static::buildDataSourceClassesByName(...ComposedViewFolderClassFinder::findClassNamesBelowFolders(
            self::DATA_SOURCE_CLASS_SHORT_NAME_SUFFIX,
            [__DIR__, ...ComposedViewFolderClassFinder::findDomainFolders('Entities/AI/Conversations/DataSources')],
        ));
    }

    /**
     * Replaces the registry (a test with stub sources); null rebuilds it from the folders on the next use.
     *
     * @param array<string, class-string<ComposedViewDataSource>>|null $dataSourceClassesByName
     */
    public static function replaceDataSourceClasses(?array $dataSourceClassesByName): void
    {
        static::$dataSourceClassesByName = $dataSourceClassesByName;
    }

    /**
     * @return array<string, class-string<ComposedViewDataSource>>
     * @throws LogicException only under tests: a duplicate or invalid name, a misnamed class, no endpoint
     */
    public static function buildDataSourceClassesByName(string ...$classNames): array
    {
        $dataSourceClassesByName = [];
        foreach (ComposedViewFolderClassFinder::filterConcreteSubclasses(ComposedViewDataSource::class, ...$classNames) as $className) {
            $dataSourceName = $className::NAME;
            if (isset($dataSourceClassesByName[$dataSourceName])) {
                // an application or module subclass of a discovered source replaces it (override before duplicate)
                $overridingClassName = ComposedViewFolderClassFinder::resolveOverridingClassName($dataSourceClassesByName[$dataSourceName], $className);
                if ($overridingClassName !== null) {
                    $dataSourceClassesByName[$dataSourceName] = $overridingClassName;
                    continue;
                }
            }
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $dataSourceName) !== 1 || isset($dataSourceClassesByName[$dataSourceName]) || $className::CLIENT_ENDPOINT === '') {
                ComposedViewFolderClassFinder::handleBuildError(sprintf('The data source %s has an invalid or duplicate NAME "%s" or no CLIENT_ENDPOINT.', $className, $dataSourceName));
                continue;
            }
            if (!str_ends_with($className, '\\' . ucfirst($dataSourceName) . self::DATA_SOURCE_CLASS_SHORT_NAME_SUFFIX)) {
                ComposedViewFolderClassFinder::handleBuildError(sprintf('The data source %s must be named "%s%s".', $className, ucfirst($dataSourceName), self::DATA_SOURCE_CLASS_SHORT_NAME_SUFFIX));
                continue;
            }
            $dataSourceClassesByName[$dataSourceName] = $className;
        }
        ksort($dataSourceClassesByName);
        return $dataSourceClassesByName;
    }

    /**
     * The declared parameters of a source.
     *
     * @param class-string<ComposedViewDataSource> $dataSourceClass
     * @return ComposedViewDataSourceParam[]
     */
    public static function getParams(string $dataSourceClass): array
    {
        return array_map(static fn ($attribute): ComposedViewDataSourceParam => $attribute->newInstance(), (new ReflectionClass($dataSourceClass))->getAttributes(ComposedViewDataSourceParam::class));
    }

    /**
     * The declared columns of a source.
     *
     * @param class-string<ComposedViewDataSource> $dataSourceClass
     * @return ComposedViewDataSourceColumn[]
     */
    public static function getColumns(string $dataSourceClass): array
    {
        return array_map(static fn ($attribute): ComposedViewDataSourceColumn => $attribute->newInstance(), (new ReflectionClass($dataSourceClass))->getAttributes(ComposedViewDataSourceColumn::class));
    }

    /** @return array<string, array<string, mixed>> every source as `composed-node-schema.json` lists it */
    public static function toSchemaArray(): array
    {
        $dataSources = [];
        foreach (static::getDataSourceClassesByName() as $dataSourceName => $dataSourceClass) {
            $dataSources[$dataSourceName] = [
                'summary' => $dataSourceClass::SUMMARY,
                'clientEndpoint' => $dataSourceClass::CLIENT_ENDPOINT,
                'rowsPath' => $dataSourceClass::ROWS_PATH,
                'freshnessSeconds' => $dataSourceClass::FRESHNESS_SECONDS,
                'params' => array_map(static fn (ComposedViewDataSourceParam $param): array => $param->toSchemaArray(), static::getParams($dataSourceClass)),
                'columns' => array_map(static fn (ComposedViewDataSourceColumn $column): array => $column->toSchemaArray(), static::getColumns($dataSourceClass)),
            ];
        }
        return $dataSources;
    }
}
