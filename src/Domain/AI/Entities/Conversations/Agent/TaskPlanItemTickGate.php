<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArgumentsUpdate;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\Base\Entities\ValueObject;
use Throwable;

/**
 * The gates of ONE `set_task_status` update on ONE task-plan item (the twin of
 * {@see TaskPlanItemPlatformCloser} for the MODEL's tick): each method is one gate group of the former 762-line tool
 * method, reads the item's declared gates against what the tick's evidence records ({@see TaskPlanEvidence}) and answers
 * a {@see TaskPlanItemTickVerdict} when its group decides the update, null when the update passes on. The plan runs them
 * in the fixed order ({@see TaskPlan::applyStatusUpdates()}): the echo of a platform closing (G4), the record criterion
 * (G7–G11), the done evidence (G12–G17), the not_applicable evidence (G18–G19; G20 was removed), the blocked evidence
 * (G21–G24), then the transition (G25, G26, G28, {@see applyTick()}). A tick NEVER fails the call — a tick
 * the record cannot confirm is not applied, the item stays open and closes itself from the record (nothing
 * of the tick is kept); the record's reason stands in the feedback. On an item without a record gate the tick applies.
 *
 * The item keeps its state and its transitions ({@see TaskPlanItem::applyStatusChosenByTheModel()});
 * this object holds the questions that combine the item with the update and the record, so the item does not grow with
 * them. A working value of one update over a reference into the live tree: what the done gates found (the
 * previous turn's accepted delivery) reaches the transition here. Never serialized.
 *
 * The purpose's criteria are asked LAZILY: each gate that reads the item's criterion asks the registry
 * for its key at the moment the gate is reached ({@see resolveTheItemsCriterion()}), over the call's one set of inputs
 * whose plan is the live tree as mutated so far — no verdict is computed ahead. Whether the criteria decide every tick is
 * the evidence's {@see TaskPlanEvidence::$criteriaDecideTicks}; the channel connection is read through the inputs
 * ({@see TaskPlanDoneCriterionInputs::findChannelConnectionState()}).
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanItemTickGate extends ValueObject
{
    /** What a widget gate says while the owner is on the phone line — English, no fence. */
    public const string PHONE_LINE_WIDGET_STEP_NOTE = 'this step needs a widget in the app and the owner is on the phone'
        . ' line, where no widget can be shown. The item stays open: tell the owner where in the app they finish it'
        . ' after the call, and go on with what can be done now.';

    protected TaskPlanItem $taskPlanItem;

    protected SetTaskStatusArgumentsUpdate $setTaskStatusArgumentsUpdate;

    protected TaskPlanEvidence $taskPlanEvidence;

    /** @var string[] The plan's done-gate tools the account cannot run, read once for the call. */
    protected array $entitlementLockedToolNames = [];

    /** The purpose's done criteria the call is judged by (empty = no criterion answers, every key is no gate). */
    protected TaskPlanDoneCriteria $taskPlanDoneCriteria;

    /** What the criteria read — built once for the whole call; its plan is the live tree. */
    protected TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs;

    /** The normalized status the update asks for. */
    protected string $status = '';

    /** The update's address as the feedback names the item (its key). */
    protected string $taskAddress = '';

    /**
     * The previous turn's delivery answer the done gates accepted for a next-turn tick ({@see checkDoneEvidence()});
     * stamped on the item only once the tick is applied ({@see applyTick()}).
     */
    protected ?int $previousTurnDeliveryEvidenceIndex = null;

    /**
     * The gate of `$setTaskStatusArgumentsUpdate` (its status recognized) on `$taskPlanItem`, a node of the live plan tree,
     * judged by the call's criteria and their inputs.
     *
     * @param string[] $entitlementLockedToolNames
     */
    public static function forUpdate(
        TaskPlanItem $taskPlanItem,
        SetTaskStatusArgumentsUpdate $setTaskStatusArgumentsUpdate,
        TaskPlanEvidence $taskPlanEvidence,
        array $entitlementLockedToolNames,
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs
    ): static {
        $taskPlanItemTickGate = new static();
        $taskPlanItemTickGate->taskPlanItem = $taskPlanItem;
        $taskPlanItemTickGate->setTaskStatusArgumentsUpdate = $setTaskStatusArgumentsUpdate;
        $taskPlanItemTickGate->taskPlanEvidence = $taskPlanEvidence;
        $taskPlanItemTickGate->entitlementLockedToolNames = $entitlementLockedToolNames;
        $taskPlanItemTickGate->taskPlanDoneCriteria = $taskPlanDoneCriteria;
        $taskPlanItemTickGate->taskPlanDoneCriterionInputs = $taskPlanDoneCriterionInputs;
        $taskPlanItemTickGate->status = (string)$setTaskStatusArgumentsUpdate->status;
        $taskPlanItemTickGate->taskAddress = $setTaskStatusArgumentsUpdate->getTaskAddressAsSent();
        return $taskPlanItemTickGate;
    }

    /**
     * G4 — the platform closed the item in THIS turn (from the record while the response ran, or from a widget its
     * own tool wrote one step earlier) and no earlier tick of the turn named it: the FIRST tick of the item in the turn
     * is an echo of what the model saw, not a loop — nothing to correct, before the note warning and the evidence
     * gates. A SECOND tick of the same item in the turn takes the ALREADY path.
     */
    public function checkEchoOfThisTurnsPlatformClosing(): ?TaskPlanItemTickVerdict
    {
        $currentTurnInitiatorMessageIndex = $this->taskPlanEvidence->currentTurnInitiatorMessageIndex;
        if ($this->status === $this->taskPlanItem->status
            && $this->status !== TaskPlanItem::STATUS_OPEN
            && $currentTurnInitiatorMessageIndex >= 0
            && ($this->taskPlanItem->platformResolvedAtMessageIndex ?? -1) >= $currentTurnInitiatorMessageIndex
            && (!$this->taskPlanEvidence->hasTranscript
                || !$this->taskPlanEvidence->conversationMessages->buildCurrentTurn()->hasTaskPlanTickNamingTask($this->taskAddress))
        ) {
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_ECHO_OF_PLATFORM_CLOSING, [
                "Task '{$this->taskAddress}' was closed by the platform in this turn — it is {$this->status}, nothing to do.",
            ]);
        }
        return null;
    }

    /**
     * The information lines that never stop an update: a status normalized from another spelling, and a terminal status
     * without its statusNote — the status is applied with the omission named (for done, and for blocked /
     * not_applicable, whose refusal looped weak models on the resend).
     *
     * @return string[]
     */
    public function buildStatusInformationLines(): array
    {
        $informationLines = [];
        if ($this->status !== $this->setTaskStatusArgumentsUpdate->rawStatus) {
            $informationLines[] = "Task '{$this->taskAddress}': status '{$this->setTaskStatusArgumentsUpdate->rawStatus}' normalized to '{$this->status}'.";
        }
        if ($this->status !== TaskPlanItem::STATUS_OPEN && $this->setTaskStatusArgumentsUpdate->statusNote === '') {
            $informationLines[] = "Task '{$this->taskAddress}': {$this->status} without a statusNote — state in ONE line "
                . ($this->status === TaskPlanItem::STATUS_DONE ? 'what you actually produced.' : 'why.');
        }
        return $informationLines;
    }

    /**
     * G7–G11 — the RECORD's criterion on a criterion leaf: the record's verdict ({@see checkTheRecordsVerdict()}), then
     * the criterion the purpose enforces on every tick ({@see checkTheEnforcedDoneCriterion()}).
     */
    public function checkAgainstTheRecordCriterion(): ?TaskPlanItemTickVerdict
    {
        return $this->checkTheRecordsVerdict() ?? $this->checkTheEnforcedDoneCriterion();
    }

    /**
     * G7 / G8 — a done / not_applicable on a criterion leaf. G7: the record's verdict is STILL OPEN — evidence the platform
     * owns is not on the record yet: not applied. G8: the record already RESOLVES the leaf (done /
     * not applicable with its note) — the record's verdict lands now, before the tool-call gate (a platform write outside
     * a model call leaves no call to find). The record PARKS the leaf (blocked on the owner's recorded words): the tick
     * is not applied — a leaf the platform already parked keeps its park untouched, any other leaf takes the record's
     * park now. A key without a criterion answers nothing — no gate.
     */
    protected function checkTheRecordsVerdict(): ?TaskPlanItemTickVerdict
    {
        $taskPlanItem = $this->taskPlanItem;
        if (($this->status !== TaskPlanItem::STATUS_DONE && $this->status !== TaskPlanItem::STATUS_NOT_APPLICABLE)
            || ($taskPlanItem->doneCriterion ?? null) === null
        ) {
            return null;
        }
        $recordVerdict = $this->resolveTheItemsCriterion();
        if ($recordVerdict instanceof TaskPlanDoneCriterionVerdict && $recordVerdict->isStillOpen()) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': {$this->status} not applied — the record decides this item (criterion '{$taskPlanItem->doneCriterion}'): "
                . $recordVerdict->statusNote . '. The item stays open; the platform closes it from the record.');
        }
        if ($recordVerdict instanceof TaskPlanDoneCriterionVerdict
            && in_array($recordVerdict->status, [TaskPlanItem::STATUS_DONE, TaskPlanItem::STATUS_NOT_APPLICABLE], true)
            && trim((string)$recordVerdict->statusNote) !== ''
        ) {
            $taskPlanItem->adoptRecordVerdictInsteadOfTick($recordVerdict, $this->taskPlanEvidence->currentTurnInitiatorMessageIndex);
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_RECORD_VERDICT_APPLIED, [
                $recordVerdict->status === $this->status
                    ? "Task '{$this->taskAddress}': {$this->status} — confirmed by the record: " . $recordVerdict->statusNote . '.'
                    : "Task '{$this->taskAddress}': the record decides this item — {$recordVerdict->status} (your {$this->status} was not applied): " . $recordVerdict->statusNote . '.',
            ]);
        }
        if ($recordVerdict instanceof TaskPlanDoneCriterionVerdict
            && $recordVerdict->status === TaskPlanItem::STATUS_BLOCKED
            && trim((string)$recordVerdict->statusNote) !== ''
        ) {
            $recordParkFeedbackLine = "Task '{$this->taskAddress}': the record decides this item — blocked (your {$this->status} was not applied): "
                . $recordVerdict->statusNote . '. The item stays parked until the record changes.';
            if ($taskPlanItem->isParkedByThePlatform()) {
                return TaskPlanItemTickVerdict::noted($recordParkFeedbackLine);
            }
            $taskPlanItem->adoptRecordParkInsteadOfTick($recordVerdict, $this->taskPlanEvidence->currentTurnInitiatorMessageIndex);
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_RECORD_VERDICT_APPLIED, [$recordParkFeedbackLine]);
        }
        return null;
    }

    /**
     * G9–G11 — only where the purpose's criteria decide every tick ({@see TaskPlanEvidence::$criteriaDecideTicks}), on a
     * criterion leaf. G9: a done while the criterion is unsatisfied on the current run (no verdict counts as
     * unsatisfied): not applied. G10: blocked while the record shows the stage satisfied. G11: blocked with
     * requiresUserAction on a machine-decided stage.
     */
    protected function checkTheEnforcedDoneCriterion(): ?TaskPlanItemTickVerdict
    {
        $taskPlanItem = $this->taskPlanItem;
        if (!$this->taskPlanEvidence->criteriaDecideTicks
            || ($taskPlanItem->doneCriterion ?? null) === null
        ) {
            return null;
        }
        $agentApplicationWording = AgentApplicationWording::current();
        if ($this->status === TaskPlanItem::STATUS_DONE) {
            $criterionResolution = $this->resolveTheItemsCriterion();
            if (!$criterionResolution instanceof TaskPlanDoneCriterionVerdict || $criterionResolution->isStillOpen()) {
                $stageToolExamples = $agentApplicationWording->criterionStageToolExamples !== ''
                    ? ' (' . $agentApplicationWording->criterionStageToolExamples . ')'
                    : '';
                return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — this item is decided by {$agentApplicationWording->criterionRecordName}: "
                    . "criterion '{$taskPlanItem->doneCriterion}' is NOT satisfied on the current run. The stage tool's record"
                    . $stageToolExamples . ' ticks the item when the work exists.');
            }
        }
        if ($this->status === TaskPlanItem::STATUS_BLOCKED) {
            $criterionResolution = $this->resolveTheItemsCriterion();
            if ($criterionResolution instanceof TaskPlanDoneCriterionVerdict && !$criterionResolution->isStillOpen()) {
                return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': blocked not applied — {$agentApplicationWording->criterionRecordName} shows this stage SATISFIED "
                    . "(criterion '{$taskPlanItem->doneCriterion}'); it is not blocked.");
            }
            if ($this->setTaskStatusArgumentsUpdate->requiresUserAction) {
                return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': blocked not applied — this stage is decided by {$agentApplicationWording->criterionRecordName} and never by the "
                    . 'owner; the stage tool\'s record ticks it. A technical obstacle is blocked with requiresUserAction=false '
                    . 'and the concrete tool error in statusNote.');
            }
        }
        return null;
    }

    /**
     * The verdict of the item's criterion over the call's inputs, asked NOW (the lazy evaluation): null
     * for a key without a criterion, and null when the criterion — or what it reads — throws (the tick never fails on a
     * record read; the gate then sees no verdict).
     */
    protected function resolveTheItemsCriterion(): ?TaskPlanDoneCriterionVerdict
    {
        try {
            return $this->taskPlanDoneCriteria->resolveKey((string)$this->taskPlanItem->doneCriterion, $this->taskPlanDoneCriterionInputs);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * G12–G17 — the done evidence a seeded item declares, read only with the transcript. G12 delivery: "done" claims the
     * OWNER READ IT — without an owner-visible answer after the item's own evidence call in this turn
     * the tick is not applied, unless the PREVIOUS turn delivered (next-turn acceptance, kept for the
     * transition); a leaf that waits for its question ({@see TaskPlanItem::$doneRequiresOwnerQuestionKey}) needs that question
     * asked ({@see TaskPlanItemPlatformCloser::describeOwnerQuestionAskedOrWidgetAnsweredAfter()}, the turn-end closing's check). The entitlement waiver: a gate tool the account cannot run is waived. G13
     * tool call; G14 the widget on the phone line; G15 the widget in the answer text (fence dictated); G16 the widget
     * the OWNER answers; G17 the connected channel.
     */
    public function checkDoneEvidence(): ?TaskPlanItemTickVerdict
    {
        if ($this->status !== TaskPlanItem::STATUS_DONE || !$this->taskPlanEvidence->hasTranscript) {
            return null;
        }
        $taskPlanItem = $this->taskPlanItem;
        $conversationMessages = $this->taskPlanEvidence->conversationMessages;
        $currentTurnInitiatorMessageIndex = $this->taskPlanEvidence->currentTurnInitiatorMessageIndex;
        $sameTurnAnswerFloorIndex = $currentTurnInitiatorMessageIndex;
        $evidenceCallIndex = null;
        if ($taskPlanItem->doneRequiresToolCall !== null) {
            $evidenceCall = $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                $taskPlanItem->getDoneRequiresToolCallAlternatives(),
                $taskPlanItem->doneRequiresToolCallArgumentsAbsent,
                $taskPlanItem->doneRequiresToolCallArgumentsPresent,
            );
            $evidenceCallIndex = $evidenceCall !== null ? $conversationMessages->buildToolCallHistory()->getMessageIndexOfToolCall($evidenceCall) : null;
            if ($evidenceCallIndex !== null && $evidenceCallIndex > $sameTurnAnswerFloorIndex) {
                $sameTurnAnswerFloorIndex = $evidenceCallIndex;
            }
        }
        if ($taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn
            && !$conversationMessages->hasOwnerVisibleAssistantAnswerAfterIndex($sameTurnAnswerFloorIndex)
        ) {
            $this->previousTurnDeliveryEvidenceIndex = $taskPlanItem->previousTurnDeliveryEvidenceToolNames !== null
                ? $conversationMessages->findPreviousTurnDeliveryEvidenceIndex(
                    $currentTurnInitiatorMessageIndex,
                    $taskPlanItem->previousTurnDeliveryEvidenceToolNames
                )
                : null;
            if ($this->previousTurnDeliveryEvidenceIndex === null) {
                // Not applied, never a failed call — a rejection sent the model into
                // tick → bounce → tick loops; the turn-end closer
                // closes the leaf once the answer after its evidence call is persisted.
                return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — the platform closes this item itself the moment your ANSWER TEXT "
                    . 'to the owner carries the result (a status line does not count). Do NOT tick it again: write the answer.');
            }
        }
        // The leaf waits for its QUESTION: a done tick before the owner was asked is not applied (the platform closes
        // the leaf on the asked question, or on the owner's answer on the result's widget).
        $taskPlanItemPlatformCloser = TaskPlanItemPlatformCloser::forItem($taskPlanItem);
        if ($taskPlanItemPlatformCloser->waitsForAnOwnerQuestion()
            && $taskPlanItemPlatformCloser->describeOwnerQuestionAskedOrWidgetAnsweredAfter($conversationMessages, $evidenceCallIndex) === null
        ) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — this item closes when the owner was ASKED: "
                . "askOwner (questionKey `{$taskPlanItem->doneRequiresOwnerQuestionKey}`, or your own question without a key) after the result is shown"
                . (($taskPlanItem->doneAlsoOnOwnerAnswerOfWidget ?? null) !== null ? ", or the owner's answer on the {$taskPlanItem->doneAlsoOnOwnerAnswerOfWidget} widget" : '')
                . '. Naming the result is not asking. The platform closes it then — do not tick it.');
        }
        $doneToolCallGateWaivedAsEntitlementLocked = ($taskPlanItem->doneRequiresToolCall ?? null) !== null
            && array_intersect($taskPlanItem->getDoneRequiresToolCallAlternatives(), $this->entitlementLockedToolNames) !== [];
        if (($taskPlanItem->doneRequiresToolCall ?? null) !== null
            && !$doneToolCallGateWaivedAsEntitlementLocked
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                $taskPlanItem->getDoneRequiresToolCallAlternatives(),
                $taskPlanItem->doneRequiresToolCallArgumentsAbsent,
                $taskPlanItem->doneRequiresToolCallArgumentsPresent,
            ) === null
        ) {
            // The platform closes a tool-gated item from the call's record — the line never sends the model to run the
            // write for the tick's sake; it names the argument filters, or the model loops on "but I did call it".
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — this item closes from the record of a completed "
                . '`' . implode('` or `', $taskPlanItem->getDoneRequiresToolCallAlternatives()) . '` call; the platform ticks it then. '
                . 'Never run that write only to tick it: the write follows its own preconditions '
                . '(the owner\'s approval where the item is a line of the draft).'
                . $this->describeDoneToolCallDisqualifiers());
        }
        // With the tool-call gate waived, the declared filled fields (derived from exactly the locked tool's result) are
        // unproducible without inventing values: only the widget BLOCK itself is required.
        $enforcedWidgetFilledFields = $doneToolCallGateWaivedAsEntitlementLocked ? null : $taskPlanItem->doneRequiresWidgetFilledFields;
        if ($this->taskPlanEvidence->ownerIsOnThePhoneLine
            && ($taskPlanItem->doneRequiresWidget ?? null) !== null
            && !$conversationMessages->hasAssistantWidgetBlockOfTypeWithFilledFields($taskPlanItem->doneRequiresWidget, $enforcedWidgetFilledFields)
        ) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — " . self::PHONE_LINE_WIDGET_STEP_NOTE);
        }
        if (($taskPlanItem->doneRequiresWidget ?? null) !== null
            && !$conversationMessages->hasAssistantWidgetBlockOfTypeWithFilledFields($taskPlanItem->doneRequiresWidget, $enforcedWidgetFilledFields)
        ) {
            return $this->noteMissingDoneWidget($enforcedWidgetFilledFields);
        }
        // The widget is the OWNER's question: a rendered widget is no evidence until the owner answered ON it.
        if (($taskPlanItem->doneRequiresWidget ?? null) !== null
            && $taskPlanItem->doneRequiresWidgetAnswered
            && TaskPlanItemPlatformCloser::resolveOwnerAnswerOnNewestWidgetOfType($conversationMessages, $taskPlanItem->doneRequiresWidget) !== true
        ) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': done not applied — the owner answers ON the {$taskPlanItem->doneRequiresWidget} widget; "
                . 'the platform closes this item on their answer.'
                . ($this->taskPlanEvidence->ownerIsOnThePhoneLine ? ' ' . self::PHONE_LINE_WIDGET_STEP_NOTE : ''));
        }
        // The rendered connect widget satisfies the widget gate at exactly the moment the item is NOT done:
        // the purpose's LIVE connection state decides; no pending note — the next move is to leave the item alone.
        if (($taskPlanItem->doneRequiresConnectedChannel ?? null) !== null && $this->taskPlanDoneCriterionInputs->readsChannelConnections()) {
            $connectionState = $this->taskPlanDoneCriterionInputs->findChannelConnectionState((string)$taskPlanItem->doneRequiresConnectedChannel);
            if ($connectionState !== true) {
                $channelLabel = $taskPlanItem->getConnectedChannelLabel();
                return TaskPlanItemTickVerdict::noted($connectionState === false
                    ? "Task '{$this->taskAddress}': done not applied — {$channelLabel} is NOT connected. "
                        . $taskPlanItem->getNotConnectedChannelAdvice()
                    : "Task '{$this->taskAddress}': done not applied — the connection state of {$channelLabel} could not be read right now; "
                        . 'the item stays open, continue with the next item.');
            }
        }
        return null;
    }

    /**
     * G18–G19 — the not_applicable evidence, read only with the transcript. G18: a seeded verdict the agent may claim only
     * after the named tool call persisted the owner's answer — a call of that tool that
     * did no work by design counts: the tool's own "nothing to do" is the n/a's evidence (a search not run
     * after the owner's recorded statement that there is nothing to find). G19: a completed call of
     * the named tool PROVES the topic is real — n/a contradicts it. (The rendered-offer widget gate is
     * gone with its dead declaration.) The criterion pre-resolution bypasses these gates by design.
     */
    public function checkNotApplicableEvidence(): ?TaskPlanItemTickVerdict
    {
        if ($this->status !== TaskPlanItem::STATUS_NOT_APPLICABLE || !$this->taskPlanEvidence->hasTranscript) {
            return null;
        }
        $taskPlanItem = $this->taskPlanItem;
        $conversationMessages = $this->taskPlanEvidence->conversationMessages;
        if (($taskPlanItem->notApplicableRequiresToolCall ?? null) !== null
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByToolName(
                $taskPlanItem->notApplicableRequiresToolCall,
                $taskPlanItem->notApplicableRequiresToolCallArgumentsAbsent,
                $taskPlanItem->notApplicableRequiresToolCallArgumentsPresent,
                callThatDidNoWorkByDesignCounts: true,
            ) === null
        ) {
            $requiredArgumentsNote = self::describeRequiredArguments($taskPlanItem->notApplicableRequiresToolCallArgumentsPresent);
            foreach ($taskPlanItem->notApplicableRequiresToolCallArgumentsAbsent ?? [] as $argumentName => $disallowedValues) {
                $requiredArgumentsNote .= ' A call with ' . $argumentName . ' containing '
                    . implode(' / ', array_map(static fn($disallowedValue): string => json_encode($disallowedValue) ?: '?', (array)$disallowedValues))
                    . ' does NOT count (a statement about that topic is not this verdict).';
            }
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': not_applicable not applied — this verdict needs the owner's "
                . "answer persisted first: a completed `{$taskPlanItem->notApplicableRequiresToolCall}` call"
                . $requiredArgumentsNote
                . '. The item stays open until that record exists.');
        }
        if (($taskPlanItem->notApplicableForbiddenAfterToolCall ?? null) !== null
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByToolName(
                $taskPlanItem->notApplicableForbiddenAfterToolCall,
            ) !== null
        ) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': not_applicable not applied — a completed "
                . "`{$taskPlanItem->notApplicableForbiddenAfterToolCall}` call exists in THIS conversation, "
                . 'which contradicts "does not apply": the topic is real for this ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '. The item stays open; '
                . 'after a rendered offer the owner\'s deferral makes it blocked (requiresUserAction).');
        }
        return null;
    }

    /**
     * G21–G24 — the blocked evidence. On an offer item `blocked` claims the owner DEFERRED the offer: the rendered offer widget, or the owner's RECORDED words, is the evidence —
     * G21 on the phone line, G22 the offer never rendered, G23 a PROSE offer without the recorded words. G24: a
     * TRANSIENT obstacle on a leaf the platform closes from the record re-opens it with the obstacle noted —
     * read without the transcript too.
     */
    public function checkBlockedEvidence(): ?TaskPlanItemTickVerdict
    {
        if ($this->status !== TaskPlanItem::STATUS_BLOCKED) {
            return null;
        }
        $taskPlanItem = $this->taskPlanItem;
        $hasTranscript = $this->taskPlanEvidence->hasTranscript;
        $conversationMessages = $this->taskPlanEvidence->conversationMessages;
        $requiresUserAction = $this->setTaskStatusArgumentsUpdate->requiresUserAction;
        $ownerStatementRecordedForBlock = ($taskPlanItem->blockedRequiresToolCall ?? null) !== null
            && $hasTranscript
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByToolName(
                $taskPlanItem->blockedRequiresToolCall,
                null,
                $taskPlanItem->blockedRequiresToolCallArgumentsPresent,
            ) !== null;
        $statementGateHint = ($taskPlanItem->blockedRequiresToolCall ?? null) !== null
            ? " Alternatively, when the owner deferred or declined IN THEIR OWN WORDS (before any widget), record those words first with `{$taskPlanItem->blockedRequiresToolCall}`"
                . self::describeRequiredArguments($taskPlanItem->blockedRequiresToolCallArgumentsPresent)
                . ' — then blocked (requiresUserAction) is accepted without the widget.'
            : '';
        // the offer widget is looked up once for the two gates that ask
        $blockedOfferWidgetIsUnshown = $hasTranscript
            && ($taskPlanItem->blockedRequiresWidget ?? null) !== null
            && !$conversationMessages->buildShownWidgets()->hasShownOfType($taskPlanItem->blockedRequiresWidget);
        if ($this->taskPlanEvidence->ownerIsOnThePhoneLine
            && $blockedOfferWidgetIsUnshown
            && !$ownerStatementRecordedForBlock
        ) {
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': blocked not applied — " . self::PHONE_LINE_WIDGET_STEP_NOTE
                . ' When the owner declined or deferred in their own words, record them first or set not_applicable'
                . ' with that reason.' . $statementGateHint);
        }
        if ($blockedOfferWidgetIsUnshown
            && !$ownerStatementRecordedForBlock
        ) {
            $widgetFenceExample = AIConversationMessage::buildWidgetFenceBlock($taskPlanItem->blockedRequiresWidget, $this->taskPlanEvidence->widgetSelfHydrationKey);
            // The not_applicable exit leads: a fence-first ordering made the model render the offer AFTER a decline.
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': blocked not applied — blocked here means the owner DEFERRED "
                . "an offer, and the offer (the ```widget block of type \"{$taskPlanItem->blockedRequiresWidget}\") "
                . 'was never rendered in this conversation. The item stays open. When the owner already declined or '
                . 'deferred in prose before any offer, or the channel is never relevant for this ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . ', '
                . 'not_applicable with that reason is the verdict — never the widget after a decline. The offer itself is '
                . "one short line, then on its own lines:\n{$widgetFenceExample}\n— blocked stands only after the "
                . 'owner defers.' . $statementGateHint);
        }
        if ($hasTranscript
            && ($taskPlanItem->blockedRequiresWidget ?? null) === null
            && ($taskPlanItem->blockedRequiresToolCall ?? null) !== null
            && $requiresUserAction
            && !$ownerStatementRecordedForBlock
        ) {
            // A PROSE offer: only the owner's recorded words show the deferral; a technical block passes untouched.
            return TaskPlanItemTickVerdict::noted("Task '{$this->taskAddress}': blocked not applied — blocked with requiresUserAction means the "
                . 'owner deferred or declined this offer, and no record of their words exists. The item stays open '
                . "until their words are recorded with `{$taskPlanItem->blockedRequiresToolCall}`"
                . self::describeRequiredArguments($taskPlanItem->blockedRequiresToolCallArgumentsPresent)
                . '. A technical failure is blocked with requiresUserAction=false and the concrete error in statusNote.');
        }
        if (!$requiresUserAction
            && $this->setTaskStatusArgumentsUpdate->retryable
            && $taskPlanItem->isClosedByThePlatformFromTheRecord()
            && ($taskPlanItem->subtasks === null || $taskPlanItem->subtasks->count() === 0)
            && $taskPlanItem->platformResolvedAtMessageIndex === null
        ) {
            $taskPlanItem->reopenWithObstacleNote($this->setTaskStatusArgumentsUpdate->statusNote);
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_NOTED_OBSTACLE, [
                "Task '{$this->taskAddress}': blocked not applied — a transient obstacle never closes this item: it stays OPEN"
                . ' (the platform closes it from the record once its write lands). Retry it in THIS turn as soon as the'
                . ' obstacle is gone (after the save it waited for); do not mark it blocked again.',
            ]);
        }
        return null;
    }

    /**
     * G25, G26, G28 — the transition. G25: a DONE item stays done — a later not_applicable is the model re-judging a finished
     * leaf; a later blocked stays possible. G26: a same-status re-tick is a NO-OP (a note refresh) —
     * on a container the line names its open subtasks by key; the lecture comes once per CALL
     * (`$callLecturedAlreadyInStatus`), only the lectured no-op counts toward the all-no-op breaker. (G27, the
     * judgement park, is gone: on an item without a record gate the done applies at once — the model did the
     * work, its tick is the evidence.) G28: the model's status applies
     * ({@see TaskPlanItem::applyStatusChosenByTheModel()}), a next-turn delivery acceptance is recorded, and on a criterion
     * leaf the record's verdict read after the transition lands — confirming the model's status or overriding it.
     */
    public function applyTick(TaskPlanItems $taskPlanItems, bool $callLecturedAlreadyInStatus): TaskPlanItemTickVerdict
    {
        $taskPlanItem = $this->taskPlanItem;
        $status = $this->status;
        $statusNote = $this->setTaskStatusArgumentsUpdate->statusNote;
        $previousStatus = $taskPlanItem->status;
        if ($previousStatus === TaskPlanItem::STATUS_DONE && $status === TaskPlanItem::STATUS_NOT_APPLICABLE) {
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_DONE_STAYS_DONE, [
                "Task '{$this->taskAddress}' is DONE — a done item stays done; 'not_applicable' not applied. "
                . 'Nothing to correct: the record closed it. Work the open items.',
            ]);
        }
        if ($status === $previousStatus && $status !== TaskPlanItem::STATUS_OPEN) {
            $taskPlanItem->refreshStatusNoteUnlessPreResolvedAtSeed($statusNote);
            return $this->answerSameStatusReTick($taskPlanItems, $callLecturedAlreadyInStatus);
        }
        $taskPlanItem->applyStatusChosenByTheModel($status, $statusNote, $this->setTaskStatusArgumentsUpdate->requiresUserAction, $this->setTaskStatusArgumentsUpdate->retryable);
        // the next-turn delivery acceptance is recorded only on the APPLIED tick — a tick a later gate kept open must not
        // leave an "accepted delivery" record
        if ($status === TaskPlanItem::STATUS_DONE && $this->previousTurnDeliveryEvidenceIndex !== null) {
            $taskPlanItem->recordAcceptedDeliveryEvidenceAt($this->previousTurnDeliveryEvidenceIndex);
        }
        $feedbackLines = [];
        if (($status === TaskPlanItem::STATUS_DONE || $status === TaskPlanItem::STATUS_NOT_APPLICABLE)
            && ($taskPlanItem->doneCriterion ?? null) !== null
        ) {
            // the record read AFTER the transition: a criterion that reads the plan (whether a round is still open) can
            // answer only now — it confirms the model's status or overrides it
            $recordResolution = $this->resolveTheItemsCriterion();
            if ($recordResolution instanceof TaskPlanDoneCriterionVerdict
                && $recordResolution->status === $status
                && trim((string)$recordResolution->statusNote) !== ''
            ) {
                $taskPlanItem->adoptRecordVerdictOnAppliedTick($recordResolution, $this->taskPlanEvidence->currentTurnInitiatorMessageIndex);
            } elseif ($recordResolution instanceof TaskPlanDoneCriterionVerdict
                && in_array($recordResolution->status, [TaskPlanItem::STATUS_DONE, TaskPlanItem::STATUS_NOT_APPLICABLE], true)
                && !$recordResolution->isStillOpen()
                && trim((string)$recordResolution->statusNote) !== ''
            ) {
                $taskPlanItem->adoptRecordVerdictOnAppliedTick($recordResolution, $this->taskPlanEvidence->currentTurnInitiatorMessageIndex);
                $feedbackLines[] = "Task '{$this->taskAddress}': the record decides this item — {$recordResolution->status} (your {$status} was not applied): "
                    . $recordResolution->statusNote . '.';
            }
        }
        // A TRANSITION into a terminal status is a RESOLUTION (the checked stop's progress exemption); a re-open is not.
        return TaskPlanItemTickVerdict::ofKind(
            $status !== $previousStatus && $status !== TaskPlanItem::STATUS_OPEN
                ? TaskPlanItemTickVerdict::KIND_APPLIED_AS_RESOLUTION
                : TaskPlanItemTickVerdict::KIND_APPLIED,
            $feedbackLines
        );
    }

    /**
     * G26's answer: a container's stored status is terminal while it renders unchecked (its subtasks are open) — the line
     * names the mechanics and the open subtasks by key, never counted (forcing a tool-less answer there would demand the
     * opposite of the feedback); a repeat after the call's lecture is one short line; the first true no-op carries the
     * lecture (one paragraph per CALL — five copies buried the line that differs).
     */
    protected function answerSameStatusReTick(TaskPlanItems $taskPlanItems, bool $callLecturedAlreadyInStatus): TaskPlanItemTickVerdict
    {
        $status = $this->status;
        if ($this->taskPlanItem->subtasks !== null && $this->taskPlanItem->subtasks->hasOpenItems()) {
            $openSubtaskKeys = $taskPlanItems->getOpenSubtaskKeysOf($this->taskPlanItem);
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_ALREADY_IN_STATUS, [
                "Task '{$this->taskAddress}' itself is ALREADY {$status}; it renders unchecked only "
                . 'because subtasks are still open. NEVER set a container\'s status — work its open '
                . 'subtasks instead: ' . implode(', ', $openSubtaskKeys) . '.',
            ]);
        }
        if ($callLecturedAlreadyInStatus) {
            return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_ALREADY_IN_STATUS, [
                "Task '{$this->taskAddress}' is ALREADY {$status} — no change (see above).",
            ]);
        }
        return TaskPlanItemTickVerdict::ofKind(TaskPlanItemTickVerdict::KIND_ALREADY_IN_STATUS_WITH_LECTURE, [
            "Task '{$this->taskAddress}' is ALREADY {$status} — no change. Nothing failed and "
            . "nothing is unavailable; NEVER tell the user you lack access. Do NOT re-tick. Look at "
            . "the plan: if the '← next' open item needs NO owner input, CONTINUE WORKING IT in THIS "
            . "turn — ending your turn with an announcement of that work ('next I will …') is a "
            . "defect; a status line belongs in a {preCallMessage} before the call that does the "
            . "work, never in your final text. Write your final answer (including any owed widget "
            . "fence) only when every remaining open item genuinely needs the owner, or none is left.",
        ]);
    }

    /**
     * G15's line: the widget the item requires is not in the answer text, or rendered without its declared fields —
     * the fence is dictated in the line itself (the skill documenting the widget can be evicted by the handover); the
     * n/a exit is named next to it (a widget authored for nothing).
     *
     * @param array<string, mixed>|null $enforcedWidgetFilledFields
     */
    protected function noteMissingDoneWidget(?array $enforcedWidgetFilledFields): TaskPlanItemTickVerdict
    {
        $taskPlanItem = $this->taskPlanItem;
        $widgetFenceExample = AIConversationMessage::buildWidgetFenceBlock(
            $taskPlanItem->doneRequiresWidget,
            $this->taskPlanEvidence->widgetSelfHydrationKey,
            $enforcedWidgetFilledFields ?? []
        );
        $filledFieldsSentence = $enforcedWidgetFilledFields !== null
            ? ' Replace every <placeholder> with the real value — the gate rejects a block whose '
                . 'declared fields are missing, empty or still placeholders.'
            : '';
        $rejectionCause = $this->taskPlanEvidence->conversationMessages->buildShownWidgets()->hasShownOfType($taskPlanItem->doneRequiresWidget)
            ? "your \"{$taskPlanItem->doneRequiresWidget}\" ```widget block exists but its declared "
                . 'fields are not filled (or the block is not valid JSON) — RE-EMIT it complete'
            : "this item requires a ```widget block of type \"{$taskPlanItem->doneRequiresWidget}\" "
                . 'in your ANSWER TEXT first (it is NOT a tool)';
        $feedbackLine = "Task '{$this->taskAddress}': done not applied — " . $rejectionCause . '. '
            . "The block, on its own lines in your answer text, is:\n{$widgetFenceExample}\n"
            . 'The platform closes the item when the block stands in the answer.' . $filledFieldsSentence
            . ' If there is genuinely nothing to show (the tool found nothing to confirm, no data behind '
            . 'the widget), set the item not_applicable with that reason instead — NEVER author a widget for nothing.';
        return TaskPlanItemTickVerdict::noted($feedbackLine);
    }

    /** G13's clause naming the done gate's argument filters: the disqualifying values, then the required ones. */
    protected function describeDoneToolCallDisqualifiers(): string
    {
        $disqualifierNote = '';
        foreach ($this->taskPlanItem->doneRequiresToolCallArgumentsAbsent ?? [] as $argumentName => $disallowedValues) {
            $disqualifierNote .= ' A call with ' . $argumentName . ' = '
                . implode(' / ', array_map(static fn($disallowedValue): string => json_encode($disallowedValue) ?: '?', (array)$disallowedValues))
                . ' does NOT count.';
        }
        foreach ($this->taskPlanItem->doneRequiresToolCallArgumentsPresent ?? [] as $argumentName => $acceptedValues) {
            $disqualifierNote .= ' Only a call with ' . $argumentName . ' = '
                . implode(' / ', array_map(static fn($acceptedValue): string => json_encode($acceptedValue) ?: '?', (array)$acceptedValues))
                . ' counts.';
        }
        return $disqualifierNote;
    }

    /**
     * " with topics = "booking" / "invoice", kind = "ABSENCE" / "DECLINE"" — the accepted-argument clause of a
     * gate's line (empty without a filter).
     *
     * @param array<string, array<int, mixed>|mixed>|null $requiredArgumentValues
     */
    protected static function describeRequiredArguments(?array $requiredArgumentValues): string
    {
        $clause = '';
        foreach ($requiredArgumentValues ?? [] as $argumentName => $acceptedValues) {
            $clause .= ' with ' . $argumentName . ' = '
                . implode(' / ', array_map(static fn($acceptedValue): string => json_encode($acceptedValue) ?: '?', (array)$acceptedValues));
        }
        return $clause;
    }
}
