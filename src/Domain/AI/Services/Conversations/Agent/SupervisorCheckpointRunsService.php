<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SupervisorTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointBriefingHeader;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointOutcome;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorCheckpointTrigger;
use DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorDecisionAcceptance;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The supervisor's execution (layer L3): which beat a supervised conversation owes (the
 * settled beat, the step beat), waking its TANDEM supervisor conversation, the tandem's checkpoint turn and its one
 * model call, and the single-writer materialization of the supervisor's steers onto the supervised conversation. The
 * supervisor's own model call goes through the loop ({@see AgentModelCallRunnerInterface}), its rows are written under the
 * tandem's run; {@see \DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService} keeps the
 * checkpoint records.
 */
class SupervisorCheckpointRunsService extends Service
{
    use AITraceLogTrait;

    /**
     * The SETTLED beat for a turn driven SYNCHRONOUSLY (the eval harness, the CLI chat): those paths answer through
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::generateAgentReply()} and never pass the worker's settle branch, so the beat is called explicitly after the
     * turn — exactly like {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::settlePurposeConversationIfGoalReached()}. Returns whether the tandem was woken.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function runSupervisorSettledBeatAfterSyncTurn(AIConversation $conversation): bool
    {
        try {
            return $this->dispatchSupervisorCheckpointIfDue($conversation);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (\Throwable $beatError) {
            $this->aiTrace('supervisor.settledBeat.failed', ['supervisedConversationId' => $conversation->id ?? null, 'message' => $beatError->getMessage()]);
            return false;
        }
    }

    /** Whether a conversation is supervised at all (cheap guards — no tandem read). */
    public function supervisionAppliesTo(AIConversation $conversation): bool
    {
        if (!$conversation->canBeSupervised()) {
            return false;
        }
        /** @var SupervisorCheckpointService $supervisorCheckpointService */
        $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
        return $supervisorCheckpointService->runCap($conversation) > 0 && $conversation->isSupervisableByPurpose();
    }

    /**
     * SETTLED beat (single-writer design): after a settled owner
     * interaction the supervised side only WAKES the tandem — it writes nothing into it. The tandem reads the supervised
     * rows itself, decides the beat class and writes its own briefing and decision (one writer per conversation).
     */
    public function dispatchSupervisorCheckpointIfDue(AIConversation $conversation): bool
    {
        if (!$this->supervisionAppliesTo($conversation)) {
            return false;
        }
        return $this->wakeSupervisorTandem($conversation, SupervisorCheckpointService::BEAT_SETTLED);
    }

    /**
     * STEP beat: after a persisted tool batch with material (a write, a failed call, a widget) the running loop calls
     * the tandem — nothing else; the tandem catches up on every row since its last covered index.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function dispatchSupervisorStepBeatIfDue(AIConversation $conversation, int $highestMessageIndexBeforeStep): void
    {
        try {
            if (!$this->supervisionAppliesTo($conversation)) {
                return;
            }
            if (SupervisorCheckpointTrigger::buildStepBeatReason($conversation->messages, $highestMessageIndexBeforeStep) === null) {
                return;
            }
            $this->wakeSupervisorTandem($conversation, SupervisorCheckpointService::BEAT_STEP);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (\Throwable $stepBeatError) {
            $this->aiTrace('supervisor.stepBeat.failed', ['supervisedConversationId' => $conversation->id ?? null, 'message' => $stepBeatError->getMessage()]);
        }
    }

    /** Get-or-create the 1:1 tandem and dispatch its resume (a claim held by a running tandem drops it — the loop re-reads). */
    public function wakeSupervisorTandem(AIConversation $conversation, string $origin): bool
    {
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        $supervisorConversation = $conversationsService->findSupervisorConversationFor((int)$conversation->id);
        if ($supervisorConversation === null) {
            $supervisorConversation = $this->newConversation();
            $supervisorConversation->inheritSupervisionScopeFrom($conversation);
            $supervisorConversation->update(0);
        }
        $this->aiTrace('supervisor.tandem.woken', ['supervisedConversationId' => $conversation->id, 'supervisorConversationId' => $supervisorConversation->id ?? null, 'origin' => $origin]);
        $this->resumeConversationAsync($supervisorConversation);
        return true;
    }

    /** The supervised conversation of a tandem, freshly loaded with its derive tail; null when gone. */
    public function loadSupervisedConversationOf(AIConversation $supervisorConversation): ?AIConversation
    {
        $supervisedConversationId = $supervisorConversation->supervisedConversationId ?? null;
        if ($supervisedConversationId === null) {
            return null;
        }
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        $supervisedConversation = $conversationsService->find((int)$supervisedConversationId, useEntityRegistrCache: false);
        if (!$supervisedConversation instanceof AIConversation) {
            return null;
        }
        $supervisedConversation->messages = $this->messagesService()->findContextTailMessages($supervisedConversation);
        return $supervisedConversation;
    }

    /**
     * The next beat the tandem owes, or null ({@see SupervisorCheckpointTrigger::buildOwed()} under the supervised
     * conversation's run cap, read once). Shared by the tandem loop, the re-fire after its release and the close's
     * pending check.
     */
    public function nextSupervisorBeatOwed(AIConversation $supervisorConversation, AIConversation $supervisedConversation, SupervisorCheckpointService $supervisorCheckpointService): ?SupervisorCheckpointTrigger
    {
        return SupervisorCheckpointTrigger::buildOwed(
            $supervisorConversation->messages->buildSupervisorCheckpointHistory(),
            $supervisedConversation,
            $supervisorCheckpointService->runCap($supervisedConversation)
        );
    }

    /**
     * After the tandem's release: a wake that met the tandem's claim was dropped, so a beat still owed on the supervised
     * conversation re-fires the tandem once (async). `$supervisorConversation` carries its reloaded derive tail.
     */
    public function resumeTandemIfABeatIsStillOwed(AIConversation $supervisorConversation): void
    {
        $supervisedConversation = $this->loadSupervisedConversationOf($supervisorConversation);
        /** @var SupervisorCheckpointService $supervisorCheckpointService */
        $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
        if ($supervisedConversation !== null && $this->nextSupervisorBeatOwed($supervisorConversation, $supervisedConversation, $supervisorCheckpointService) !== null) {
            $this->aiTrace('supervisor.checkpoint.reFiredAfterRelease', ['supervisorConversationId' => $supervisorConversation->id ?? null]);
            $this->resumeConversationAsync($supervisorConversation);
        }
    }

    /**
     * The TANDEM's turn (single writer): catch up on the supervised conversation — read its rows, infer the
     * beat, write the briefing itself, decide in ONE call, repeat while new material arrived meanwhile (≤ 4 rounds).
     * An accepted steer or correction resumes the supervised conversation async (its own worker materializes it); a
     * settled round without intervention re-evaluates the purpose close. Best-effort: a failure is traced, never thrown.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function runSupervisorCheckpointTurn(AgentRun $agentRun, AgentModelCallRunnerInterface $agentModelCallRunner, AIConversation $conversation, ?string $runToken): void
    {
        // The SUPERVISOR conversation's own bypass (copied from the supervised one at creation) lifts the cap for the
        // tandem's rounds: a grant on the tandem's turn envelope (the resume worker's — this runs only on the worker
        // path), restored in the `finally`.
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $tandemBudgetBypassGrantRestore = $conversation->budgetBypass
            ? $aIUsageEnvelopesService->grantBudgetBypassUntilRestored(
                AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS)
            )
            : null;
        try {
            /** @var SupervisorCheckpointService $supervisorCheckpointService */
            $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
            /** @var AIConversationAuditRenderService $auditRenderService */
            $auditRenderService = DDDService::instance()->getService(AIConversationAuditRenderService::class);
            $modelName = $supervisorCheckpointService->resolveModelName($conversation);
            $operatingPrompt = $supervisorCheckpointService->getDefaultOperatingPrompt();
            $anySignal = false;
            $lastBeat = null;
            $supervisedConversation = null;
            for ($round = 0; $round < 4; $round++) {
                $supervisedConversation = $this->loadSupervisedConversationOf($conversation);
                if ($supervisedConversation === null) {
                    break;
                }
                $owed = $this->nextSupervisorBeatOwed($conversation, $supervisedConversation, $supervisorCheckpointService);
                if ($owed === null) {
                    break;
                }
                $agentSpaceStateText = null;
                if ($owed->beat === SupervisorCheckpointService::BEAT_SETTLED) {
                    try {
                        // the state of the supervised conversation's space; a conversation without a space works at
                        // account level and its state reads run there (the policy of the conversation names the reads)
                        $agentSpaceStateText = $auditRenderService->renderAgentSpaceState($supervisedConversation->agentSpace ?? null, $supervisedConversation, 1500);
                    } catch (\Throwable $stateError) {
                        $this->aiTrace('supervisor.agentSpaceState.failed', ['supervisedConversationId' => $supervisedConversation->id ?? null, 'message' => $stateError->getMessage()]);
                    }
                    // The state is LIVE: an owner action that landed while it rendered (a widget submit removing
                    // entries) shows in the state but not in the rows up to the owed index — the supervisor read
                    // the owner's removal as a lost write and added the entries again. Re-target at the newest row.
                    $freshSupervisedConversation = $this->loadSupervisedConversationOf($conversation);
                    $freshNewestIndex = $freshSupervisedConversation !== null ? $freshSupervisedConversation->messages->getNextIndex() - 1 : -1;
                    if ($freshNewestIndex > $owed->newestMessageIndex && $round < 3) {
                        $this->aiTrace('supervisor.settled.retargeted', ['supervisedConversationId' => $supervisedConversation->id ?? null, 'owedNewest' => $owed->newestMessageIndex, 'freshNewest' => $freshNewestIndex]);
                        continue;
                    }
                }
                $briefing = $supervisorCheckpointService->buildBriefing(
                    $supervisedConversation,
                    $owed->newestMessageIndex,
                    $owed->reason,
                    $owed->coveredMessageIndex >= 0 ? $owed->coveredMessageIndex : null,
                    $owed->beat,
                    $agentSpaceStateText
                );
                $throwawayTransientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
                $briefingMessage = $this->messagesService()->appendNewMessage(
                    $conversation,
                    $throwawayTransientMessageIds,
                    AIConversationMessage::MESSAGE_TEXT,
                    $briefing,
                    role: AIConversationMessage::ROLE_USER,
                    authorType: AIConversationMessage::AUTHOR_USER
                );
                $this->messagesService()->persistMessageUnderRun($agentRun, $briefingMessage);
                $checkpointOutcome = $this->runOneSupervisorCheckpoint($agentRun, $agentModelCallRunner, $conversation, $supervisedConversation, $briefingMessage, $modelName, $operatingPrompt, $supervisorCheckpointService);
                $lastBeat = $owed->beat;
                if ($checkpointOutcome->signalText !== null) {
                    $anySignal = true;
                    $this->resumeConversationAsync($supervisedConversation);
                }
            }
            if (!$anySignal && $lastBeat === SupervisorCheckpointService::BEAT_SETTLED && $supervisedConversation !== null) {
                // reloaded right before the close: the round's copy predates its decision call, and a corrective turn
                // that finished meanwhile must be reviewed first (its rows make a beat owed again)
                $supervisedConversation = $this->loadSupervisedConversationOf($conversation) ?? $supervisedConversation;
                $this->purposeRunsService()->closeConversationIfPurposeGoalReached($agentRun, $supervisedConversation);
            }
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (\Throwable $supervisorError) {
            $this->aiTrace('supervisor.checkpoint.failed', [
                'supervisorConversationId' => $conversation->id ?? null,
                'supervisedConversationId' => $conversation->supervisedConversationId ?? null,
                'message' => $supervisorError->getMessage(),
            ]);
        } finally {
            $aIUsageEnvelopesService->restoreBudgetBypassGrant($tandemBudgetBypassGrantRestore);
        }
    }

    /**
     * ONE checkpoint: a single decision call over the briefing the turn just wrote (`$briefingMessage`; its header is the
     * covered index and the beat — the thinking does the work, the visible output is one short terminal call); a second call only when the first produced no valid decision. The reasoning effort follows
     * the beat (step: the config's `stepReasoningEffort`, settled: `reasoningEffort`). Every terminal call passes the
     * acceptance chain ({@see SupervisorDecisionAcceptance}); its row is written here. Returns the beat and the signal
     * text an accepted steer/correction materializes (null = no intervention). A response without a tool call decides
     * nothing.
     */
    public function runOneSupervisorCheckpoint(
        AgentRun $agentRun,
        AgentModelCallRunnerInterface $agentModelCallRunner,
        AIConversation $conversation,
        ?AIConversation $supervisedConversation,
        AIConversationMessage $briefingMessage,
        ?string $modelName,
        string $operatingPrompt,
        SupervisorCheckpointService $supervisorCheckpointService
    ): SupervisorCheckpointOutcome {
        $briefingHeader = SupervisorCheckpointBriefingHeader::parseFrom($briefingMessage) ?? new SupervisorCheckpointBriefingHeader();
        $supervisorDecisionAcceptance = SupervisorDecisionAcceptance::forBriefing(
            $briefingHeader,
            (string)($briefingMessage->message ?? ''),
            $supervisedConversation?->messages->getNewestInitiatorMessageIndex() ?? 0,
            $supervisorCheckpointService->findSupervisorPolicyFor($supervisedConversation)
        );
        $beat = $supervisorDecisionAcceptance->beat;
        $conversation->messages->buildSupervisorCheckpointHistory()->projectEarlierCheckpointsForTheModel();
        $effort = $supervisorCheckpointService->findSupervisorSettings()->getEffortForBeat($beat);
        if (!isset($conversation->runSettings)) {
            $conversation->runSettings = RunSettings::newInstance();
        }
        $conversation->runSettings->fixedReasoningEffort = $effort;
        $toolSchemas = SupervisorTools::toOpenAiToolSchemas();
        $decisionSignalText = null;
        $throwawayTransientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
        for ($modelCall = 0; $modelCall < SupervisorTools::MAX_MODEL_CALLS_PER_CHECKPOINT && !$supervisorDecisionAcceptance->decided; $modelCall++) {
            $modelResponse = $agentModelCallRunner->performModelCall($conversation, $toolSchemas, 'required', $modelName, $operatingPrompt);
            $toolCalls = $modelResponse->toolCalls?->getElements() ?? [];
            if ($toolCalls === []) {
                // A tool-less answer is NO decision: the text row is kept for the
                // admin console, the second call follows, then the budget-exhausted row — the live checkpoint never
                // parses a model's prose (the terminal tools are offered with `tool_choice: required`).
                $textMessage = $this->messagesService()->appendNewMessage(
                    $conversation,
                    $throwawayTransientMessageIds,
                    AIConversationMessage::MESSAGE_TEXT,
                    (string)($modelResponse->text ?? ''),
                    role: AIConversationMessage::ROLE_ASSISTANT,
                    authorType: AIConversationMessage::AUTHOR_ASSISTANT,
                    requestSnapshot: $modelResponse->requestSnapshot,
                    aiModelName: $modelResponse->modelName
                );
                $textMessage->applyUsage($modelResponse);
                $this->messagesService()->persistMessageUnderRun($agentRun, $textMessage);
                continue;
            }
            $stepHeadPending = true;
            foreach ($toolCalls as $toolCall) {
                $callMessage = $this->messagesService()->appendNewMessage(
                    $conversation,
                    $throwawayTransientMessageIds,
                    AIConversationMessage::MESSAGE_TOOL_CALL,
                    '',
                    authorType: AIConversationMessage::AUTHOR_ASSISTANT,
                    requestSnapshot: $stepHeadPending ? $modelResponse->requestSnapshot : null,
                    aiModelName: $modelResponse->modelName
                );
                $toolCall->status = AIConversationMessage::STATUS_PENDING;
                $callMessage->toolCall = $toolCall;
                if ($stepHeadPending) {
                    $stepHeadPending = false;
                    $callMessage->applyUsage($modelResponse);
                    if (($callMessage->inputTokens ?? 0) <= 0) {
                        $callMessage->inputTokens = 1;
                    }
                }
                $acceptedSignalText = $supervisorDecisionAcceptance->judgeToolCall($toolCall, $conversation->messages->buildSupervisorCheckpointHistory(), $supervisedConversation);
                $decisionSignalText = $decisionSignalText ?? $acceptedSignalText;
                $this->messagesService()->persistMessageUnderRun($agentRun, $callMessage);
            }
        }
        if (!$supervisorDecisionAcceptance->decided) {
            $noDecisionMessage = $this->messagesService()->appendNewMessage(
                $conversation,
                $throwawayTransientMessageIds,
                AIConversationMessage::MESSAGE_TEXT,
                SupervisorTools::RESULT_BUDGET_EXHAUSTED,
                role: AIConversationMessage::ROLE_ASSISTANT,
                authorType: AIConversationMessage::AUTHOR_AGENT
            );
            $this->messagesService()->persistMessageUnderRun($agentRun, $noDecisionMessage);
        }
        $this->aiTrace('supervisor.checkpoint.decided', [
            'supervisorConversationId' => $conversation->id ?? null,
            'supervisedConversationId' => $supervisedConversation?->id,
            'beat' => $beat,
            'effort' => $effort,
            'coveredMessageIndex' => $supervisorDecisionAcceptance->coveredMessageIndex,
            'intervened' => $decisionSignalText !== null,
        ]);
        return SupervisorCheckpointOutcome::create($beat, $decisionSignalText);
    }

    /**
     * Whether the tandem supervisor still OWES work on this conversation — the close's deferral predicate:
     * (a) the newest seed briefing has no verdict yet (a checkpoint is in flight), or (b) the newest verdict
     * carries a STEER that this conversation has not materialized yet (the single-writer model materializes it
     * on the next drive — the close must wait for that corrective turn). No tandem = nothing pending.
     */
    public function supervisorWorkIsPendingFor(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        $supervisorConversation = $conversationsService->findSupervisorConversationFor((int)$conversation->id);
        if ($supervisorConversation === null) {
            return false;
        }
        $supervisorConversation->messages = $this->messagesService()->findContextTailMessages($supervisorConversation);
        /** @var SupervisorCheckpointService $supervisorCheckpointService */
        $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
        // the tandem has not covered the newest supervised rows yet (single writer: it catches up itself)
        if (isset($conversation->messages) && $this->nextSupervisorBeatOwed($supervisorConversation, $conversation, $supervisorCheckpointService) !== null) {
            return true;
        }
        // an intervention not yet materialized — its turn must run before the close
        return $this->hasUnconsumedInterventionFor($conversation, $supervisorConversation, $supervisorCheckpointService);
    }

    /**
     * After the supervised conversation's OWN release: an intervention the tandem accepted while that turn still held the
     * run claim lost its wake (the claim drops a concurrent resume) and nothing else re-fires it — the close then waits
     * for it forever. Resume the supervised conversation once (async); its next drive turn materializes the
     * intervention. Returns whether it resumed. Best-effort: a failed read resumes nothing.
     */
    public function resumeSupervisedConversationIfAnInterventionWaits(AIConversation $conversation): bool
    {
        if (!isset($conversation->id) || !$this->supervisionAppliesTo($conversation)) {
            return false;
        }
        /** @var AIConversationsService $conversationsService */
        $conversationsService = AIConversation::getService();
        $supervisorConversation = $conversationsService->findSupervisorConversationFor((int)$conversation->id);
        if ($supervisorConversation === null) {
            return false;
        }
        $supervisorConversation->messages = $this->messagesService()->findContextTailMessages($supervisorConversation);
        /** @var SupervisorCheckpointService $supervisorCheckpointService */
        $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
        if (!$this->hasUnconsumedInterventionFor($conversation, $supervisorConversation, $supervisorCheckpointService)) {
            return false;
        }
        $this->aiTrace('supervisor.intervention.reFiredAfterSupervisedRelease', ['supervisedConversationId' => $conversation->id, 'supervisorConversationId' => $supervisorConversation->id ?? null]);
        $this->resumeConversationAsync($conversation);
        return true;
    }

    /** Whether the tandem holds an accepted steer or correction the supervised conversation has not consumed yet. */
    protected function hasUnconsumedInterventionFor(AIConversation $conversation, AIConversation $supervisorConversation, SupervisorCheckpointService $supervisorCheckpointService): bool
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        $lastConsumedDecisionId = $aIConversationMessagesService->findLastConsumedSupervisorTurnMessageId((int)$conversation->id);
        return $supervisorCheckpointService->buildDecisionsAfterMessageId($supervisorConversation, $lastConsumedDecisionId)->hasIntervention();
    }

    /**
     * SINGLE-WRITER materialization of the supervisor's steers onto the SUPERVISED conversation.
     *
     * Runs at the START of the supervised conversation's own drive turn — where THIS worker already holds the run
     * claim ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::resumeConversationIfReady()} step 1), making it the sole legitimate appender of this conversation's
     * message rail. It reads the tandem supervisor conversation's verdicts and appends each NOT-yet-materialized one
     * as a user-invisible `SUPERVISOR_STEERING` SIGNAL, carrying the cause→effect rung `supervisorTurnMessageId`.
     *
     * WHY this replaced the supervisor-side cross-write: the old {@see applySupervisorSteeringSignal} appended onto the
     * supervised rail FROM the supervisor worker, concurrently with the supervised conversation's own drive worker.
     * Both derive `indexInConversation` client-side from a loaded snapshot; under an active drive (the eval's instant
     * simulated owner, and fast live chats) the two collided on the identity map and the steer's `signalSource` bled
     * onto a foreign drive-row while the steer TEXT never became its own row — steers silently corrupted/lost. A
     * single writer per conversation removes the race entirely.
     *
     * Idempotent + compaction-robust via the MONOTONIC consumed marker
     * ({@see AIConversationMessagesService::findLastConsumedSupervisorTurnMessageId}): only verdicts newer than the
     * last materialized one are appended, so redelivery / a re-driven turn never double-appends and an evicted older
     * steer is never resurrected. A healthy verdict (no concrete signal) materializes nothing. Best-effort — any
     * failure is logged and swallowed; a missed steer is picked up on the next turn, and it never wedges the drive.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function materializeUnconsumedSupervisorSteers(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || $conversation->isDelegation() || $conversation->isSupervisorTandem()) {
            return;
        }
        try {
            /** @var SupervisorCheckpointService $supervisorCheckpointService */
            $supervisorCheckpointService = DDDService::instance()->getService(SupervisorCheckpointService::class);
            // Supervision fully OFF (run cap 0) also stops SIGNAL INJECTION — a decision that already exists must not
            // steer a conversation whose supervision the admin / the A-B measurement turned off.
            if ($supervisorCheckpointService->runCap($conversation) <= 0) {
                return;
            }
            /** @var AIConversationsService $conversationsService */
            $conversationsService = AIConversation::getService();
            $supervisorConversation = $conversationsService->findSupervisorConversationFor((int)$conversation->id);
            if ($supervisorConversation === null) {
                return; // not a supervised conversation → nothing to materialize
            }
            $supervisorConversation->messages = $this->messagesService()->findContextTailMessages($supervisorConversation);
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $lastConsumedDecisionId = $aIConversationMessagesService->findLastConsumedSupervisorTurnMessageId((int)$conversation->id);

            $transientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
            foreach ($supervisorCheckpointService->buildDecisionsAfterMessageId($supervisorConversation, $lastConsumedDecisionId)->getElements() as $supervisorCheckpointDecision) {
                if ($supervisorCheckpointDecision->signalText === null) {
                    continue; // a silent approval, a refused decision → no intervention
                }
                // STALENESS: the decision named an expected tool and a call of that tool COMPLETED after
                // the evidence the decision rests on — the driver may already have fixed it.
                $isStale = $supervisorCheckpointDecision->isStaleAgainst($conversation->messages);
                $expectedTool = $supervisorCheckpointDecision->expectedTool;
                $signalText = $supervisorCheckpointDecision->signalText;
                // Batch sv6: a steer whose expected tool is not among the loaded skills' tools fell back to a plain
                // 'required' pin and the agent never executed it (forget without the Memory skill). Name the skill.
                if (!$isStale && $expectedTool !== null && !in_array($expectedTool, $conversation->messages->getNewestLoadedCapabilities()?->getLoadedToolNames() ?? [], true)) {
                    $signalText = $supervisorCheckpointDecision->buildSignalTextWithSkillHint(
                        array_values(array_diff(AISkill::getSkillNamesProvidingToolName($expectedTool), $conversation->messages->getLoadedSkillNames()))
                    );
                }
                if ($isStale) {
                    // The driver ran the expected tool AFTER the decision's evidence (a step beat judged a batch whose
                    // next step did the write anyway; a settled correction the approval turn already covered): no
                    // pinned step, no correction turn, no owner message — a resolved note consumes the decision. The
                    // next checkpoint sees that write in its ledger and steers again if its VALUES are wrong.
                    $signalText = $supervisorCheckpointDecision->buildResolvedNoteText();
                }
                $signalMessage = $this->messagesService()->appendNewMessage(
                    $conversation,
                    $transientMessageIds,
                    AIConversationMessage::MESSAGE_SIGNAL,
                    $signalText,
                    role: AIConversationMessage::ROLE_SYSTEM,
                    authorType: AIConversationMessage::AUTHOR_AGENT,
                    signalSource: $supervisorCheckpointDecision->getSignalSourceForMaterialization($isStale)
                );
                $signalMessage->supervisorTurnMessageId = $supervisorCheckpointDecision->decisionMessageId;
                $this->messagesService()->persistNewRowUnderRunClaim($conversation, $signalMessage);
                $this->aiTrace('supervisor.steering.materialized', [
                    'supervisedConversationId' => $conversation->id,
                    'supervisorTurnMessageId' => $supervisorCheckpointDecision->decisionMessageId,
                    'correction' => $supervisorCheckpointDecision->isCorrection,
                ]);
            }
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (\Throwable $materializeError) {
            $this->aiTrace('supervisor.steering.materialize.failed', [
                'supervisedConversationId' => $conversation->id ?? null,
                'message' => $materializeError->getMessage(),
            ]);
        }
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /**
     * Enqueue the conversation's resume job — the loop's async resume ({@see AIConversationAgentService::resumeConversationIfReady()}
     * with `async: true`); a test double replaces it.
     */
    protected function resumeConversationAsync(AIConversation $conversation): void
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = DDDService::instance()->getService(AIConversationAgentService::class);
        $aIConversationAgentService->resumeConversationIfReady($conversation, async: true);
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return $aIConversationPurposeRunsService;
    }

    /**
     * Construction method for a blank conversation (the tandem supervisor conversation) — overridden in container-less unit tests to
     * reflection-construct it.
     */
    protected function newConversation(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = AIConversationMessages::newInstance();
        return $conversation;
    }
}
