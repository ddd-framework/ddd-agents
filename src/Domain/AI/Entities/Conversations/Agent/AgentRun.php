<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolRestriction;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AIConversationTurnOptions;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AIConversationTurnTimings;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyToolSet;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Infrastructure\Services\DDDService;

/**
 * ONE invocation of the agent loop for one conversation — a plain mutable
 * runtime object, never persisted, never resolved from DI and never stored on a service: it is created by the entry that
 * starts the run (the worker resume, a sync drive, an inline delegation child) and passed as a PARAMETER to every method
 * of the run, because a `sync://` transport job can run inside another job and an inline child and the supervisor
 * tandem run on the same service instance. Holds what one run measures and how it does its side work; decides nothing.
 *
 * The run token is NOT held here (the documented exception to the rule against process-wide state): the process registry of
 * {@see AIConversationRunClaimsService::getRunTokenClaimedInThisProcess()} stays the only authority for "which claim does this
 * process hold", because the claim fences writes the run never sees (tools, a purpose's code, a tool subprocess that
 * adopted its parent's token); it records a fact of the process and the worker job reset clears it. The run only reads
 * it ({@see findRunTokenClaimedInThisProcess()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentRun
{
    /** How this run does its side work (the recall embedding of its rows). */
    public AIConversationTurnOptions $turnOptions;

    /** What this run measures (turn-start phases, step pre-call timings, persist breakdowns) — telemetry only. */
    public AIConversationTurnTimings $turnTimings;

    /** When the worker received the job this run serves; null on a run no worker job started. */
    public ?AgentWorkerJobReceipt $workerJobReceipt = null;

    /**
     * @var array<int, array<string, true>> Conversation id → the purpose-declared background warm-ups (operation + tool
     *      name + the `url` argument + the purpose's cache identity) this run already dispatched: a
     *      second write step of the same run must not spawn a second identical child.
     */
    public array $backgroundWarmOperationsDispatched = [];

    /**
     * @var int[][] Conversation id → `delegate` row id → the `hrtime` deadline (ns) until which this
     *      run waits (bounded) before its next model call for that platform sub-agent's run end (a purpose's grace
     *      period, a read that follows a slow write).
     */
    public array $subAgentRunsAwaitedBeforeNextStep = [];

    /**
     * Whether this run's conversations have enabled A2A push configs, for the realtime publishes of the run; null for the rows persisted outside a run (the realtime service keeps their memo).
     */
    public ?AIConversationTaskPushNotificationConfigMemo $pushConfigMemo = null;

    /**
     * @var array<int, string|null> Root conversation id → its purpose name (null = none), read once per run: a
     *      purpose is stamped once at creation, a rename is seen by the next run.
     */
    public array $purposeNameByRootConversationId = [];

    /**
     * @var array<string, AIConversationPurpose|null> Purpose name → the purpose entity the background warm-up asks
     *      after each step, resolved once per run: the entity is built from immutable config and its
     *      warm-up answer reads only its config and its arguments, so one instance serves every step of the run.
     */
    public array $purposesForBackgroundWarmByName = [];

    /**
     * The model-call steps the current owner turn had consumed when this run's loop started (derived from the
     * persisted usage-stamped messages — crash / park / chunk-safe, no stored counter).
     */
    public int $stepsConsumedBeforeInvocation = 0;

    /** The step of this run's loop (0 = its first model call; restarts with every worker chunk). */
    public int $invocationStep = 0;

    /** The step limit of this run's loop, re-derived before every step (a plan created mid-turn raises it). */
    public int $invocationStepLimit = 0;

    /**
     * Whether a sub-agent of the conversation had an unfinished run when this run's loop started —
     * read once per loop, only on a turn sub-agent notifications opened; the offer of `wait_for_sub_agents` reads it.
     */
    public bool $hasUnfinishedSubAgentRunAtLoopStart = false;

    /**
     * How this run's model calls talk to the model: resolved and validated once at the loop start;
     * null before the loop resolved it (a collaborator's call resolves its own).
     */
    public ?AgentWireConfiguration $agentWireConfiguration = null;

    /**
     * The offered tools the current step holds back while it keeps them on the wire (append-only
     * loading never narrows the cached `tools`); the dispatch refuses their calls. Set before every model call of the
     * loop; null = the step holds nothing back on the wire (the system-prompt swap narrows the array itself).
     */
    public ?AgentStepToolRestriction $stepToolRestriction = null;

    /**
     * The id of the final text the forced `send_owner_report` step follows — that text is the report the call
     * binds, never a line the forced step writes beside its call. Set when the step is armed, used up by that step.
     */
    public int|string|null $ownerReportSourceMessageIdOfForcedStep = null;

    /**
     * @var array<string, LoadedCapabilities|null> `<conversation>:<index>` → the capability set at an append-only prefix
     *      epoch's start: covered rows are settled, so one read per run serves every model call.
     */
    public array $prefixEpochBaseCapabilitiesByKey = [];

    /**
     * @var AgentAppendOnlyToolSet[] conversation key → which tools its append-only epoch carries top-level:
     *      the purpose, the entitlement and the catalog do not change inside a run, so one decision
     *      serves the offer and the epoch of every model call.
     */
    public array $appendOnlyToolSetsByConversationKey = [];

    /**
     * @var array<string, \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeliveryBaseValues|null> conversation
     *      key → the base-prompt values of a `onceWhenChanged` conversation, resolved once per run.
     */
    public array $stateDeliveryBaseValuesByConversationKey = [];

    /** Why this run's last loop stopped (the release after a turn that ended waiting for its sub-agents); null before. */
    public ?AgentLoopStopReason $lastLoopStopReason = null;

    /** A run started by a sync entry (the CLI, the eval, the HTTP drive): default options, no turn-start stamps. */
    public static function forTurnEntry(): static
    {
        $agentRun = new static();
        $agentRun->turnOptions = new AIConversationTurnOptions();
        $agentRun->turnTimings = new AIConversationTurnTimings();
        $agentRun->pushConfigMemo = new AIConversationTaskPushNotificationConfigMemo();
        return $agentRun;
    }

    /**
     * A run started by a worker resume: the turn-start stamps open with the job's wall-clock origin (null fields when
     * no receipt is known); their first phase measures from the receipt's hrtime.
     */
    public static function forWorkerResume(?AgentWorkerJobReceipt $agentWorkerJobReceipt): self
    {
        $agentRun = self::forTurnEntry();
        $agentRun->workerJobReceipt = $agentWorkerJobReceipt;
        $agentRun->turnTimings->turnStartStamps = [
            'jobReceivedWallUs' => $agentWorkerJobReceipt?->receivedWallUs,
            'dispatchedAtWallUs' => $agentWorkerJobReceipt?->dispatchedAtWallUs,
            'ownerRowSavedWallUs' => $agentWorkerJobReceipt?->ownerRowSavedWallUs,
        ];
        $agentRun->turnTimings->turnStartStampLastHr = null;
        return $agentRun;
    }

    /**
     * The run of an inline (sync) delegation child inside `$parentRun`: its own timings and memos, the
     * parent's options (the child does its side work the way the parent does).
     */
    public static function forInlineChild(AgentRun $parentRun): static
    {
        $agentRun = new static();
        $agentRun->turnOptions = $parentRun->turnOptions;
        $agentRun->turnTimings = new AIConversationTurnTimings();
        $agentRun->pushConfigMemo = new AIConversationTaskPushNotificationConfigMemo();
        return $agentRun;
    }

    /**
     * What a row persisted OUTSIDE a run reads (a cron pass, an owner message appended between turns, a late report):
     * the options the caller keeps for such rows, timings nobody reads, no push-config memo (the realtime service's).
     */
    public static function outsideARun(AIConversationTurnOptions $turnOptionsOutsideARun): static
    {
        $agentRun = new static();
        $agentRun->turnOptions = $turnOptionsOutsideARun;
        $agentRun->turnTimings = new AIConversationTurnTimings();
        return $agentRun;
    }

    /**
     * The run token this process holds for the conversation (null when it holds none: a claimless CLI or eval drive,
     * a lost claim) — read from the process registry, the one authority (see the class docblock). `find…`: it crosses
     * into the run claims service.
     */
    public function findRunTokenClaimedInThisProcess(AIConversation $conversation): ?string
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation);
    }

    /**
     * Record the next turn-start phase: the milliseconds since the previous stamp, or since the job was received (the
     * first phase), or 0 when neither is known. No-op on a run without turn-start stamps (a sync entry).
     */
    public function stampTurnStartPhase(string $phase): void
    {
        if ($this->turnTimings->turnStartStamps === null) {
            return;
        }
        $now = hrtime(true);
        $previous = $this->turnTimings->turnStartStampLastHr ?? ($this->workerJobReceipt->receivedHr ?? $now);
        $this->turnTimings->turnStartStamps[$phase] = (int)round(($now - $previous) / 1e6);
        $this->turnTimings->turnStartStampLastHr = $now;
        $this->turnTimings->preFirstStepPartStampLastHr = $now;
    }

    /**
     * Record one part of the work between the system prompt and step 1 (`preFirstStep`) under
     * `turnStartStamps.preFirstStepParts`: the milliseconds since the previous part or phase stamp, added up when a
     * part repeats. The phase stamps stay as they are — the parts split them, they never move them. No-op on a run
     * without turn-start stamps.
     */
    public function stampPreFirstStepPart(string $part): void
    {
        if ($this->turnTimings->turnStartStamps === null) {
            return;
        }
        $now = hrtime(true);
        $previous = $this->turnTimings->preFirstStepPartStampLastHr ?? $this->turnTimings->turnStartStampLastHr ?? $now;
        $preFirstStepParts = $this->turnTimings->turnStartStamps['preFirstStepParts'] ?? [];
        $preFirstStepParts[$part] = ($preFirstStepParts[$part] ?? 0) + (int)round(($now - $previous) / 1e6);
        $this->turnTimings->turnStartStamps['preFirstStepParts'] = $preFirstStepParts;
        $this->turnTimings->preFirstStepPartStampLastHr = $now;
    }
}
