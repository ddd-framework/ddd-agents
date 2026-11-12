<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The tools one model call of the loop offers (formerly a bare schema array the loop filtered
 * in four places): the seeded-plan purpose's set, the forced first step's set, the plan-tick brake's set, the work
 * tools and the stall re-drive's set are each this set without some named tools. A schema without a name is never
 * removed, as before.
 *
 * @phpstan-consistent-constructor
 */
class AgentOfferedTools extends ValueObject
{
    /** @var array<int, array<string, mixed>> The OpenAI-shaped tool schemas, in the order they are offered */
    protected array $toolSchemas = [];

    /** @var string[] the domain tools of this offer a sub-agent's tool run may call ({@see offersToolForASubAgentToolRun()}) */
    protected array $subAgentToolRunToolNames = [];

    /**
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public static function fromToolSchemas(array $toolSchemas): static
    {
        $offeredTools = new static();
        $offeredTools->toolSchemas = array_values($toolSchemas);
        return $offeredTools;
    }

    /**
     * The offer of one model call, pure (formerly `AgentToolSchemasService::buildToolSchemas()`):
     * the meta tools in their fixed order, then the resident schemas of the loaded skills, then `load_tool` last. Every
     * tool is a VO that emits its own wire shape; the inputs are gathered by
     * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildOfferedTools()}.
     */
    public static function buildFor(OfferedSkillCatalog $offeredSkillCatalog, AgentOfferedToolsInputs $agentOfferedToolsInputs): self
    {
        $skillCatalog = $offeredSkillCatalog->skills;
        $loadSkillsTool = LoadSkillsTool::forCatalog($skillCatalog, $offeredSkillCatalog->alreadyLoadedSkillNames);
        $toolSchemas = [];
        if ($loadSkillsTool->hasLoadableSkills()) {
            $toolSchemas[] = $loadSkillsTool->toOpenAiToolSchema();
        }
        if ($agentOfferedToolsInputs->allowThreads) {
            $toolSchemas[] = DelegateTool::forCatalog($skillCatalog)->toOpenAiToolSchema();
            // The status read is offered from the conversation's first `delegate` row on and stays —
            // position-stable after the first spawn, one change of the cached prefix per conversation (it used to come
            // and go with a running delegation, two prefix changes per delegation).
            if ($agentOfferedToolsInputs->hasDelegateCall || $agentOfferedToolsInputs->offersConditionalMetaToolsFromTheStart) {
                $toolSchemas[] = CheckDelegationsTool::create()->toOpenAiToolSchema();
                // The message to a sub-agent rides with the status read — the same stable position
                $toolSchemas[] = MessageSubAgentTool::create()->toOpenAiToolSchema();
            }
            // The end of a turn without a text — only on a turn sub-agent notifications opened while
            // another sub-agent still runs; an owner's turn never sees it (the owner waits for an answer)
            if ($agentOfferedToolsInputs->offersWaitForSubAgents) {
                $toolSchemas[] = WaitForSubAgentsTool::create()->toOpenAiToolSchema();
            }
        }
        // BOTH plan meta-tools are offered (like load_skills) — static schemas, position-stable,
        // so they never invalidate the prompt-cache prefix; the discipline text lives in Agentic.md, not here.
        // update_task_plan = STRUCTURE (create/rebuild, the nested shape, ~once per run); set_task_status = the
        // cheap per-step PROGRESS tick (flat updates addressed by the rendered item numbers). A plan the
        // purpose seeded is never rewritten — update_task_plan stays off the wire for it (from the seed on, stable).
        if ($agentOfferedToolsInputs->offersUpdateTaskPlan) {
            $toolSchemas[] = UpdateTaskPlanTool::create()->toOpenAiToolSchema();
        }
        $toolSchemas[] = SetTaskStatusTool::create()->toOpenAiToolSchema();
        // askOwner: the closed question with the model's own answer variants — universal, static,
        // position-stable like the plan tools; the platform renders the widget at turn end.
        $toolSchemas[] = AskOwnerTool::create()->toOpenAiToolSchema($agentOfferedToolsInputs->ownerIsOnThePhoneLine);
        // findWidgets: the widget catalog search — static and position-stable right after askOwner, on a
        // screen surface only (a widget renders nowhere else)
        if ($agentOfferedToolsInputs->offersFindWidgets) {
            $toolSchemas[] = FindWidgetsTool::create()->toOpenAiToolSchema();
        }
        // the finished-plans read — offered only while finished plans exist AND the plan-dynamics site
        // guides the conversation (a byte-identical tool surface otherwise)
        if (($agentOfferedToolsInputs->finishedTaskPlanCount > 0 && $agentOfferedToolsInputs->isGuidedByPlanDynamics)
            || $agentOfferedToolsInputs->offersConditionalMetaToolsFromTheStart
        ) {
            $toolSchemas[] = GetTaskPlansTool::create()->toOpenAiToolSchema();
        }
        // the owner-report SEND tool — offered ONLY on unattended EMAIL runs whose report is not
        // yet delivered (enum-exclusion: after the send the schema disappears next step; a normal chat never
        // sees it, so the surface stays lean everywhere else).
        if ($agentOfferedToolsInputs->reportChannel === AIConversationSchedule::REPORT_CHANNEL_EMAIL
            && $agentOfferedToolsInputs->initiatorType !== null
            && (!$agentOfferedToolsInputs->reportIsDelivered || $agentOfferedToolsInputs->offersConditionalMetaToolsFromTheStart)
        ) {
            $toolSchemas[] = SendOwnerReportTool::create()->toOpenAiToolSchema();
        }
        // UNIFORM tool surface: the RESIDENT tools (the anchor's TOOL items) emit their full schema;
        // every other tool of the loaded skills is "available" via the load_tool catalog. A tool the eviction unloaded
        // simply drops back to "available".
        $loadedSkillToolDefinitions = $agentOfferedToolsInputs->loadedSkillToolDefinitions;
        $residentToolNames = $agentOfferedToolsInputs->residentToolNames;
        // The purpose's `blockedToolNames` (the root's for a delegation child) leave the wire — resident schema and
        // load_tool catalog alike. The executor refuses such a call anyway; a tool the model cannot see it does not
        // call, and a refusal it never saw it cannot relay as "I could not do X". The loop holds no tool name here.
        if ($agentOfferedToolsInputs->purposeBlockedToolNames !== []) {
            $loadedSkillToolDefinitions = $loadedSkillToolDefinitions->withoutNames($agentOfferedToolsInputs->purposeBlockedToolNames);
            $residentToolNames = array_values(array_diff($residentToolNames, $agentOfferedToolsInputs->purposeBlockedToolNames));
        }
        if ($agentOfferedToolsInputs->appendOnlyReachableToolDefinitions !== null) {
            // the epoch's whole reachable domain tool set (the purpose's blocked tools already removed)
            foreach ($agentOfferedToolsInputs->appendOnlyReachableToolDefinitions->getElements() as $toolDefinition) {
                $toolSchemas[] = $toolDefinition->toOpenAiToolSchema($agentOfferedToolsInputs->descriptionPrefixesOfToolArgumentsFilledFromAgentSpace, $agentOfferedToolsInputs->ownerIsOnThePhoneLine);
            }
        } else {
            foreach ($loadedSkillToolDefinitions->getElements() as $toolDefinition) {
                if (in_array($toolDefinition->name, $residentToolNames, true)) {
                    $toolSchemas[] = $toolDefinition->toOpenAiToolSchema($agentOfferedToolsInputs->descriptionPrefixesOfToolArgumentsFilledFromAgentSpace, $agentOfferedToolsInputs->ownerIsOnThePhoneLine);
                }
            }
        }
        // Offer the load_tool catalog for the still-available (unloaded / evicted) tools of the loaded skills AND for
        // the tools of the loaded roots' not-yet-loaded sub-skills: a tool that lives in a sub-skill is thereby named
        // to the model (name + one line) as soon as its root is loaded, and one load_tool call reaches it.
        $loadTool = LoadToolTool::forAvailableTools(
            $loadedSkillToolDefinitions,
            $residentToolNames,
            $agentOfferedToolsInputs->loadableSubSkillTools,
            $agentOfferedToolsInputs->listsSubSkillToolsByNameOnly,
            $agentOfferedToolsInputs->ownerIsOnThePhoneLine
        );
        // append-only loading offers no `load_tool` (its enum would change with every load)
        if ($loadTool->hasLoadableTools() && !$agentOfferedToolsInputs->loadsSkillsAppendOnly) {
            $toolSchemas[] = $loadTool->toOpenAiToolSchema();
        }
        $agentOfferedTools = self::fromToolSchemas($toolSchemas);
        $residentDomainToolNames = [];
        foreach ($loadedSkillToolDefinitions->getElements() as $toolDefinition) {
            if (in_array($toolDefinition->name, $residentToolNames, true)) {
                $residentDomainToolNames[] = $toolDefinition->name;
            }
        }
        $agentOfferedTools->subAgentToolRunToolNames = array_values(array_unique([...$residentDomainToolNames, ...$loadTool->getLoadableToolNames()]));
        return $agentOfferedTools;
    }

    /**
     * Whether a sub-agent's tool run may call `$toolName`: a tool of the loaded
     * skills offered in this call — resident or in the `load_tool` catalog — and never a meta tool or a tool the purpose
     * blocks. Known only for a set built by {@see buildFor()}.
     */
    public function offersToolForASubAgentToolRun(string $toolName): bool
    {
        return in_array($toolName, $this->subAgentToolRunToolNames, true);
    }

    /**
     * This set without the tools named in $toolNames; the order of the rest is kept.
     *
     * @param string[] $toolNames
     */
    public function withoutTools(array $toolNames): self
    {
        return self::fromToolSchemas(array_filter(
            $this->toolSchemas,
            static fn (array $schema): bool => !in_array($schema['function']['name'] ?? null, $toolNames, true)
        ));
    }

    public function isEmpty(): bool
    {
        return $this->toolSchemas === [];
    }

    public function offersTool(string $toolName): bool
    {
        return in_array($toolName, $this->getToolNames(), true);
    }

    /**
     * The offered tools' names, in order; a schema without a name is left out.
     *
     * @return string[]
     */
    public function getToolNames(): array
    {
        return array_values(array_filter(array_map(static fn (array $schema): ?string => $schema['function']['name'] ?? null, $this->toolSchemas)));
    }

    /**
     * The schemas as the model call sends them as `tools`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toOpenAiToolSchemas(): array
    {
        return $this->toolSchemas;
    }
}
