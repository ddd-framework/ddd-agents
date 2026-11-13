<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanLeafClosings;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateRejection;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentToolCallWorkRequest;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentTaskPlanTickOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecision;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;

/**
 * The evidence behind a task plan's ticks: the purpose's done criteria and the inputs they
 * read for a tick (the record of the conversation's space, loaded once per call), the seeded leaves already satisfied at seed time, the
 * record closers after a completed call and at the turn end — and the application of the plan tools' calls (`update_task_plan`, `set_task_status`) onto the loaded plan, judged by those
 * criteria. The transitions themselves are
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan}'s.
 */
class AIConversationTaskPlanRecordService extends Service
{
    use AITraceLogTrait;

    /**
     * The done criteria a `set_task_status` call of `$conversation` is judged by: the registry of the
     * purpose of a purposed conversation ({@see AIConversationPurpose::getDoneCriteria()}), with or without a space — a
     * conversation without one is judged at account level; otherwise an
     * empty registry — no key has a criterion, so no record verdict gates the tick.
     */
    public function findDoneCriteriaForStatusTick(AIConversation $conversation): TaskPlanDoneCriteria
    {
        return $this->findPurposeJudgingTicksOfConversation($conversation)?->getDoneCriteria() ?? TaskPlanDoneCriteria::fromClasses();
    }

    /**
     * What the criteria of a `set_task_status` call read, built ONCE for the call: the purpose builds
     * its inputs ({@see AIConversationPurpose::buildDoneCriterionInputs()}) over the conversation and `$taskPlanItems` (the
     * LIVE plan the call mutates); a purpose that reads the record of the conversation's space loads it FRESH once — the
     * call writes the plan, never that record, so every lazy evaluation of the call reads the same record. A record that
     * cannot be loaded, or a builder that throws,
     * gives the domain-free inputs, over which no purpose's criterion answers (each narrows its inputs type). The inputs
     * of a purposed conversation with a space read the channel connections of that space through the purpose
     * ({@see TaskPlanDoneCriterionInputs::readChannelConnectionsThrough()}); without a space the connected-channel gate
     * is not applied.
     */
    public function buildDoneCriterionInputsForStatusTick(AIConversation $conversation, ?TaskPlanItems $taskPlanItems): TaskPlanDoneCriterionInputs
    {
        $conversationMessages = isset($conversation->messages) ? $conversation->messages : null;
        $aIConversationPurpose = $this->findPurposeJudgingTicksOfConversation($conversation);
        if ($aIConversationPurpose === null) {
            return TaskPlanDoneCriterionInputs::forConversation($conversation, $conversationMessages, $taskPlanItems);
        }
        $taskPlanDoneCriterionInputs = $this->buildPurposeInputsOverFreshRecord($aIConversationPurpose, $conversation, $taskPlanItems)
            ?? TaskPlanDoneCriterionInputs::forConversation($conversation, $conversationMessages, $taskPlanItems);
        // the channels are those of a space: a conversation without one has no reader of the connected-channel gate
        $agentSpace = $conversation->agentSpace ?? null;
        if ($agentSpace !== null) {
            $taskPlanDoneCriterionInputs->readChannelConnectionsThrough($aIConversationPurpose, $agentSpace);
        }
        return $taskPlanDoneCriterionInputs;
    }

    /**
     * The purpose's own inputs over the conversation (a purpose that reads the record of the conversation's space loads
     * it fresh now); null when the builder throws.
     */
    protected function buildPurposeInputsOverFreshRecord(AIConversationPurpose $aIConversationPurpose, AIConversation $conversation, ?TaskPlanItems $taskPlanItems): ?TaskPlanDoneCriterionInputs
    {
        try {
            return $aIConversationPurpose->buildDoneCriterionInputs($conversation, $taskPlanItems);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Hand the seeded/open leaves to the PURPOSE for pre-resolution against the live state of the conversation's space
     * (the purpose class owns the seed leaves' meaning). Delegates with the conversation's space to
     * {@see AIConversationPurpose::preResolveSeedTaskPlanItems()} — the base purpose resolves nothing; a purpose with
     * machine-checkable seed leaves owns their meaning and loads the record of the space FRESH (not the registry-cached
     * instance: the criteria read facets the turn's own writes just changed — a list saved seconds ago
     * must be what the criterion of its leaf sees). Returns the number of leaves resolved. A conversation without a
     * space is resolved at account level: the purpose gets no space and its criteria read what the account holds.
     */
    public function preResolveSeededLeavesForPurpose(
        AIConversation $conversation,
        AIConversationPurpose $aIConversationPurpose,
        TaskPlanItems $taskPlanItems,
        ?array $onlyDoneCriterionKeys = null
    ): int {
        return $aIConversationPurpose->preResolveSeedTaskPlanItems($taskPlanItems, $conversation->agentSpace ?? null, $conversation->messages, $conversation->getTimezone(), isset($conversation->id) ? (int)$conversation->id : null, $onlyDoneCriterionKeys, $conversation);
    }

    protected function turnEventsService(): AIConversationTurnEventsService
    {
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        return $aIConversationTurnEventsService;
    }

    /**
     * Apply an `update_task_plan` call onto the conversation's IN-MEMORY plan (the keyed contract): the
     * wire parsed once (the call's {@see AgentToolCallWorkRequest}), checked as a whole against the standing plan,
     * applied by the plan itself — created when no plan stands, changed otherwise ({@see TaskPlan::createFromUpdate()} / {@see TaskPlan::applyUpdate()}). The statuses the
     * call sends pass the tick application with the same evidence, criteria and inputs a `set_task_status` call gets;
     * a status never rejects the call, its gate lines join the result. There is no
     * earlier-run exception: a call over a plan of an earlier run follows the same checks. Sets the call's status
     * and records its outcome where it is decided. The plan COLUMN is persisted by the loop after the dispatch
     * returns — never here, where the dispatch's catch would demote a claim-lost throw to a swallowed FAILED tool result.
     *
     * @param AgentToolCallWorkRequest $taskPlanUpdateRequest the call's request ({@see UpdateTaskPlanTool::workRequest()})
     * @return string the call's result text
     */
    public function applyUpdateTaskPlanCall(AIConversation $conversation, ToolCall $toolCall, AgentToolCallWorkRequest $taskPlanUpdateRequest): string
    {
        $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        $priorTaskPlan = $conversation->getTaskPlan();
        // Carrier A rides the FIRST accepted plan write of the run (no plan yet, or the standing plan was not written in
        // this turn) — result text only, it changes no check
        $isFirstPlanWriteOfThisRun = $priorTaskPlan === null
            || !$priorTaskPlan->wasUpdatedInTurn($currentTurnInitiatorMessageIndex);
        // the statuses the call sends are judged exactly as a set_task_status tick of this conversation —
        // its evidence, the purpose's criteria, their inputs (over the plan the call rebuilds), read only when a status
        // transition reaches the tick: a rejected call or one without a status loads no record
        $taskPlanUpdateContext = TaskPlanUpdateContext::forStatusTickOfConversation($currentTurnInitiatorMessageIndex, $conversation, $this);
        $taskPlanUpdateContext->keysAddedEarlierInThisResponse = $conversation->messages->getTaskPlanKeysAddedByEarlierCallsOfTheCurrentModelStep($priorTaskPlan, $toolCall);
        $taskPlanUpdateOutcome = $taskPlanUpdateRequest->applyTaskPlanUpdateTo($priorTaskPlan, $taskPlanUpdateContext);
        if ($taskPlanUpdateOutcome->isRejected()) {
            $resultContent = $taskPlanUpdateOutcome->rejections->renderBlock($priorTaskPlan?->items);
            $toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
            $toolCall->markFailed();
            // The rejected call still counts as this turn's plan TOUCH (checked stop, adaptive step budget) — without
            // it a turn whose only plan interaction was a rejected call lets a lazy final answer pass unbounced.
            $priorTaskPlan?->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
            $this->traceKeyIntegrityViolationOfRejectedUpdate($conversation, $priorTaskPlan, $taskPlanUpdateOutcome);
            return $resultContent;
        }
        $feedbackSentence = $taskPlanUpdateOutcome->feedback !== [] ? ' ' . implode(' ', $taskPlanUpdateOutcome->feedback) : '';
        $maintenanceText = $isFirstPlanWriteOfThisRun ? $this->buildPlanMaintenanceText() : '';
        if ($taskPlanUpdateOutcome->isUnchanged) {
            // NO-OP RESEND (a stalled model resends the identical plan for many consecutive steps instead of doing the
            // work): refuse WITHOUT a brake (forcing a tool-less answer here would make the model rationalize a false "I
            // could not do it" to the owner) and name the load_tool route, which is the observed escape
            // ({@see TaskPlan::buildUnchangedResendResultText()}). The refusal
            // still counts as this turn's plan TOUCH and still delivers the feedback and the once-per-run maintenance rules.
            $priorTaskPlan?->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
            $toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_UNCHANGED;
            $toolCall->markCompleted();
            if ($taskPlanUpdateOutcome->hasGateRefusedStatus) {
                // a gate refused the status the call sent: the call was no resend — a gate refusal, the feedback names the gate
                $toolCall->wasRefusedByGate = true;
                return 'Task plan NOT updated: a status you sent was NOT applied (the reason follows) and nothing else'
                    . ' changed. Do what the reason names first, then send the status again.'
                    . $feedbackSentence . $maintenanceText;
            }
            $toolCall->wasRefusedAsDuplicate = true;
            return TaskPlan::buildUnchangedResendResultText() . $feedbackSentence . $maintenanceText;
        }
        $updatedTaskPlan = $taskPlanUpdateOutcome->taskPlan;
        // the creation stamp names the owner turn the plan was made for (its title at retirement); a change
        // keeps the original creation
        $updatedTaskPlan->createdAtMessageIndex = $taskPlanUpdateOutcome->isCreation || $priorTaskPlan === null
            ? $currentTurnInitiatorMessageIndex
            : ($priorTaskPlan->createdAtMessageIndex ?? $currentTurnInitiatorMessageIndex);
        $updatedTaskPlan->title = $priorTaskPlan?->title;
        $agentWorkingState = isset($conversation->agentWorkingState)
            ? $conversation->agentWorkingState
            : new AgentWorkingState();
        $agentWorkingState->taskPlan = $updatedTaskPlan;
        $conversation->agentWorkingState = $agentWorkingState;
        // the outcome is recorded where it is decided — the replay never re-judges this call
        $toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED;
        $toolCall->markCompleted();
        return $taskPlanUpdateOutcome->renderAppliedSummary() . $maintenanceText
            . ($taskPlanUpdateOutcome->isCreation ? ' ' . TaskPlanUpdateOutcome::CREATION_KEY_SENTENCE : '');
    }

    /**
     * The lazy-decomposition Carrier A: the maintenance rules ride the FIRST plan write of
     * a run — once per run, at the moment the model is most attentive; this result is exempt from the clearing
     * stub so it stays wired the whole run. The tick rules are the ONE text the tool description and the plan header
     * carry too ({@see TaskPlan::buildTickRules()}); the structure rules follow. Leading space included.
     */
    protected function buildPlanMaintenanceText(): string
    {
        return ' MAINTENANCE: ' . TaskPlan::buildTickRules()
            . ' Never resend the whole plan for a status change (update_task_plan is for structure only). If the user changes'
            . ' the task mid-run, revise the plan FIRST (add the new items, drop cancelled items with "remove": true), then continue.';
    }

    /**
     * The purpose of a purposed conversation — the one that judges its ticks against the record (its done criteria and,
     * for a conversation with a space, the channel connections of that space); null otherwise (no purpose name, or the
     * purpose cannot be read). A conversation without a space is judged at account level by the same purpose.
     */
    protected function findPurposeJudgingTicksOfConversation(AIConversation $conversation): ?AIConversationPurpose
    {
        $purposeName = $conversation->purposeName ?? null;
        if ($purposeName === null || $purposeName === '') {
            return null;
        }
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        return $aIConversationPurpose instanceof AIConversationPurpose ? $aIConversationPurpose : null;
    }

    /**
     * An `update_task_plan` call rejected because the stored plan's keys are inconsistent (R25, a
     * hand-edited or partially written row) found a key-integrity defect the platform must fix — traced with the plan's
     * violation lines, here where the conversation is known (the read that found them has no conversation id).
     */
    protected function traceKeyIntegrityViolationOfRejectedUpdate(AIConversation $conversation, ?TaskPlan $taskPlan, TaskPlanUpdateOutcome $taskPlanUpdateOutcome): void
    {
        if ($taskPlan === null || !($taskPlanUpdateOutcome->rejections?->hasCode(TaskPlanUpdateRejection::CODE_STORED_KEYS_INCONSISTENT) ?? false)) {
            return;
        }
        $this->aiTrace('plan.keyIntegrityViolation', [
            'conversationId' => $conversation->id ?? null,
            'planCreatedAtMessageIndex' => $taskPlan->createdAtMessageIndex,
            'tool' => UpdateTaskPlanTool::NAME,
            'violations' => $taskPlan->keyIntegrityViolations->lines ?? [],
        ]);
    }

    /**
     * Apply a `set_task_status` call onto the LOADED plan: the arguments parsed once
     * ({@see SetTaskStatusArguments}, carried by the call's {@see AgentToolCallWorkRequest}), the addresses checked
     * first (strict key addressing — one address that is not a key rejects the whole call), then the
     * plan's own application ({@see TaskPlan::applyStatusUpdates()}) judged against the record by the purpose's criteria, evaluated lazily over inputs built once for the call
     * ({@see findDoneCriteriaForStatusTick()}, {@see buildDoneCriterionInputsForStatusTick()}). Records the call's outcome, marks the call and answers its result
     * text; the answer forces the tool-less step on an all-no-op tick that leaves nothing workable or repeats a failed tick
     * ({@see AgentTaskPlanTickOutcome::requiresToolLessAnswerStep()}). The plan COLUMN is persisted by the loop after the
     * dispatch returns (never here — the dispatch's catch would swallow a claim-lost throw).
     *
     * @param AgentToolCallWorkRequest $taskStatusUpdateRequest the call's request ({@see SetTaskStatusTool::workRequest()})
     * @param TaskPlanEvidence $taskPlanEvidence the tick's evidence ({@see AIConversation::buildTaskPlanEvidenceForStatusTick()})
     */
    public function applySetTaskStatusCall(
        AIConversation $conversation,
        ToolCall $toolCall,
        AgentToolCallWorkRequest $taskStatusUpdateRequest,
        TaskPlanEvidence $taskPlanEvidence
    ): AgentMetaToolCallAnswer {
        $currentTaskPlanForTick = $conversation->getTaskPlan();
        $setTaskStatusArguments = $taskStatusUpdateRequest->getSetTaskStatusArguments();
        // the call's addresses, checked before anything applies
        $addressCheck = $currentTaskPlanForTick !== null && $setTaskStatusArguments->hasUpdatesList
            ? SetTaskStatusAddressCheck::ofArguments($setTaskStatusArguments, $currentTaskPlanForTick->items)
            : null;
        if ($currentTaskPlanForTick === null) {
            $toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
            $toolCall->markFailed();
            return AgentMetaToolCallAnswer::failed('No task plan exists yet — create it first with update_task_plan (the complete plan as items: [{text, status}, …]).');
        }
        if ($addressCheck !== null && $addressCheck->rejectsTheCall()) {
            // an address that is not a key of the plan rejects the whole call — nothing applies; the result
            // is the rejection block alone (the offending values, the key to send, the open keys)
            $toolCall->taskPlanCallOutcome = ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
            $toolCall->markFailed();
            $this->traceKeyIntegrityViolationOfAmbiguousTicks($conversation, $currentTaskPlanForTick, $addressCheck);
            return AgentMetaToolCallAnswer::failed($addressCheck->renderRejection($currentTaskPlanForTick->items));
        }
        // recorded where it is decided — before the application, so a throw inside it keeps the outcome
        // (the plan ticked in memory is persisted after the dispatch all the same); a call without an `updates` list
        // changed nothing and is `refused`
        $toolCall->taskPlanCallOutcome = $addressCheck !== null
            ? ToolCall::TASK_PLAN_CALL_OUTCOME_APPLIED_KEYED
            : ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
        // The purpose's criteria and their inputs once for the call; each gate asks its key when reached
        $agentTaskPlanTickOutcome = $taskStatusUpdateRequest->applyTaskStatusUpdatesTo(
            $currentTaskPlanForTick,
            $taskPlanEvidence,
            $this->findDoneCriteriaForStatusTick($conversation),
            $this->buildDoneCriterionInputsForStatusTick($conversation, $currentTaskPlanForTick->items)
        );
        // No forced tool-less step after a NOTED widget or blocked tick — the call
        // completes, the item stays open, the record closes it. Only the all-no-op tick may force it, read here before the
        // call is marked (an earlier FAILED tick of this turn is the loop signal, this call is not one of them yet).
        $forcesToolLessAnswerStep = $agentTaskPlanTickOutcome->isAllAlreadyInStatus()
            && $agentTaskPlanTickOutcome->requiresToolLessAnswerStep(
                $currentTaskPlanForTick->items?->countItemsByStatus()->open ?? 0,
                $conversation->messages->buildCurrentTurn()->countFailedTaskPlanTicks(),
                $currentTaskPlanForTick->items?->getNextMarkedItem() !== null
            );
        $resultContent = $agentTaskPlanTickOutcome->buildResultText($currentTaskPlanForTick->items);
        if ($agentTaskPlanTickOutcome->failsTheCall()) {
            $toolCall->markFailed();
            $setTaskStatusCallAnswer = AgentMetaToolCallAnswer::failed($resultContent);
        } else {
            $toolCall->markCompleted();
            $setTaskStatusCallAnswer = AgentMetaToolCallAnswer::completed($resultContent);
        }
        return $forcesToolLessAnswerStep ? $setTaskStatusCallAnswer->withToolLessAnswerStep() : $setTaskStatusCallAnswer;
    }

    /**
     * A tick that named a key the stored plan holds more than once (a hand-edited or partially written
     * row) found a key-integrity defect the platform must fix — traced once per such call with the plan's violation
     * lines, here where the conversation is known (the read that found them has no conversation id).
     */
    protected function traceKeyIntegrityViolationOfAmbiguousTicks(AIConversation $conversation, TaskPlan $taskPlan, SetTaskStatusAddressCheck $addressCheck): void
    {
        if ($addressCheck->getAmbiguousKeys() === []) {
            return;
        }
        $this->aiTrace('plan.keyIntegrityViolation', [
            'conversationId' => $conversation->id ?? null,
            'planCreatedAtMessageIndex' => $taskPlan->createdAtMessageIndex,
            'tool' => SetTaskStatusTool::NAME,
            'ambiguousKeys' => $addressCheck->getAmbiguousKeys(),
            'violations' => $taskPlan->keyIntegrityViolations->lines ?? [],
        ]);
    }

    /**
     * The turn-end half of the record closers: once the turn's final text is persisted, close the open leaves whose
     * widget / delivery evidence is in the transcript ({@see TaskPlanItems::closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd()}),
     * persist the slice once and remember the closures for the next turn's PLAN_BOOKKEEPING signal. The one write of
     * these closings: the checked stop only reads a candidate text's evidence, it never closes on it. Best-effort: a
     * failure here never wedges the turn (the final text is already delivered).
     */
    public function closePlanLeavesAtTurnEnd(
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        bool $stampsTurnProgress = true // false at the turn START: observing the owner's answer grants no progress exemption
    ): void {
        try {
            $currentTaskPlan = $conversation->getTaskPlan();
            if ($currentTaskPlan?->items === null || $conversation->isDelegation() || !$currentTaskPlan->hasOpenItems()) {
                return;
            }
            // the evidence every closing below reads, gathered once
            $turnEndTaskPlanEvidence = $conversation->buildTaskPlanEvidence();
            $currentTurnInitiatorMessageIndex = $turnEndTaskPlanEvidence->currentTurnInitiatorMessageIndex;
            $closedAtTurnEnd = $currentTaskPlan->items->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd($turnEndTaskPlanEvidence);
            if (!$closedAtTurnEnd->hasClosings()) {
                return;
            }
            if ($stampsTurnProgress) {
                $currentTaskPlan->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
                $currentTaskPlan->markResolvedInTurn($currentTurnInitiatorMessageIndex);
            }
            // MERGED with a note the previous turn end left (it is announced once, at the next model step) — an
            // overwrite dropped the previous turn's closures and their "do NOT tick them again".
            $closedNote = TaskPlan::buildNoteForLeavesClosedAtTurnEnd($closedAtTurnEnd, $currentTaskPlan->items, atTurnStart: !$stampsTurnProgress);
            $pendingNote = trim((string)($currentTaskPlan->platformClosedAtTurnEndNote ?? ''));
            $currentTaskPlan->platformClosedAtTurnEndNote = $pendingNote !== '' && $closedNote !== null ? $pendingNote . "\n" . $closedNote : ($closedNote ?? ($pendingNote !== '' ? $pendingNote : null));
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistTaskPlanSlice($conversation, $currentTaskPlan, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
            $this->aiTrace('plan.closedAtTurnEnd', ['conversationId' => $conversation->id ?? null, 'byEvidence' => $closedAtTurnEnd->getStatusByNumberPath()]);
            // No purpose consequence here: a notice appended AFTER the final text would read as an unanswered inbound
            // row and start an unrequested turn — the settled-turn reconcile ({@see AIConversationPurpose::reconcileTaskPlanAtSettledTurn()})
            // and the next plan persist run the consequences.
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $turnEndCloseError) {
            $this->aiTrace('plan.closedAtTurnEnd.failed', ['conversationId' => $conversation->id ?? null, 'message' => $turnEndCloseError->getMessage()]);
        }
    }

    /**
     * After a completed work tool the PLATFORM closes the leaves whose declared evidence
     * this call delivered ({@see TaskPlanItems::closeLeavesProvenByCompletedCall()}) and persists the slice, then the
     * tool result's plan note names what was closed (and what, rarely, is still the model's tick). The model no
     * longer spends a `set_task_status` call on evidence the record already proves.
     *
     * The plan note on the result of a completed work tool, written after the post-write re-sweep: the items the
     * platform closed from the record are named with their verdict, the items whose tick the model owes are named
     * for the tick. An appendix, not part of the result: the result stays decodable for the tool→loop conventions.
     * A successful gate call that nobody ticked left the model believing the gate was open; an instruction to tick
     * an item the platform closes a moment later made it tick a closed item — so the note is built from the plan as
     * it is AFTER the platform's look. A call the loop answered without running it (a refused repeat, a read
     * answered from an earlier result) carries no note: nothing was persisted by it.
     *
     * @param string[] $openItemKeysBeforeReSweep the open leaves' keys before the post-write re-sweep
     */
    public function closeLeavesProvenByCompletedToolCallAndAppendPlanNote(
        AgentRun $agentRun,
        AIConversation $conversation,
        ToolCall $toolCall,
        AIConversationMessage $callMessage,
        array $openItemKeysBeforeReSweep,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream = null,
        bool $emitRealtimeSignal = false
    ): void {
        $currentTaskPlan = $conversation->getTaskPlan();
        $taskPlanItems = $currentTaskPlan?->items;
        $toolName = $toolCall->name ?? '';
        if ($currentTaskPlan === null || $taskPlanItems === null || $toolName === '' || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate) {
            return;
        }
        // the completed-call closings never read the locked gate tools, so this evidence never sweeps the skill catalog
        $completedCallTaskPlanEvidence = $conversation->buildTaskPlanEvidence();
        $closedByEvidence = $taskPlanItems->closeLeavesProvenByCompletedCall(
            $toolName,
            (int)($callMessage->indexInConversation ?? ($conversation->messages->getNewestMessageIndex() ?? 0)),
            $completedCallTaskPlanEvidence
        );
        if ($closedByEvidence->hasClosings()) {
            $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
            $currentTaskPlan->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
            $currentTaskPlan->markResolvedInTurn($currentTurnInitiatorMessageIndex);
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistTaskPlanSlice($conversation, $currentTaskPlan, $agentRun->findRunTokenClaimedInThisProcess($conversation));
            $this->aiTrace('plan.closedByEvidence', ['conversationId' => $conversation->id ?? null, 'tool' => $toolName, 'closed' => $closedByEvidence->getStatusByNumberPath()]);
            // the purpose's consequence runs on this persist too (the completion bridge, mid-turn — the same as the sweep)
            $this->purposeRunsService()->applyPurposeConsequencesAfterPlanPersist($agentRun, $conversation, $currentTaskPlan, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        }
        $keysResolvedByPlatform = array_values(array_diff($openItemKeysBeforeReSweep, $taskPlanItems->getOpenItemNumberPaths()));
        $keysTickableByModel = $taskPlanItems->getItemNumberPathsTickableAfterToolCall(
            $toolName,
            $completedCallTaskPlanEvidence,
            array_values(array_diff($keysResolvedByPlatform, $closedByEvidence->getClosedNumberPaths()))
        );
        $closedByPlatform = new TaskPlanLeafClosings();
        // every item by its KEY — the note names what the plan render shows
        foreach ($keysResolvedByPlatform as $itemKey) {
            $closedTaskPlanItem = $taskPlanItems->getItemByKey((string)$itemKey);
            if ($closedTaskPlanItem !== null && !in_array($itemKey, $keysTickableByModel, true)) {
                $closedByPlatform->addClosing((string)$itemKey, (string)$closedTaskPlanItem->status);
            }
        }
        $toolCall->resultAppendix = TaskPlan::buildNoteAfterCompletedToolCall($closedByPlatform, $keysTickableByModel);
    }

    /**
     * Re-run the purpose's seed pre-resolution over the plan's REMAINING open leaves after a completed work tool
     * ({@see AIConversationPurpose::preResolveSeedTaskPlanItems()} — open+criterion leaves only by construction).
     * When anything resolved, stamp the plan as touched AND resolved this turn and persist the slice (token-fenced,
     * same chokepoint semantics as the plan-tool persist above). No-op on unpurposed conversations or without a plan.
     *
     * @param string[]|null $onlyDoneCriterionKeysToReRead the keys to re-read regardless of a tool (the turn start of an
     *        owner's typed message re-reads the criteria that rule on an ended turn); null = derived from the tool
     */
    public function reResolveOpenSeededLeavesAfterWork(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal,
        ?string $completedToolName = null,
        ?array $onlyDoneCriterionKeysToReRead = null
    ): void {
        $purposeName = $conversation->purposeName ?? null;
        $currentTaskPlan = $conversation->getTaskPlan();
        if ($purposeName === null || $currentTaskPlan?->items === null || !$currentTaskPlan->hasOpenItems()) {
            return;
        }
        $aIConversationPurpose = $conversation->findPurpose();
        if (!$aIConversationPurpose instanceof AIConversationPurpose) {
            return;
        }
        // Only the criteria this tool can have changed are re-read (null = every open criterion leaf for a tool the
        // purpose does not know; [] = a write that touches no criterion — no facet read at all)
        // `$onlyDoneCriterionKeysToReRead` names the keys directly (the turn start of an owner's typed message)
        $onlyDoneCriterionKeys = $onlyDoneCriterionKeysToReRead
            ?? ($completedToolName !== null ? $aIConversationPurpose->criterionKeysChangedByTool($completedToolName) : null);
        if ($onlyDoneCriterionKeys === []) {
            return;
        }
        $resolvedLeafCount = $this->preResolveSeededLeavesForPurpose($conversation, $aIConversationPurpose, $currentTaskPlan->items, $onlyDoneCriterionKeys);
        // A rendered non-blocking offer widget moves its item to AWAITING the owner — a platform stamp
        // persisted with the slice, but NOT a resolution (observing a widget grants no progress exemption).
        $stampedOfferCount = $currentTaskPlan->items->stampRenderedNonBlockingOffers($conversation->messages);
        if ($resolvedLeafCount === 0) {
            if ($stampedOfferCount > 0) {
                /** @var AIConversationsService $aIConversationsService */
                $aIConversationsService = AIConversation::getService();
                $aIConversationsService->persistTaskPlanSlice($conversation, $currentTaskPlan, $agentRun->findRunTokenClaimedInThisProcess($conversation));
            }
            return;
        }
        $currentTurnInitiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        $currentTaskPlan->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
        $currentTaskPlan->markResolvedInTurn($currentTurnInitiatorMessageIndex);
        // The purpose's plan consequence runs on THIS persist too (a sweep resolution can be exactly the write
        // that completes a declared item — without this the completion bridge waited for the NEXT plan write).
        $this->persistTaskPlanSliceAndApplyPurposeConsequences($agentRun, $conversation, $currentTaskPlan, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
    }

    /**
     * Persist the plan slice under the claim this run holds and run the purpose's consequence of the persist — the
     * tail of every platform resolution of a leaf during a turn (the post-write re-sweep, a pending tick the candidate
     * text delivered).
     *
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function persistTaskPlanSliceAndApplyPurposeConsequences(
        AgentRun $agentRun,
        AIConversation $conversation,
        TaskPlan $currentTaskPlan,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistTaskPlanSlice(
            $conversation,
            $currentTaskPlan,
            $agentRun->findRunTokenClaimedInThisProcess($conversation)
        );
        $this->purposeRunsService()->applyPurposeConsequencesAfterPlanPersist($agentRun, $conversation, $currentTaskPlan, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
    }

    /**
     * RETIRE a finished plan at the ACCEPTED final answer of an owner turn: every item terminal, the
     * answer delivered, so the plan leaves the wire tail and joins the finished set (`get_task_plans`). Only for a
     * model-created plan of a plan-less purpose (a seeded plan belongs to its purpose's settled-turn logic) and only
     * while the site GUIDES (switch 2); under `shadow` the retirement is recorded as `wouldRetireFinishedPlan`. Token-
     * fenced persist; a claim loss propagates like every plan write. Never throws into the loop for its own reasons.
     * `$purposeWorksAutonomously` is the loop's answer for this step ({@see AIConversationPurposeRunsService::conversationPurposeWorksAutonomously()}).
     */
    public function retireFinishedTaskPlanIfDue(AIConversation $conversation, ?string $runToken, bool $purposeWorksAutonomously): void
    {
        $agentWorkingState = $conversation->getAgentWorkingState();
        $activePlan = $agentWorkingState?->taskPlan;
        if ($agentWorkingState === null || $activePlan === null || !$activePlan->isFinished()) {
            return;
        }
        $siteMode = AgentDecisionModelSettings::current()->siteModeForConversation(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $conversation);
        if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation()
            || $purposeWorksAutonomously || $this->purposeRunsService()->conversationPurposeSeedsItsTaskPlan($conversation)
        ) {
            return;
        }
        $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        $decision = $agentWorkingState->planDynamicsDecision;
        if ($decision === null || $decision->ownerMessageIndex !== $initiatorMessageIndex) {
            $decision = new AgentPlanDynamicsDecision();
            $decision->ownerMessageIndex = $initiatorMessageIndex;
            $decision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
            $decision->decidedBy = $siteMode === AgentDecisionModelSettings::MODE_ON ? AgentPlanDynamicsDecision::DECIDED_BY_DECISION_MODEL : AgentPlanDynamicsDecision::DECIDED_BY_SHADOW;
            $agentWorkingState->planDynamicsDecision = $decision;
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        if ($siteMode !== AgentDecisionModelSettings::MODE_ON) {
            $decision->wouldRetireFinishedPlan = true;
            $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'planDynamicsDecision');
            $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $initiatorMessageIndex, 'retirement', null, ['applied' => false, 'mode' => $siteMode, 'wouldRetireFinishedPlan' => true, 'itemCount' => $activePlan->items?->countAllItems() ?? 0], $runToken, null);
            return;
        }
        $ownerMessage = '';
        foreach ($conversation->messages->getElements() as $message) {
            if (($message->indexInConversation ?? null) === $activePlan->createdAtMessageIndex && ($message->messageType ?? null) === AIConversationMessage::MESSAGE_TEXT) {
                $ownerMessage = (string)($message->message ?? '');
            }
        }
        $retiredPlan = $agentWorkingState->retireFinishedTaskPlan($conversation->messages->getNewestMessageIndex() ?? $initiatorMessageIndex, (new DateTime())->format('Y-m-d H:i:s'), $ownerMessage !== '' ? $ownerMessage : null);
        if ($retiredPlan === null) {
            return;
        }
        $decision->retiredFinishedPlan = true;
        $aIConversationsService->persistTaskPlanRetirementSlice($conversation, $runToken);
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'planDynamicsDecision');
        $this->turnEventsService()->recordEventOfConversation($conversation, AIConversationTurnEvent::KIND_DECISION, AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, $initiatorMessageIndex, 'retirement', null, ['applied' => true, 'mode' => $siteMode, 'retiredFinishedPlan' => true, 'itemCount' => $retiredPlan->items?->countAllItems() ?? 0, 'finishedPlanCount' => $agentWorkingState->countFinishedTaskPlans()], $runToken, null);
        $this->aiTrace('turn.planDynamics.retired', ['conversationId' => $conversation->id ?? null, 'title' => $retiredPlan->title, 'finishedPlanCount' => $agentWorkingState->countFinishedTaskPlans()]);
    }

    /** The run claims (the run tokens this process holds) — resolved inline (DI rule). */
    /**
     * Moved from the agent loop: a widget the owner answers
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem::$doneRequiresWidgetAnswered})
     * rendered in this turn's FINAL text moves its plan item to AWAITING the owner before the next turn's tail renders
     * `← next` on it. Stamps and persists the slice (no progress exemption — observing a widget is not
     * work). Fail-soft, but a lost claim is the loop's one claim-lost exit — never a swallowed failure.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the slice was written
     */
    public function stampRenderedNonBlockingOffersAndPersist(AIConversation $conversation): void
    {
        $currentTaskPlan = $conversation->getTaskPlan();
        if ($currentTaskPlan?->items === null) {
            return;
        }
        try {
            if ($currentTaskPlan->items->stampRenderedNonBlockingOffers($conversation->messages) === 0) {
                return;
            }
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $aIConversationsService->persistTaskPlanSlice($conversation, $currentTaskPlan, $this->runClaimsService()->getRunTokenClaimedInThisProcess($conversation));
            $this->aiTrace('plan.nonBlockingOffer.stamped', ['conversationId' => $conversation->id ?? null]);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost;
        } catch (Throwable $stampError) {
            $this->aiTrace('plan.nonBlockingOffer.stampFailed', ['conversationId' => $conversation->id ?? null, 'error' => $stampError->getMessage()]);
        }
    }

    protected function runClaimsService(): AIConversationRunClaimsService
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        return $aIConversationRunClaimsService;
    }

    /** The purposed conversation's runs (the purpose's consequence of a plan persist) — resolved inline (DI rule). */
    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return $aIConversationPurposeRunsService;
    }
}
