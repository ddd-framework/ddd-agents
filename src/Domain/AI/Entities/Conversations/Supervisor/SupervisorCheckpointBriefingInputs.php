<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Supervisor;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCoverToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a supervisor checkpoint briefing needs from the world: the purpose from the
 * registry, the tool digest from the catalog, the replayed task plans, the audit renders, the live state of the space, the
 * criteria file, the FACTS and the compaction cover's counts. The purpose's lists, texts and FACTS come from its
 * {@see SupervisorPolicy}. Gathered by
 * {@see \DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService::buildBriefingInputs()}; rendered by
 * {@see SupervisorCheckpointBriefing} together with the supervised conversation's loaded rows.
 *
 * The parts a beat does not show are not gathered: the static block's tool digest only for a full briefing, the audit
 * renders, the state of the space and the criteria only for a settled beat.
 */
class SupervisorCheckpointBriefingInputs extends ValueObject
{
    /** The supervised conversation's purpose (null = free dialog): the static block's contract, pitfalls and done criteria. */
    public ?AIConversationPurpose $purpose = null;

    /** @var string[] The static block's tool digest lines (empty on a delta). */
    public array $toolDigestLines = [];

    /** The static block's list of the widget types a tool renders and the owner answers on ('' = no WIDGETS section). */
    public string $ownerWidgetTypesDigest = '';

    /** @var string[] The reads the ledger renders in full (every other read is a count). */
    public array $groundingReadToolNames = [];

    /** The task plan the PLAN section shows: the stored plan at the newest row, else the replay as of the covered index. */
    public ?TaskPlan $taskPlan = null;

    /** Whether {@see $taskPlan} is the stored plan (no `*` marks — no stored history of earlier statuses). */
    public bool $taskPlanIsTheStoredPlan = false;

    /** The replay as of the delta's start, whose statuses the `*` marks compare against (null without a delta). */
    public ?TaskPlan $previousTaskPlan = null;

    /** The FACTS section (computed by the platform), shown on both beats ('' = none). */
    public string $factsSection = '';

    /** The audit render of the widgets the owner saw (settled beat only). */
    public ?string $widgetInventoryText = null;

    /** The audit render of every completed write with its arguments (settled beat only). */
    public ?string $writeLedgerText = null;

    /** The audit render of the outward actions (settled beat only). */
    public ?string $outwardActionsText = null;

    /** The audit render of the task plan with provenance; null = no plan (settled beat only). */
    public ?string $taskPlanWithProvenanceText = null;

    /** The live state of the space read by the caller (settled beat only; null or blank = no section). */
    public ?string $agentSpaceStateText = null;

    /** The headline of the live-state section, named by the purpose's policy ({@see SupervisorPolicy::getAgentSpaceStateHeadline()}). */
    public string $agentSpaceStateHeadline = SupervisorPolicy::DEFAULT_AGENT_SPACE_STATE_HEADLINE;

    /** The purpose's audit criteria ('' = no section; settled beat only). */
    public string $auditCriteria = '';

    /** The compaction cover's completed counts before the first loaded row the briefing covers (the TOTALS). */
    public AIConversationCoverToolTelemetry $coverToolTelemetry;
}
