<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Turns;

use DDD\Domain\AI\Entities\Conversations\Agent\LoadableSubSkillTools;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What decides the tools one model call is offered beside the skill catalog: the facts
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::buildOfferedTools()} reads from the conversation, its
 * anchor message and the world, handed to the pure {@see AgentOfferedTools::buildFor()}. Read once, never re-read.
 */
class AgentOfferedToolsInputs extends ValueObject
{
    /** Sub-threads are allowed (an orchestrator, not a delegation child): `delegate` is offered. */
    public bool $allowThreads = true;

    /** The conversation has a `delegate` row: `check_delegations` and `message_sub_agent` are offered from then on (only with sub-threads). */
    public bool $hasDelegateCall = false;

    /**
     * `wait_for_sub_agents` is offered: a root conversation, a turn opened by sub-agent notifications
     * only, and a sub-agent with an unfinished run at the loop's start.
     */
    public bool $offersWaitForSubAgents = false;

    /** The owner is on the phone line: `askOwner` and the tool descriptions render their phone sections. */
    public bool $ownerIsOnThePhoneLine = false;

    /**
     * `findWidgets` is offered: a root conversation whose owner reads the chat or talks in the app with the
     * chat open — a screen surface. Never on the phone line, in a sub-agent, a scheduled, platform or external-agent run.
     */
    public bool $offersFindWidgets = false;

    /**
     * `update_task_plan` is offered — not while the conversation's purpose seeded the plan it holds: a seeded plan is
     * worked, never rewritten, and the call would only be refused ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool::findSeededPlanRefusal()}).
     */
    public bool $offersUpdateTaskPlan = true;

    /** How many finished task plans the working state keeps. */
    public int $finishedTaskPlanCount = 0;

    /** The plan-dynamics site guides the conversation — asked only while finished plans exist (false otherwise). */
    public bool $isGuidedByPlanDynamics = false;

    /** The run's report channel (`EMAIL` on an unattended e-mail run). */
    public ?string $reportChannel = null;

    /** The run's initiator type (null for an owner's chat). */
    public ?string $initiatorType = null;

    /** The run's report is already delivered. */
    public bool $reportIsDelivered = false;

    /** The tool definitions of the anchor's loaded skills, gated by the entitlement (skills and tools). */
    public ToolDefinitions $loadedSkillToolDefinitions;

    /** @var string[] The tools resident on the anchor message (their full schema is offered). */
    public array $residentToolNames = [];

    /** @var string[] The tree purpose's `blockedToolNames` — off the wire, resident schema and `load_tool` alike. */
    public array $purposeBlockedToolNames = [];

    /**
     * @var string[] What the model reads in front of each tool argument the executor fills from the conversation's
     * space, by argument name; empty for a conversation without a space
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()}).
     */
    public array $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace = [];

    /** The tools of the loaded roots' not yet loaded sub-skills, each with its sub-skill and one-line summary. */
    public LoadableSubSkillTools $loadableSubSkillTools;

    /** The `load_tool` catalog names the sub-skill tools without their summary. */
    public bool $listsSubSkillToolsByNameOnly = false;

    /**
     * The conversation loads skills append-only — the `load_skills` enum is the whole reachable catalog
     * (loaded skills and sub-skills included; a repeat answers "already loaded"), so it stays the same for the epoch,
     * and `load_tool` is not offered (a loaded skill brings all its tools).
     */
    public bool $loadsSkillsAppendOnly = false;

    /**
     * The append-only epoch carries the purpose's whole reachable domain tool set top-level — these
     * definitions are offered in full instead of the loaded skills' resident tools (the order never changes inside the
     * epoch); null = the resident tools of the loaded skills, as before.
     */
    public ?ToolDefinitions $appendOnlyReachableToolDefinitions = null;

    /**
     * The conditional meta tools (`check_delegations`, `message_sub_agent`, `get_task_plans`, an e-mail
     * run's `send_owner_report` after its delivery) are offered from the start, so the top-level `tools` never change for
     * them; a call outside the condition gets its existing answer with a next move.
     */
    public bool $offersConditionalMetaToolsFromTheStart = false;

    public function __construct()
    {
        parent::__construct();
        $this->loadableSubSkillTools = new LoadableSubSkillTools();
    }
}
