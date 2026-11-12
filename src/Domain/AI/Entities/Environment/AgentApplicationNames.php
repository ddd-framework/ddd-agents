<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Environment;

use DDD\Infrastructure\Libs\Config;

/**
 * The names an application gives to what the agent core writes outside a conversation: the prefix of its trace lines
 * in the log, the label of the assistant's lines on the console, and a further name per environment variable of the
 * core under which the application's hosts set it. Read from `config/app/AI/agentApplicationNames.php`
 * (`Config::get('AI.agentApplicationNames')`); an application replaces that file whole with its own (keys are never
 * merged). A key the application's file leaves out falls back to the default of this class.
 */
class AgentApplicationNames
{
    public const string CONFIG_NAME = 'AI.agentApplicationNames';

    /** The prefix of every trace line when the application declares none. */
    public const string DEFAULT_TRACE_LINE_PREFIX = '[agent:trace]';

    /** The label of the assistant's lines on the console when the application declares none. */
    public const string DEFAULT_CONSOLE_ASSISTANT_LABEL = 'agent';

    /** The prefix of every trace line the core writes to the log ({@see \DDD\Domain\AI\Traits\Conversations\AITraceLogTrait}). */
    public static function getTraceLinePrefix(): string
    {
        $traceLinePrefix = static::readDeclaredValue('traceLinePrefix');
        return is_string($traceLinePrefix) && $traceLinePrefix !== '' ? $traceLinePrefix : static::DEFAULT_TRACE_LINE_PREFIX;
    }

    /** The label in front of the assistant's lines on the console and in a console transcript (`<label>> text`). */
    public static function getConsoleAssistantLabel(): string
    {
        $consoleAssistantLabel = static::readDeclaredValue('consoleAssistantLabel');
        return is_string($consoleAssistantLabel) && $consoleAssistantLabel !== '' ? $consoleAssistantLabel : static::DEFAULT_CONSOLE_ASSISTANT_LABEL;
    }

    /**
     * The further name under which the application's hosts set one environment variable of the core
     * ({@see AgentEnvironmentVariables}); null when the application declares none for it.
     */
    public static function getFurtherEnvironmentVariableName(string $environmentVariableName): ?string
    {
        $furtherEnvironmentVariableNames = static::readDeclaredValue('furtherEnvironmentVariableNames');
        $furtherName = is_array($furtherEnvironmentVariableNames) ? ($furtherEnvironmentVariableNames[$environmentVariableName] ?? null) : null;
        return is_string($furtherName) && $furtherName !== '' ? $furtherName : null;
    }

    protected static function readDeclaredValue(string $key): mixed
    {
        $declaredNames = Config::get(static::CONFIG_NAME);
        return is_array($declaredNames) ? ($declaredNames[$key] ?? null) : null;
    }
}
