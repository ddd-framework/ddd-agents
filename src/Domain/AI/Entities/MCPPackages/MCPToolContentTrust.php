<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Whether a tool returns untrusted content — the rule every reader asks (the tool documentation, the tool-result
 * screen, the reading rule of a result, the executor's write gate after an untrusted read):
 *
 * 1. `#[MCPToolReturnsUntrustedContent]` on the method or its package: untrusted.
 * 2. `#[MCPToolReturnsTrustedContent]` on the method or its package: trusted.
 * 3. the tool or its package listed in `config/app/AI/untrustedContent.php` (`trustedTools`, `trustedPackages`): trusted.
 * 4. a WRITE or DELETE tool: trusted (its result reports the platform's own write).
 * 5. every other tool (READ, WORKSPACE, no effect declared): UNTRUSTED.
 *
 * Untrusted is the default: a tool that reads platform records only says so; a forgotten declaration costs a consent
 * question, never an injected write.
 */
class MCPToolContentTrust
{
    /** A write after an untrusted read runs as any write (the package default; the gate is opt-in) */
    public const string WRITE_AFTER_UNTRUSTED_READ_OFF = 'off';

    /** The write after an untrusted read waits for the owner's consent (the owner-consent check) */
    public const string WRITE_AFTER_UNTRUSTED_READ_OWNER_CONSENT = 'ownerConsent';

    /** The write after an untrusted read is refused in that turn (the owner starts it with a new message) */
    public const string WRITE_AFTER_UNTRUSTED_READ_REFUSE = 'refuse';

    /** A fetch of a model-built URL nobody showed is refused after an untrusted read (the package default) */
    public const string UNSEEN_URL_AFTER_UNTRUSTED_READ_REFUSE = 'refuse';

    /** A fetch of a model-built URL after an untrusted read runs as any fetch */
    public const string UNSEEN_URL_AFTER_UNTRUSTED_READ_OFF = 'off';

    /** Whether an unknown `writeAfterUntrustedRead` value was reported in this process */
    protected static bool $unknownWriteAfterUntrustedReadModeReported = false;

    /** @var array<string, bool> tool name → untrusted, memoized per process */
    protected static array $isUntrustedByToolName = [];

    /**
     * @param ReflectionClass $packageReflectionClass
     * @param ReflectionMethod $toolMethod
     * @return bool
     */
    public static function isUntrustedToolMethod(ReflectionClass $packageReflectionClass, ReflectionMethod $toolMethod): bool
    {
        if ($toolMethod->getAttributes(MCPToolReturnsUntrustedContent::class) !== []
            || static::isDeclaredOnPackageOrParent($packageReflectionClass, MCPToolReturnsUntrustedContent::class)
        ) {
            return true;
        }
        if ($toolMethod->getAttributes(MCPToolReturnsTrustedContent::class) !== []
            || static::isDeclaredOnPackageOrParent($packageReflectionClass, MCPToolReturnsTrustedContent::class)
        ) {
            return false;
        }
        if (in_array($toolMethod->getName(), static::getTrustedToolNames(), true)) {
            return false;
        }
        foreach (static::getTrustedPackageClasses() as $trustedPackageClass) {
            if ($packageReflectionClass->getName() === ltrim($trustedPackageClass, '\\') || $packageReflectionClass->isSubclassOf(ltrim($trustedPackageClass, '\\'))) {
                return false;
            }
        }
        $effectAttributes = $toolMethod->getAttributes(MCPToolEffect::class);
        if ($effectAttributes !== []) {
            /** @var MCPToolEffect $toolEffect */
            $toolEffect = $effectAttributes[0]->newInstance();
            if (in_array($toolEffect->effect, [MCPToolEffect::WRITE, MCPToolEffect::DELETE], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param ReflectionClass $packageReflectionClass
     * @param string $attributeClass
     * @return bool the package class or one of its parents declares the attribute (an application's subclass of a
     *              package keeps its parent's declaration)
     */
    protected static function isDeclaredOnPackageOrParent(ReflectionClass $packageReflectionClass, string $attributeClass): bool
    {
        for ($currentClass = $packageReflectionClass; $currentClass instanceof ReflectionClass; $currentClass = $currentClass->getParentClass()) {
            if ($currentClass->getAttributes($attributeClass) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * The rule for a tool known only by name (a stored call): unknown names (meta tools, removed tools) are trusted.
     * @param string $toolName
     * @return bool
     */
    public static function isUntrustedToolName(string $toolName): bool
    {
        if (array_key_exists($toolName, static::$isUntrustedByToolName)) {
            return static::$isUntrustedByToolName[$toolName];
        }
        $toolMethod = MCPToolAttributeCatalog::getToolMethodsByToolName()[$toolName] ?? null;
        $isUntrusted = $toolMethod !== null && static::isUntrustedToolMethod($toolMethod->getDeclaringClass(), $toolMethod);
        static::$isUntrustedByToolName[$toolName] = $isUntrusted;
        return $isUntrusted;
    }

    /**
     * The rule for a tool among candidate packages (the executor's view): the first package exposing the name.
     * @param string $toolName
     * @param string[] $packageClasses
     * @return bool
     */
    public static function isUntrustedToolInPackages(string $toolName, array $packageClasses): bool
    {
        foreach ($packageClasses as $packageClass) {
            if (!class_exists($packageClass) || !method_exists($packageClass, $toolName)) {
                continue;
            }
            $packageReflectionClass = new ReflectionClass($packageClass);
            $toolMethod = $packageReflectionClass->getMethod($toolName);
            if ($toolMethod->getAttributes(MCPTool::class) !== []) {
                return static::isUntrustedToolMethod($packageReflectionClass, $toolMethod);
            }
        }
        return static::isUntrustedToolName($toolName);
    }

    /**
     * What a write after an untrusted read of the same turn gets (`writeAfterUntrustedRead`): `off` (the default, the
     * write runs as any write), `ownerConsent` (the owner-consent check) or `refuse`. A missing value is `off`; an
     * unknown value is `off` too and is reported once per process in the issues log.
     *
     * @return string WRITE_AFTER_UNTRUSTED_READ_*
     */
    public static function getWriteAfterUntrustedReadMode(): string
    {
        $configuredMode = Config::get('AI.untrustedContent.writeAfterUntrustedRead');
        if ($configuredMode === null || $configuredMode === static::WRITE_AFTER_UNTRUSTED_READ_OFF) {
            return static::WRITE_AFTER_UNTRUSTED_READ_OFF;
        }
        if (in_array($configuredMode, [static::WRITE_AFTER_UNTRUSTED_READ_OWNER_CONSENT, static::WRITE_AFTER_UNTRUSTED_READ_REFUSE], true)) {
            return $configuredMode;
        }
        static::reportUnknownWriteAfterUntrustedReadModeOnce($configuredMode);
        return static::WRITE_AFTER_UNTRUSTED_READ_OFF;
    }

    /**
     * What a fetch of a model-built URL whose query string or fragment nobody showed gets after an untrusted read
     * (`unseenUrlAfterUntrustedRead`, {@see MCPToolFetchesModelChosenUrl}): `refuse` (the default) or `off`. Any value
     * other than `off` is `refuse`.
     *
     * @return string UNSEEN_URL_AFTER_UNTRUSTED_READ_*
     */
    public static function getUnseenUrlAfterUntrustedReadMode(): string
    {
        return Config::get('AI.untrustedContent.unseenUrlAfterUntrustedRead') === static::UNSEEN_URL_AFTER_UNTRUSTED_READ_OFF
            ? static::UNSEEN_URL_AFTER_UNTRUSTED_READ_OFF
            : static::UNSEEN_URL_AFTER_UNTRUSTED_READ_REFUSE;
    }

    /** Reports an unknown `writeAfterUntrustedRead` value once per process (warning); the mode is then `off`. */
    protected static function reportUnknownWriteAfterUntrustedReadModeOnce(mixed $configuredMode): void
    {
        if (static::$unknownWriteAfterUntrustedReadModeReported) {
            return;
        }
        static::$unknownWriteAfterUntrustedReadModeReported = true;
        $warning = new RuntimeException(
            'AI.untrustedContent.writeAfterUntrustedRead has the unknown value ' . json_encode($configuredMode)
            . "; it reads as 'off'. Use 'off', 'ownerConsent' or 'refuse'."
        );
        try {
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable($warning, LogLevel::WARNING);
        } catch (Throwable) {
            error_log('MCPToolContentTrust: ' . $warning->getMessage());
        }
    }

    /**
     * @return string[]
     */
    public static function getTrustedToolNames(): array
    {
        $trustedToolNames = Config::get('AI.untrustedContent.trustedTools');
        return is_array($trustedToolNames) ? array_values(array_filter($trustedToolNames, 'is_string')) : [];
    }

    /**
     * @return string[]
     */
    public static function getTrustedPackageClasses(): array
    {
        $trustedPackageClasses = Config::get('AI.untrustedContent.trustedPackages');
        return is_array($trustedPackageClasses) ? array_values(array_filter($trustedPackageClasses, 'is_string')) : [];
    }

    /**
     * Forgets the memoized answers (tests, a changed configuration).
     * @return void
     */
    public static function resetMemo(): void
    {
        static::$isUntrustedByToolName = [];
    }
}
