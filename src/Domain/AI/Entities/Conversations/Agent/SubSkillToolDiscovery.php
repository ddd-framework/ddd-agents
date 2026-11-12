<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables;

/**
 * How the chat model learns what lies BELOW a skill. The skills form a hierarchy so that the catalog of the system
 * prompt does not grow with every new skill:
 *  - the catalog lists the TOP level only, one line of fixed length per skill; the line speaks for the whole
 *    sub-tree, it names no child;
 *  - a LOADED skill lists its direct sub-skills in its own block, one line each (name and purpose); they are
 *    loaded with `load_skills` like any skill;
 *  - the tools of a skill are visible once that skill is loaded, never before.
 * The cost of a request therefore depends on what is loaded, never on how many skills or tools exist. This is the
 * mode `hierarchy`, the default.
 *
 * The other modes are the arms the hierarchy was measured against; they list the TOOLS of the unloaded sub-skills
 * and grow with the number of those tools: `catalogIndex` (the sub-skill's catalog line lists its tools with their
 * summaries), `loadTool` (the `load_tool` catalog lists them), `both`, `loadToolByName` (`load_tool` lists the names,
 * one line per sub-skill), `off` (the sub-skills join the catalog with their description, nothing else).
 *
 * `AGENT_SUB_SKILL_TOOL_DISCOVERY` (then the further name the application declares) selects the mode; unset = `hierarchy`.
 */
class SubSkillToolDiscovery
{
    public const string MODE_HIERARCHY = 'hierarchy';
    public const string MODE_OFF = 'off';
    public const string MODE_CATALOG_INDEX = 'catalogIndex';
    public const string MODE_LOAD_TOOL = 'loadTool';
    public const string MODE_LOAD_TOOL_BY_NAME = 'loadToolByName';
    public const string MODE_BOTH = 'both';

    public const string ENVIRONMENT_VARIABLE = AgentEnvironmentVariables::SUB_SKILL_TOOL_DISCOVERY;

    public static function resolveMode(): string
    {
        $configuredMode = '';
        foreach (AgentEnvironmentVariables::getNamesInReadOrder(self::ENVIRONMENT_VARIABLE) as $environmentVariableName) {
            $configuredValue = $_ENV[$environmentVariableName] ?? $_SERVER[$environmentVariableName] ?? getenv($environmentVariableName);
            $configuredMode = is_string($configuredValue) ? trim($configuredValue) : '';
            if ($configuredMode !== '') {
                break;
            }
        }
        foreach ([self::MODE_HIERARCHY, self::MODE_OFF, self::MODE_CATALOG_INDEX, self::MODE_LOAD_TOOL, self::MODE_LOAD_TOOL_BY_NAME, self::MODE_BOTH] as $mode) {
            if (strcasecmp($configuredMode, $mode) === 0) {
                return $mode;
            }
        }
        return self::MODE_HIERARCHY;
    }

    /** True: the catalog shows the top level only and a loaded skill lists its direct sub-skills in its own block. */
    public static function listsSubSkillsUnderLoadedParent(): bool
    {
        return self::resolveMode() === self::MODE_HIERARCHY;
    }

    public static function listsToolsInCatalog(): bool
    {
        return in_array(self::resolveMode(), [self::MODE_CATALOG_INDEX, self::MODE_BOTH], true);
    }

    public static function offersToolsThroughLoadTool(): bool
    {
        return in_array(self::resolveMode(), [self::MODE_LOAD_TOOL, self::MODE_LOAD_TOOL_BY_NAME, self::MODE_BOTH], true);
    }

    public static function listsSubSkillToolsByNameOnly(): bool
    {
        return self::resolveMode() === self::MODE_LOAD_TOOL_BY_NAME;
    }
}
