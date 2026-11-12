<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewFolderClassFinder;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Libs\Config;
use LogicException;
use ReflectionEnum;
use Throwable;

/**
 * The signal source registry: every case of every {@see ConversationSignalSource} enum keyed by its
 * stored value, built once per process from the code — the AI core's {@see AIConversationSignalSource} first, then the
 * enums found by the folder convention (the precedent of {@see \DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses}):
 * the AI core's own `Domain/AI/Entities/Conversations/SignalSources/` and every domain's
 * `Domain/<Domain>/Entities/AI/Conversations/SignalSources/`, each `*ConversationSignalSource.php` file turned into its
 * class name by PSR-4 (`App\` = `src/`). The AI core imports no domain enum: it resolves a stored string here
 * ({@see tryFrom()}) and asks the source; the SQL readers take the named value lists below.
 *
 * Build errors — an enum that fails to load, a class in the folder that is no {@see ConversationSignalSource} enum, an
 * enum without {@see ConversationSignalSourceDefaultAnswers}, a short name that is not `<Prefix>ConversationSignalSource`,
 * a stored value another registered enum already declares — FAIL SOFT in production: the enum is logged as an ERROR
 * and skipped (of a duplicate value the first enum stays), so one broken domain enum cannot take every turn down with
 * it. They throw a LogicException only under tests (`APP_ENV=test`) or when {@see $throwErrors} is set. The memo keeps
 * the built result even when an enum was skipped.
 */
class ConversationSignalSources
{
    /** The short-name suffix every signal source enum carries after its prefix. */
    public const string ENUM_SHORT_NAME_SUFFIX = 'ConversationSignalSource';

    /** @var ConversationSignalSource[]|null The registry by stored value, built on first use — kept even when an enum was skipped. */
    protected static ?array $signalSourcesByValue = null;

    /** @var bool A build error throws instead of being logged and skipped (set by a tool; tests throw regardless). */
    public static bool $throwErrors = false;

    /** The source whose stored value is `$storedValue` in any registered enum; null for an unknown string (a tool name, an error code). */
    public static function tryFrom(string $storedValue): ?ConversationSignalSource
    {
        return static::getSignalSourcesByValue()[$storedValue] ?? null;
    }

    /**
     * Every registered source: the AI core enum's cases first, then each domain enum's, case order.
     *
     * @return ConversationSignalSource[]
     */
    public static function getAllSignalSources(): array
    {
        return array_values(static::getSignalSourcesByValue());
    }

    /**
     * The registered enums, the AI core enum first.
     *
     * @return class-string<ConversationSignalSource>[]
     */
    public static function getSignalSourceEnumClasses(): array
    {
        $enumClasses = [];
        foreach (static::getSignalSourcesByValue() as $signalSource) {
            $enumClasses[$signalSource::class] = $signalSource::class;
        }
        return array_values($enumClasses);
    }

    // --- named value lists (the SQL `IN (…)` readers) ---

    /** @return string[] The sources that carry an owner statement ({@see ConversationSignalSource::carriesAnOwnerStatement()}). */
    public static function getValuesThatCarryAnOwnerStatement(): array
    {
        return static::findValuesAnsweringTrue('carriesAnOwnerStatement');
    }

    /** @return string[] The owner's own actions ({@see ConversationSignalSource::isOwnerAuthored()}). */
    public static function getValuesThatAreOwnerAuthored(): array
    {
        return static::findValuesAnsweringTrue('isOwnerAuthored');
    }

    /** @return string[] The sources that start a turn ({@see ConversationSignalSource::startsATurn()}). */
    public static function getValuesThatStartATurn(): array
    {
        return static::findValuesAnsweringTrue('startsATurn');
    }

    /** @return string[] The sources a running turn takes over ({@see ConversationSignalSource::isPickedUpByRunningTurn()}). */
    public static function getValuesPickedUpByRunningTurn(): array
    {
        return static::findValuesAnsweringTrue('isPickedUpByRunningTurn');
    }

    /** @return string[] The supervisor's steer and correction ({@see ConversationSignalSource::isSupervisorSignal()}). */
    public static function getValuesOfSupervisorSignals(): array
    {
        return static::findValuesAnsweringTrue('isSupervisorSignal');
    }

    /** @return string[] The plan-open bounces ({@see ConversationSignalSource::isTaskPlanOpenBounce()}). */
    public static function getValuesOfTaskPlanOpenBounces(): array
    {
        return static::findValuesAnsweringTrue('isTaskPlanOpenBounce');
    }

    /**
     * The values of every registered source answering the interface question `$questionName` (a method of
     * {@see ConversationSignalSource} without parameters) with true, registry order.
     *
     * @return string[]
     */
    protected static function findValuesAnsweringTrue(string $questionName): array
    {
        $values = [];
        foreach (static::getSignalSourcesByValue() as $storedValue => $signalSource) {
            if ($signalSource->{$questionName}() === true) {
                $values[] = (string)$storedValue;
            }
        }
        return $values;
    }

    // --- the build ---

    /**
     * The registry, built once per process.
     *
     * @return ConversationSignalSource[] Keyed by stored value
     */
    public static function getSignalSourcesByValue(): array
    {
        return static::$signalSourcesByValue ??= static::buildSignalSourcesByValue(...static::findSignalSourceEnumClassNames());
    }

    /**
     * The AI core enum, then the class names of every `*ConversationSignalSource.php` file in the core folder and in
     * every source root's domain folders (the application and every installed module) by PSR-4, sorted by class
     * name — the interface itself included (the build skips it). Enums are never overridden: a stored value two enums
     * declare is a build error.
     *
     * @return string[]
     */
    public static function findSignalSourceEnumClassNames(): array
    {
        $enumFilePaths = glob(__DIR__ . '/*' . self::ENUM_SHORT_NAME_SUFFIX . '.php') ?: [];
        foreach (ComposedViewFolderClassFinder::findDomainFolders('Entities/AI/Conversations/SignalSources') as $domainSignalSourceFolder) {
            array_push($enumFilePaths, ...(glob($domainSignalSourceFolder . '/*' . self::ENUM_SHORT_NAME_SUFFIX . '.php') ?: []));
        }
        $enumClassNames = [];
        // a module that is also scanned as the core folder (the same real path) counts once
        foreach (array_unique($enumFilePaths) as $enumFilePath) {
            $enumClassNames[] = ComposedViewFolderClassFinder::findClassNameForFilePath($enumFilePath);
        }
        sort($enumClassNames);
        return [AIConversationSignalSource::class, ...$enumClassNames];
    }

    /**
     * Every case of the valid signal source enums among `$classNames`, keyed by its stored value, in the given order;
     * the interface is skipped. A build error ({@see handleBuildError()}) skips the whole enum or throws.
     *
     * @return ConversationSignalSource[] Keyed by stored value
     * @throws LogicException only under tests or {@see $throwErrors}
     */
    public static function buildSignalSourcesByValue(string ...$classNames): array
    {
        $signalSourcesByValue = [];
        foreach ($classNames as $className) {
            if ($className === ConversationSignalSource::class) {
                continue;
            }
            try {
                if (!enum_exists($className) && !class_exists($className) && !interface_exists($className)) {
                    static::handleBuildError(sprintf('The signal source enum %s does not load: no such enum.', $className));
                    continue;
                }
            } catch (Throwable $loadError) {
                static::handleBuildError(sprintf('The signal source enum %s does not load: %s', $className, $loadError->getMessage()));
                continue;
            }
            if (!enum_exists($className) || !is_subclass_of($className, ConversationSignalSource::class)) {
                static::handleBuildError(sprintf('%s must be a string-backed enum implementing %s.', $className, ConversationSignalSource::class));
                continue;
            }
            if (!in_array(ConversationSignalSourceDefaultAnswers::class, class_uses($className), true)) {
                static::handleBuildError(sprintf('The signal source enum %s must use %s.', $className, ConversationSignalSourceDefaultAnswers::class));
                continue;
            }
            $shortName = (new ReflectionEnum($className))->getShortName();
            if ($shortName === self::ENUM_SHORT_NAME_SUFFIX || !str_ends_with($shortName, self::ENUM_SHORT_NAME_SUFFIX)) {
                static::handleBuildError(sprintf('The signal source enum %s must be named "<Prefix>%s".', $className, self::ENUM_SHORT_NAME_SUFFIX));
                continue;
            }
            $duplicateValues = [];
            foreach ($className::cases() as $signalSource) {
                if (isset($signalSourcesByValue[$signalSource->value])) {
                    $duplicateValues[] = (string)$signalSource->value;
                }
            }
            if ($duplicateValues !== []) {
                static::handleBuildError(sprintf('The signal source enum %s declares stored values another enum already declares: %s.', $className, implode(', ', $duplicateValues)));
                continue;
            }
            foreach ($className::cases() as $signalSource) {
                $signalSourcesByValue[$signalSource->value] = $signalSource;
            }
        }
        return $signalSourcesByValue;
    }

    /**
     * One build error: thrown under tests or {@see $throwErrors}, otherwise logged as an ERROR — the caller then skips
     * the enum.
     *
     * @throws LogicException only under tests or {@see $throwErrors}
     */
    public static function handleBuildError(string $buildErrorMessage): void
    {
        if (static::shouldThrowBuildErrors()) {
            throw new LogicException($buildErrorMessage);
        }
        static::logBuildError($buildErrorMessage . ' The enum is skipped.');
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
