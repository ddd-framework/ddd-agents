<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationSettledOwnerTurnListenersService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The follow-ups of a fully answered worker turn: a root dialog whose turn answered and
 * released IDLE without a re-fire ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease::$runsSettledTurnFollowUps})
 * runs, in this order, the schedule's completed-firing record, the memory curation, the supervisor checkpoint dispatch,
 * the purpose-goal close (allowed only when no checkpoint was dispatched) and the settled-owner-turn listeners. Each
 * step's owner is its own service; this service holds only the sequence and the catches. Every step after the schedule
 * record is best-effort: the answer is already persisted, so a failing step is swallowed and never flips the turn to
 * FAILED. The schedule record is not caught — a failure there reaches the caller's turn catch.
 */
class AIConversationTurnEndFollowUpsService extends Service
{
    use AITraceLogTrait;

    /**
     * Run the follow-ups of the answered turn `$agentRun` drove on `$conversation`, after its release.
     */
    public function runFollowUpsOfAnsweredTurn(AgentRun $agentRun, AIConversation $conversation): void
    {
        // a SCHEDULE-spawned run that fully settled = a COMPLETED firing — clear the
        // schedule's failure streak (no-op for every non-schedule conversation).
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        $aIConversationSchedulesService->recordRunTerminalOfConversation($conversation, true);
        // The user↔assistant turn fully SETTLED (root dialog, nothing new mid-turn) → harvest memories in
        // the background. The per-message memoryIngestedAt marker makes a re-dispatch
        // idempotent (only the still-unmarked delta is curated), so firing per settled turn is safe; a
        // debounce delay-stamp to collapse rapid-fire turns is a noted optimization, not a correctness need.
        // Best-effort, exactly like compaction: a harvest failure must NEVER flip a fully
        // settled, already-answered turn to FAILED — the answer is persisted, memory is off the critical path.
        try {
            /** @var AgentMemoriesService $agentMemoriesService */
            $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
            $agentMemoriesService->curateConversationAfterTurn($conversation, true);
        } catch (\Throwable) {
            // swallow — the turn already produced + persisted its answer; curation is best-effort background work
        }
        // Supervisor: a fully settled owner turn is the cadence beat — decide whether a
        // checkpoint is owed and, if so, dispatch the tandem supervisor async (never inline — the owner's
        // turn already answered, the checkpoint must not add latency). Best-effort: a dispatch failure is
        // swallowed exactly like memory curation; the next settled turn re-evaluates.
        $checkpointDispatched = false;
        try {
            /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
            $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
            $checkpointDispatched = $supervisorCheckpointRunsService->dispatchSupervisorCheckpointIfDue($conversation);
        } catch (\Throwable $settledBeatError) {
            // swallow — supervision is off the critical path; the answer is already persisted
            $this->aiTrace('supervisor.settledBeat.failed', ['supervisedConversationId' => $conversation->id ?? null, 'message' => $settledBeatError->getMessage()]);
        }
        // An intervention the tandem accepted while this turn held the claim lost its wake — re-fire it now (its own
        // catch: a failed dispatch above must not skip it). A supervised conversation's close is already held by the
        // dispatched checkpoint; its corrective turn's settle closes.
        try {
            /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
            $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
            $supervisorCheckpointRunsService->resumeSupervisedConversationIfAnInterventionWaits($conversation);
        } catch (\Throwable $interventionReFireError) {
            $this->aiTrace('supervisor.interventionReFire.failed', ['supervisedConversationId' => $conversation->id ?? null, 'message' => $interventionReFireError->getMessage()]);
        }
        // Purpose-goal COMPLETION + CLOSE: a fully settled turn whose purpose
        // declares its goal reached reconciles its plan and makes the goal DURABLE right away (e.g. a
        // completion record of the application — the owner's next click reads it); the CLOSE
        // (IDLE → COMPLETED) alone waits while the milestone checkpoint just dispatched or supervisor work
        // is still pending (the close-review's correction must be able to run; ITS settled turn closes).
        // Best-effort like the two above.
        try {
            /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
            $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
            $aIConversationPurposeRunsService->closeConversationIfPurposeGoalReached($agentRun, $conversation, !$checkpointDispatched);
        } catch (\Throwable) {
            // swallow — re-evaluated on the next settled turn / the supervisor completion path / the reaper
        }
        // Other domains react to the finished owner turn — for example a long
        // run of THIS chat's space that sits idle while the owner just wrote here: it takes in the
        // owner's lines now and is resumed. Best-effort per listener; the listener's own recovery cron
        // pass retries. Resolving the service and building a listener stay inside the same catch.
        try {
            /** @var AIConversationSettledOwnerTurnListenersService $aIConversationSettledOwnerTurnListenersService */
            $aIConversationSettledOwnerTurnListenersService = DDDService::instance()->getService(AIConversationSettledOwnerTurnListenersService::class);
            $aIConversationSettledOwnerTurnListenersService->notifyListenersOfSettledOwnerTurn($conversation);
        } catch (\Throwable) {
            // swallow — the chat's answer is persisted; the listener's own recovery delivers later
        }
    }
}
