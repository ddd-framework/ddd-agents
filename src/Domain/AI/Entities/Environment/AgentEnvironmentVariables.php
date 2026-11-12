<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Environment;

/**
 * The environment variables the agent core reads, by their names. Each reader reads the core's name first, then the
 * further name the application declares for the same variable
 * ({@see AgentApplicationNames::getFurtherEnvironmentVariableName()}) — hosts that set a variable under a name of
 * their application keep working. How a reader reads one name (raw process environment, `.env` files through
 * `Config::getEnv()`, or both) stays the reader's own; this class names the variables and their read order only.
 */
class AgentEnvironmentVariables
{
    /** `skill` / `tool`: overrides the configured unloading unit ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings}). */
    public const string UNLOAD_GRANULARITY = 'AGENT_UNLOAD_GRANULARITY';

    /** The sub-skill tool discovery mode ({@see \DDD\Domain\AI\Entities\Conversations\Agent\SubSkillToolDiscovery}). */
    public const string SUB_SKILL_TOOL_DISCOVERY = 'AGENT_SUB_SKILL_TOOL_DISCOVERY';

    /** `litellm`: routes this process's agent model calls through the configured LiteLLM proxy endpoint (test only). */
    public const string EGRESS = 'AGENT_EGRESS';

    /** `1` / `true`: writes the agent's trace lines to the log (local runs). */
    public const string TRACE = 'AGENT_TRACE';

    /**
     * The names to read for one variable, in order: the given name, then the further name the application declares
     * for it (when it declares one).
     *
     * @return string[]
     */
    public static function getNamesInReadOrder(string $environmentVariableName): array
    {
        $furtherName = AgentApplicationNames::getFurtherEnvironmentVariableName($environmentVariableName);
        return $furtherName === null ? [$environmentVariableName] : [$environmentVariableName, $furtherName];
    }

    /**
     * The value of the first of the names (in read order) that is set to a non-empty string in the raw process
     * environment (`$_SERVER`, then `getenv()`); null when none is.
     */
    public static function readRawProcessValue(string $environmentVariableName): ?string
    {
        foreach (static::getNamesInReadOrder($environmentVariableName) as $nameToRead) {
            $rawValue = $_SERVER[$nameToRead] ?? null;
            if ($rawValue === null) {
                $shellValue = getenv($nameToRead);
                $rawValue = $shellValue === false ? null : $shellValue;
            }
            if (is_scalar($rawValue) && (string)$rawValue !== '') {
                return (string)$rawValue;
            }
        }
        return null;
    }
}
