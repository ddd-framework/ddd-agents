<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\LoadableSubSkillTools;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The `load_tool` meta-tool — the lazy
 * promotion `available → loaded` ONE level below {@see LoadSkillsTool}. In LAZY mode
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings::$loadToolsOnSkillLoad} = false) a
 * loaded skill's tools start "available": their {@see \DDD\Domain\AI\Entities\MCPPackages\ToolDefinition::$summary}
 * (#[MCPToolSummary]) is listed in THIS catalog, but the full schema is NOT resident. The model calls `load_tool` to
 * promote the tools it needs to "loaded" (their full schema enters the canonical `tools` array next turn). The wire
 * shape lives here, mirroring {@see LoadSkillsTool::toOpenAiToolSchema()}; the loop offers it only when ≥1 available tool is unloaded.
 *
 * @phpstan-consistent-constructor
 */
class LoadToolTool extends ValueObject implements AgentMetaTool
{
    public const string NAME = 'load_tool';

    /** The effect the stored-call readers see ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog}). */
    public const ?string DECLARED_EFFECT = MCPToolEffect::READ;

    /** The AVAILABLE tools (of the currently loaded skills) this catalog offers — their names become the enum, their summaries the help text. */
    protected ToolDefinitions $availableTools;

    /** @var string[] tool names already LOADED (resident) — excluded from the enum so the model cannot re-load them. */
    protected array $alreadyLoadedToolNames = [];

    /**
     * The tools of the loaded roots' NOT YET LOADED sub-skills (each with its sub-skill and one-line summary): offered
     * beside the available tools, so a tool that lives in a sub-skill is named to the model once its root is loaded.
     * Loading one loads its sub-skill.
     */
    protected LoadableSubSkillTools $subSkillTools;

    /** True: the sub-skill tools are listed by name, one line per sub-skill; false: one line per tool with its summary. */
    protected bool $listsSubSkillToolsByNameOnly = false;

    /** True while the owner is on the phone line: a catalog line falling back to the description renders its phone sections. */
    protected bool $ownerIsOnThePhoneLine = false;

    public function __construct()
    {
        parent::__construct();
        unset($this->objectType);
        $this->subSkillTools = new LoadableSubSkillTools();
    }

    /**
     * @param string[] $alreadyLoadedToolNames tools already resident — removed from the offered enum (mirror
     *   of LoadSkillsTool excluding already-loaded skills).
     * @param LoadableSubSkillTools|null $subSkillTools the tools of the loaded roots' not yet loaded sub-skills;
     *   null = none
     */
    public static function forAvailableTools(
        ToolDefinitions $availableTools,
        array $alreadyLoadedToolNames = [],
        ?LoadableSubSkillTools $subSkillTools = null,
        bool $listsSubSkillToolsByNameOnly = false,
        bool $ownerIsOnThePhoneLine = false
    ): static {
        $tool = new static();
        $tool->ownerIsOnThePhoneLine = $ownerIsOnThePhoneLine;
        $tool->availableTools = $availableTools;
        $tool->alreadyLoadedToolNames = $alreadyLoadedToolNames;
        $tool->subSkillTools = $subSkillTools ?? new LoadableSubSkillTools();
        $tool->listsSubSkillToolsByNameOnly = $listsSubSkillToolsByNameOnly;
        return $tool;
    }

    /** @return string[] the available tool names still loadable (available minus already-loaded). */
    public function getLoadableToolNames(): array
    {
        $loadableToolNames = [];
        foreach ($this->availableTools->getElements() as $toolDefinition) {
            if (!in_array($toolDefinition->name, $this->alreadyLoadedToolNames, true)) {
                $loadableToolNames[] = $toolDefinition->name;
            }
        }
        foreach ($this->subSkillTools->getToolNames() as $subSkillToolName) {
            if (!in_array($subSkillToolName, $loadableToolNames, true) && !in_array($subSkillToolName, $this->alreadyLoadedToolNames, true)) {
                $loadableToolNames[] = $subSkillToolName;
            }
        }
        return $loadableToolNames;
    }

    /** True when at least one available tool is still unloaded — the loop omits the tool entirely when none remain. */
    public function hasLoadableTools(): bool
    {
        return $this->getLoadableToolNames() !== [];
    }

    /** Nothing for the loop to do after this tool's call. */
    public static function afterStep(ToolCall $toolCall): ?AgentAfterStepRequest
    {
        return null;
    }

    /** The call loads the tools it names, a not-yet-loaded sub-skill first. */
    public static function workRequest(ToolCall $toolCall): ?AgentToolCallWorkRequest
    {
        return AgentToolCallWorkRequest::loadTools($toolCall->arguments);
    }

    public function getToolName(): string
    {
        return self::NAME;
    }

    /** @return array<string, mixed> The OpenAI function-tool envelope. */
    public function toOpenAiToolSchema(): array
    {
        $names = [];
        $lines = [];
        foreach ($this->availableTools->getElements() as $toolDefinition) {
            if (in_array($toolDefinition->name, $this->alreadyLoadedToolNames, true)) {
                continue; // already loaded → not offered for (re)loading
            }
            $names[] = $toolDefinition->name;
            $summary = ($toolDefinition->summary !== null && $toolDefinition->summary !== '')
                ? $toolDefinition->summary
                : $toolDefinition->renderDescription($this->ownerIsOnThePhoneLine);
            $lines[] = "- {$toolDefinition->name} — {$summary}";
        }
        $toolNamesBySubSkillName = [];
        foreach ($this->subSkillTools->tools as $subSkillTool) {
            $subSkillToolName = $subSkillTool->toolName;
            if (in_array($subSkillToolName, $names, true) || in_array($subSkillToolName, $this->alreadyLoadedToolNames, true)) {
                continue;
            }
            $names[] = $subSkillToolName;
            if ($this->listsSubSkillToolsByNameOnly) {
                $toolNamesBySubSkillName[$subSkillTool->subSkillName][] = $subSkillToolName;
                continue;
            }
            $lines[] = "- {$subSkillToolName} — {$subSkillTool->summary} (sub-skill {$subSkillTool->subSkillName}, loaded with the tool)";
        }
        foreach ($toolNamesBySubSkillName as $subSkillName => $toolNamesOfSubSkill) {
            $lines[] = "- sub-skill {$subSkillName} (loaded with its tool): " . implode(', ', $toolNamesOfSubSkill);
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                // the wording names the sub-skills only while their tools are listed
                'description' => ($this->subSkillTools->isEmpty()
                    ? 'Load one or more tools from a skill you already have, to gain their full parameters. '
                        . 'Call when a task needs a tool listed below that is not yet available; callable again at any step. '
                    : 'Load one or more of the tools listed below to gain their full parameters: tools of a skill '
                        . 'you already have, and tools of its sub-skills. Call when a task needs one of them — never work around '
                        . 'a listed tool by hand; callable again at any step. ')
                    . "Available tools:\n" . implode("\n", $lines),
                'parameters' => [
                    'type' => 'object',
                    'required' => ['tool_names'],
                    'properties' => [
                        'tool_names' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $names],
                            'description' => 'Tool names from the available list to load.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
