<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes;

/**
 * The `Supervisor.Checkpoint` purpose: the TANDEM oversight conversation that runs
 * 1:1 alongside a supervised conversation ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$supervisedConversationId}).
 * Each checkpoint is a TURN in it — the seed USER message is the briefing (the delta-context of that turn),
 * the ASSISTANT message is the verdict JSON. System-spawned only ({@see $startableViaApi} = false): the agent
 * loop opens/continues it at checkpoints, it never has an owner or a standalone start.
 *
 * This class owns the purpose-individual behaviour: the model-scope the supervisor runs under. The former
 * checkpoint-trigger classes (cadence / milestone / event / close) were removed —
 * the step/settled beats decide when a checkpoint runs. The briefing composition + signal parsing live in
 * the supervisor service, not here (they touch the supervised conversation's repos).
 */
class SupervisorCheckpointPurpose extends AIConversationPurpose
{
    /** The ModelScope the supervisor's turns resolve their model through ({@see \DDD\Domain\AI\Entities\Models\Selection\ModelScope::ORCHESTRATOR}). */
    public const string MODEL_SCOPE = 'ORCHESTRATOR';
}
