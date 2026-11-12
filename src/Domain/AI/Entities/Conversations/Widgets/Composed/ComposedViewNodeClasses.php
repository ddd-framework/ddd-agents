<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Libs\Config;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * The node type → class registry of the `ComposedView` kit: every concrete
 * {@see ComposedViewNode} below `Widgets/Composed/Nodes/` (any depth), keyed by its `NODE_TYPE`, built once per process
 * from the files — so a lane adds a node type with one new file and edits no list. The PSR-4 rule turns a file into its
 * class (`App\` = `src/`). Build errors (two classes with one type, a short name that is not `<NODE_TYPE>ComposedViewNode`,
 * a class that fails to load) are logged and skipped in production and throw under tests, like
 * `ConversationWidgetClasses`.
 */
class ComposedViewNodeClasses
{
    public const string NODE_CLASS_SHORT_NAME_SUFFIX = 'ComposedViewNode';

    /** @var array<string, class-string<ComposedViewNode>>|null The registry, built on first use. */
    protected static ?array $nodeClassesByNodeType = null;

    /** @var bool A build error throws instead of being logged (tests throw regardless). */
    public static bool $throwErrors = false;

    /** @return class-string<ComposedViewNode>|null the class of `$nodeType`, null for an unknown type */
    public static function getNodeClassForNodeType(string $nodeType): ?string
    {
        return static::getNodeClassesByNodeType()[$nodeType] ?? null;
    }

    /**
     * Every registered node type, sorted.
     *
     * @return string[]
     */
    public static function getNodeTypes(): array
    {
        return array_keys(static::getNodeClassesByNodeType());
    }

    /** @return array<string, class-string<ComposedViewNode>> */
    public static function getNodeClassesByNodeType(): array
    {
        return static::$nodeClassesByNodeType ??= static::buildNodeClassesByNodeType(...static::findNodeClassNamesInNodeFolder());
    }

    /**
     * Replaces the registry with `$nodeClassesByNodeType` (a test with its own stub node types, a tool); null rebuilds it
     * from the folder on the next use.
     *
     * @param array<string, class-string<ComposedViewNode>>|null $nodeClassesByNodeType
     */
    public static function replaceNodeClasses(?array $nodeClassesByNodeType): void
    {
        static::$nodeClassesByNodeType = $nodeClassesByNodeType;
    }

    /**
     * The class names of every `*ComposedViewNode.php` below the kit's `Widgets/Composed/Nodes/` and below every source
     * root's `Domain/<Domain>/Entities/AI/Conversations/Nodes/` (the application and every module), by PSR-4, sorted.
     *
     * @return string[]
     */
    public static function findNodeClassNamesInNodeFolder(): array
    {
        return ComposedViewFolderClassFinder::findClassNamesBelowFolders(
            self::NODE_CLASS_SHORT_NAME_SUFFIX,
            [__DIR__ . '/Nodes', ...ComposedViewFolderClassFinder::findDomainFolders('Entities/AI/Conversations/Nodes')]
        );
    }

    /**
     * The concrete node classes among `$classNames`, keyed by `NODE_TYPE`, sorted by it.
     *
     * @return array<string, class-string<ComposedViewNode>>
     * @throws LogicException only under tests or {@see $throwErrors}
     */
    public static function buildNodeClassesByNodeType(string ...$classNames): array
    {
        $nodeClassesByNodeType = [];
        foreach ($classNames as $className) {
            try {
                if (!class_exists($className)) {
                    static::handleBuildError(sprintf('The node class %s does not load: no such class.', $className));
                    continue;
                }
            } catch (Throwable $loadError) {
                static::handleBuildError(sprintf('The node class %s does not load: %s', $className, $loadError->getMessage()));
                continue;
            }
            if (!is_subclass_of($className, ComposedViewNode::class) || (new ReflectionClass($className))->isAbstract()) {
                continue;
            }
            $nodeType = $className::NODE_TYPE;
            if (isset($nodeClassesByNodeType[$nodeType])) {
                $overridingClassName = ComposedViewFolderClassFinder::resolveOverridingClassName($nodeClassesByNodeType[$nodeType], $className);
                if ($overridingClassName === null) {
                    static::handleBuildError(sprintf('Two node classes declare the NODE_TYPE "%s": %s and %s.', $nodeType, $nodeClassesByNodeType[$nodeType], $className));
                    continue;
                }
                if ($overridingClassName !== $className) {
                    continue;
                }
            }
            if ($nodeType === '' || (new ReflectionClass($className))->getShortName() !== $nodeType . self::NODE_CLASS_SHORT_NAME_SUFFIX) {
                static::handleBuildError(sprintf('The node class %s must be named "%s%s" after its NODE_TYPE.', $className, $nodeType, self::NODE_CLASS_SHORT_NAME_SUFFIX));
                continue;
            }
            $nodeClassesByNodeType[$nodeType] = $className;
        }
        ksort($nodeClassesByNodeType);
        return $nodeClassesByNodeType;
    }

    /** @throws LogicException only under tests or {@see $throwErrors} */
    public static function handleBuildError(string $buildErrorMessage): void
    {
        if (static::$throwErrors || Config::getEnv('APP_ENV') === 'test') {
            throw new LogicException($buildErrorMessage);
        }
        DDDService::instance()->getLogger()->error($buildErrorMessage . ' The class is skipped.');
    }
}
