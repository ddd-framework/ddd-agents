<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFolderClassFinder;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Libs\Config;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * The widget type → class registry: every concrete {@see ConversationWidget} keyed by its
 * `WIDGET_TYPE`, built once per process from the code. The AI core imports no domain widget; it FINDS them by the folder
 * convention — the AI core's own `Domain/AI/Entities/Conversations/Widgets/` and every domain's
 * `Domain/<Domain>/Entities/AI/Conversations/Widgets/`, each at ANY depth — turning each
 * `*ConversationWidget.php` file into its class name by PSR-4 (`App\` = `src/`). A class outside the two folders is not found, and the contract test
 * of the application then fails because its frontend type has no class.
 *
 * Why two globs and not the DI container or the framework's ClassFinder: a tagged-service registry is not how this
 * codebase resolves services, and ClassFinder scans the whole domain tree and caches for 300 s, so a
 * new class would appear late. Cost: two `glob()` calls and ~65 autoloads, once per worker process.
 *
 * Build errors — two classes with one `WIDGET_TYPE`, a class whose short name is not `<WIDGET_TYPE>ConversationWidget`, a
 * `WIDGET_TYPE` ending in the reserved legacy suffix `Directive`, a class that fails to load — FAIL SOFT in production
 * (like a purpose's `purposeClass`): the class is logged as an ERROR and skipped (of a duplicate the first
 * class stays), so one broken widget class cannot take every turn down with it. They throw a LogicException only under
 * tests (`APP_ENV=test`) or when {@see $throwErrors} is set; the contract test therefore stays fail-hard. The memo keeps
 * the built result even when a class was skipped: the build runs once per process, never again per call.
 */
class ConversationWidgetClasses
{
    /** The suffix every pre-canonicalization wire name carried (`widget-name-resolver.ts`); never part of a WIDGET_TYPE. */
    public const string LEGACY_WIDGET_TYPE_SUFFIX = 'Directive';

    /** The short-name suffix every concrete widget class carries after its `WIDGET_TYPE`. */
    public const string WIDGET_CLASS_SHORT_NAME_SUFFIX = 'ConversationWidget';

    /** @var array<string, class-string<ConversationWidget>>|null The registry, built on first use — kept even when a class was skipped. */
    protected static ?array $widgetClassesByWidgetType = null;

    /** @var array<string, class-string<FormSubmitConversationWidget>>|null The form-submit widget classes, derived from the registry on first use. */
    protected static ?array $formSubmitWidgetClassesByWidgetType = null;

    /** @var bool A build error throws instead of being logged and skipped (set by a tool; tests throw regardless). */
    public static bool $throwErrors = false;

    /**
     * The class whose `WIDGET_TYPE` is `$widgetType`, after the frontend's name rule: the exact name; else, only for a
     * name ending in `Directive`, that suffix stripped ONCE (the legacy wire form stored conversations carry). Null for
     * an unknown type.
     *
     * @return class-string<ConversationWidget>|null
     */
    public static function getWidgetClassForWidgetType(string $widgetType): ?string
    {
        $widgetClassesByWidgetType = static::getWidgetClassesByWidgetType();
        if (isset($widgetClassesByWidgetType[$widgetType])) {
            return $widgetClassesByWidgetType[$widgetType];
        }
        if (!str_ends_with($widgetType, self::LEGACY_WIDGET_TYPE_SUFFIX)) {
            return null;
        }
        return $widgetClassesByWidgetType[substr($widgetType, 0, -strlen(self::LEGACY_WIDGET_TYPE_SUFFIX))] ?? null;
    }

    /** The type's `AWAITS_OWNER_INPUT`; false for an unknown type. */
    public static function isWidgetTypeAwaitingOwnerInput(string $widgetType): bool
    {
        $widgetClass = static::getWidgetClassForWidgetType($widgetType);
        return $widgetClass !== null && $widgetClass::AWAITS_OWNER_INPUT;
    }

    /**
     * Whether one of the types declares `PROPOSES_IDENTITY_OR_CLASS`; an unknown type declares nothing.
     *
     * @param string[] $widgetTypes
     */
    public static function isOneOfWidgetTypesProposingIdentityOrClass(array $widgetTypes): bool
    {
        foreach ($widgetTypes as $widgetType) {
            $widgetClass = static::getWidgetClassForWidgetType($widgetType);
            if ($widgetClass !== null && $widgetClass::PROPOSES_IDENTITY_OR_CLASS) {
                return true;
            }
        }
        return false;
    }

    /**
     * The types whose class declares `EMAIL_RENDERABLE` — rendered as images in a scheduled report mail — sorted. The
     * mail job's allowlist and the e-mail surface of what a run is taught both read this list; there is no second one.
     *
     * @return string[]
     */
    public static function getEmailRenderableWidgetTypes(): array
    {
        $emailRenderableWidgetTypes = [];
        foreach (static::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if ($widgetClass::EMAIL_RENDERABLE) {
                $emailRenderableWidgetTypes[] = $widgetType;
            }
        }
        sort($emailRenderableWidgetTypes);
        return $emailRenderableWidgetTypes;
    }

    /** Whether the type's class declares `EMAIL_RENDERABLE`; false for an unknown type. */
    public static function isWidgetTypeEmailRenderable(string $widgetType): bool
    {
        $widgetClass = static::getWidgetClassForWidgetType($widgetType);
        return $widgetClass !== null && $widgetClass::EMAIL_RENDERABLE;
    }

    /** The type's `OWNER_CONTROL`; null for an unknown type or a class that describes none. */
    public static function getOwnerControlOfWidgetType(string $widgetType): ?string
    {
        $widgetClass = static::getWidgetClassForWidgetType($widgetType);
        return $widgetClass !== null && $widgetClass::OWNER_CONTROL !== '' ? $widgetClass::OWNER_CONTROL : null;
    }

    /**
     * The types whose class reads its own answer ({@see AnswerableConversationWidget}), sorted — each needs a contract
     * fixture with its example blocks and reaction sentences (checked by a test of the application).
     *
     * @return string[]
     */
    public static function getAnswerableWidgetTypes(): array
    {
        $answerableWidgetTypes = [];
        foreach (static::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if (is_subclass_of($widgetClass, AnswerableConversationWidget::class)) {
                $answerableWidgetTypes[] = $widgetType;
            }
        }
        return $answerableWidgetTypes;
    }

    /**
     * The type of the form widget whose owner's submit `$message` is ({@see FormSubmitConversationWidget::isFormSubmitRow()});
     * null for a row that submits no form.
     */
    public static function findFormWidgetTypeSubmittedByRow(AIConversationMessage $message): ?string
    {
        foreach (static::getFormSubmitWidgetClassesByWidgetType() as $widgetType => $formSubmitWidgetClass) {
            if ($formSubmitWidgetClass::isFormSubmitRow($message)) {
                return $widgetType;
            }
        }
        return null;
    }

    /**
     * The classes whose owner's submit arrives as a form row of their own ({@see FormSubmitConversationWidget}), keyed
     * by type, derived from the registry once per process.
     *
     * @return array<string, class-string<FormSubmitConversationWidget>>
     */
    public static function getFormSubmitWidgetClassesByWidgetType(): array
    {
        if (static::$formSubmitWidgetClassesByWidgetType !== null) {
            return static::$formSubmitWidgetClassesByWidgetType;
        }
        $formSubmitWidgetClassesByWidgetType = [];
        foreach (static::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if (is_subclass_of($widgetClass, FormSubmitConversationWidget::class)) {
                $formSubmitWidgetClassesByWidgetType[$widgetType] = $widgetClass;
            }
        }
        return static::$formSubmitWidgetClassesByWidgetType = $formSubmitWidgetClassesByWidgetType;
    }

    /**
     * The answer set of the turn-start `direct_widget` question: every type whose class declares a
     * {@see ConversationWidget::DIRECT_WIDGET_WHEN} line, type → line, sorted by type (the request bytes stay stable).
     *
     * @return array<string, string>
     */
    public static function getDirectWidgetWhenLinesByWidgetType(): array
    {
        $directWidgetWhenLinesByWidgetType = [];
        foreach (static::getWidgetClassesByWidgetType() as $widgetType => $widgetClass) {
            if ($widgetClass::DIRECT_WIDGET_WHEN !== '') {
                $directWidgetWhenLinesByWidgetType[$widgetType] = $widgetClass::DIRECT_WIDGET_WHEN;
            }
        }
        ksort($directWidgetWhenLinesByWidgetType);
        return $directWidgetWhenLinesByWidgetType;
    }

    /**
     * Every registered widget type, sorted.
     *
     * @return string[]
     */
    public static function getWidgetTypes(): array
    {
        return array_keys(static::getWidgetClassesByWidgetType());
    }

    /**
     * The registry, built once per process from the widget folders.
     *
     * @return array<string, class-string<ConversationWidget>>
     */
    public static function getWidgetClassesByWidgetType(): array
    {
        return static::$widgetClassesByWidgetType ??= static::buildWidgetClassesByWidgetType(...static::findWidgetClassNamesInWidgetFolders());
    }

    /**
     * The class names of every `*ConversationWidget.php` file in the two widget folder patterns, by PSR-4 — abstract
     * classes and the base included (the build skips them).
     *
     * @return string[]
     */
    public static function findWidgetClassNamesInWidgetFolders(): array
    {
        $widgetFolders = array_values(array_unique([__DIR__, ...ComposedViewFolderClassFinder::findDomainFolders('Entities/AI/Conversations/Widgets')]));
        $widgetFilePaths = [];
        foreach ($widgetFolders as $widgetFolder) {
            array_push($widgetFilePaths, ...static::findWidgetClassFilesBelowFolder($widgetFolder));
        }
        sort($widgetFilePaths);
        $widgetClassNames = [];
        foreach ($widgetFilePaths as $widgetFilePath) {
            $widgetClassNames[] = ComposedViewFolderClassFinder::findClassNameForFilePath($widgetFilePath);
        }
        return $widgetClassNames;
    }

    /**
     * Every `*ConversationWidget.php` below `$widgetFolder`, any depth (the kit's own widget sits in
     * `Widgets/Composed/`, and a lane may group its widgets in a subfolder).
     *
     * @return string[]
     */
    public static function findWidgetClassFilesBelowFolder(string $widgetFolder): array
    {
        if (!is_dir($widgetFolder)) {
            return [];
        }
        $widgetFilePaths = [];
        $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($widgetFolder, FilesystemIterator::SKIP_DOTS));
        foreach ($fileIterator as $widgetFile) {
            $widgetFilePath = (string)$widgetFile;
            if (str_ends_with($widgetFilePath, self::WIDGET_CLASS_SHORT_NAME_SUFFIX . '.php')) {
                $widgetFilePaths[] = $widgetFilePath;
            }
        }
        return $widgetFilePaths;
    }

    /**
     * The concrete widget classes among `$classNames`, keyed by `WIDGET_TYPE` and sorted by it; non-widgets and
     * abstract classes are skipped. Of two classes with one `WIDGET_TYPE` the one that extends the other wins (an
     * application or module override); a build error ({@see handleBuildError()}) skips the class — of an unrelated
     * duplicate the first class stays — or throws.
     *
     * @return array<string, class-string<ConversationWidget>>
     * @throws LogicException only under tests or {@see $throwErrors}: on a class that fails to load, a duplicate
     *                        `WIDGET_TYPE`, a short name that is not `<WIDGET_TYPE>ConversationWidget`, or a `WIDGET_TYPE`
     *                        ending in `Directive`
     */
    public static function buildWidgetClassesByWidgetType(string ...$classNames): array
    {
        $widgetClassesByWidgetType = [];
        foreach ($classNames as $className) {
            try {
                // an interface in a widget folder (`AnswerableConversationWidget`) loads and is no widget
                if (!class_exists($className) && !interface_exists($className)) {
                    static::handleBuildError(sprintf('The widget class %s does not load: no such class.', $className));
                    continue;
                }
            } catch (Throwable $loadError) {
                static::handleBuildError(sprintf('The widget class %s does not load: %s', $className, $loadError->getMessage()));
                continue;
            }
            if (!is_subclass_of($className, ConversationWidget::class)) {
                continue;
            }
            $reflectionClass = new ReflectionClass($className);
            if ($reflectionClass->isAbstract()) {
                continue;
            }
            $widgetType = $className::WIDGET_TYPE;
            if (isset($widgetClassesByWidgetType[$widgetType])) {
                // an application or module subclass of a discovered widget class replaces it (override before duplicate)
                $overridingClassName = ComposedViewFolderClassFinder::resolveOverridingClassName($widgetClassesByWidgetType[$widgetType], $className);
                if ($overridingClassName === null) {
                    static::handleBuildError(sprintf('Two widget classes declare the WIDGET_TYPE "%s": %s and %s.', $widgetType, $widgetClassesByWidgetType[$widgetType], $className));
                    continue;
                }
                if ($overridingClassName !== $className) {
                    continue;
                }
            }
            if ($widgetType === '' || $reflectionClass->getShortName() !== $widgetType . self::WIDGET_CLASS_SHORT_NAME_SUFFIX) {
                static::handleBuildError(sprintf('The widget class %s must be named "%s%s" after its WIDGET_TYPE "%s".', $className, $widgetType, self::WIDGET_CLASS_SHORT_NAME_SUFFIX, $widgetType));
                continue;
            }
            if (str_ends_with($widgetType, self::LEGACY_WIDGET_TYPE_SUFFIX)) {
                static::handleBuildError(sprintf('The WIDGET_TYPE "%s" of %s ends in the reserved legacy suffix "%s".', $widgetType, $className, self::LEGACY_WIDGET_TYPE_SUFFIX));
                continue;
            }
            $widgetClassesByWidgetType[$widgetType] = $className;
        }
        ksort($widgetClassesByWidgetType);
        return $widgetClassesByWidgetType;
    }

    /**
     * One build error: thrown under tests or {@see $throwErrors}, otherwise logged as an ERROR — the caller then skips
     * the class.
     *
     * @throws LogicException only under tests or {@see $throwErrors}
     */
    public static function handleBuildError(string $buildErrorMessage): void
    {
        if (static::shouldThrowBuildErrors()) {
            throw new LogicException($buildErrorMessage);
        }
        static::logBuildError($buildErrorMessage . ' The class is skipped.');
    }

    /** Whether a build error throws: under tests, or when {@see $throwErrors} is set. */
    public static function shouldThrowBuildErrors(): bool
    {
        return static::$throwErrors || static::isRunningUnderTests();
    }

    /** Whether this process runs the test suite (`APP_ENV=test`, set by phpunit.xml). */
    public static function isRunningUnderTests(): bool
    {
        return Config::getEnv('APP_ENV') === 'test';
    }

    /** Writes one skipped build error to the log at ERROR level. */
    public static function logBuildError(string $buildErrorMessage): void
    {
        DDDService::instance()->getLogger()->error($buildErrorMessage);
    }
}
