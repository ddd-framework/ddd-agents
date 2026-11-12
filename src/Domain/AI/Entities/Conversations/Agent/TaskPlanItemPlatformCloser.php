<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\AI\Entities\Conversations\Widgets\AnswerableConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidget;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The PLATFORM's closing decisions for ONE task-plan item: each method reads the item's declared
 * gates against what the conversation and the purpose's criteria record — the criterion verdict, a completed call, the
 * rendered or answered widget, the delivered answer — and, when the record
 * decides, applies the matching intent-named transition of the item ({@see TaskPlanItem::closeFromCriterionVerdict()},
 * {@see TaskPlanItem::closeOnCompletedCall()}, …). The item keeps its state and its transitions; this object holds the
 * questions that combine the item with the record, so the item does not grow with them. The tree walks of
 * {@see TaskPlanItems} build one per visited node ({@see forItem()}). A working value over a reference into the tree —
 * never serialized.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanItemPlatformCloser extends ValueObject
{
    protected TaskPlanItem $taskPlanItem;

    /** The closer of `$taskPlanItem` — a node of a live plan tree; its transitions write that node. */
    public static function forItem(TaskPlanItem $taskPlanItem): static
    {
        $taskPlanItemPlatformCloser = new static();
        $taskPlanItemPlatformCloser->taskPlanItem = $taskPlanItem;
        return $taskPlanItemPlatformCloser;
    }

    /**
     * The pre-resolution of this node ({@see TaskPlanItems::applyPreResolutions()} walks the tree post-order): an OPEN node
     * with a {@see TaskPlanItem::$doneCriterion} — or one the MODEL blocked or set not_applicable (a claim about the record; the record
     * decides), or a PLATFORM park or not_applicable that only a DONE overriding it reopens — takes the criterion's verdict, unless the
     * verdict is open or repeats the model's own blocked / not_applicable. `$onlyDoneCriterionKeys` (the post-write
     * re-sweep) limits the keys read; null reads every one. The criterion is evaluated when the walk reaches this node,
     * over the inputs' live plan. Returns whether the node was resolved.
     *
     * @param string[]|null $onlyDoneCriterionKeys
     */
    public function applyPreResolution(
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs,
        ?AIConversationMessages $conversationMessages = null,
        ?array $onlyDoneCriterionKeys = null
    ): bool {
        // OPEN leaves, and leaves the MODEL blocked (a blocked leaf is a claim about the record; the record decides):
        // a value saved by another way must not leave its leaf "blocked — the owner must give the value".
        // A PLATFORM park is re-read too, but only a DONE that names the very fact the park waited for reopens it
        // (a leaf parked on a mis-recorded "nothing to give" statement reopens when the value is saved after all);
        // an owner's declined offer stays declined.
        $modelBlocked = $this->taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED && $this->taskPlanItem->platformResolvedAtMessageIndex === null;
        $platformBlocked = $this->taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED && !$modelBlocked;
        // A not_applicable the MODEL set on a criterion leaf is a claim about the record too — the record decides it
        // like a model-set blocked: a leaf ticked n/a in the first turn would otherwise stay n/a while the platform's
        // later verdict on the same leaf never applied. A platform n/a is
        // re-read like a platform park: only a DONE from the fact it ruled out reopens it (a leaf closed
        // "nothing named" right after a save, then the value was saved in the same response).
        $modelNotApplicable = $this->taskPlanItem->status === TaskPlanItem::STATUS_NOT_APPLICABLE && $this->taskPlanItem->platformResolvedAtMessageIndex === null;
        $platformNotApplicable = $this->taskPlanItem->status === TaskPlanItem::STATUS_NOT_APPLICABLE && !$modelNotApplicable;
        if ($this->taskPlanItem->doneCriterion === null || ($this->taskPlanItem->status !== TaskPlanItem::STATUS_OPEN && !$modelBlocked && !$platformBlocked && !$modelNotApplicable && !$platformNotApplicable)) {
            return false;
        }
        // the post-write re-sweep names the criteria the completed tool can have changed — every other leaf keeps
        // its state without a facet read (null = every open criterion leaf, the seed-time behaviour)
        if ($onlyDoneCriterionKeys !== null && !in_array($this->taskPlanItem->doneCriterion, $onlyDoneCriterionKeys, true)) {
            return false;
        }
        $taskPlanDoneCriterionVerdict = $taskPlanDoneCriteria->resolveKey($this->taskPlanItem->doneCriterion, $taskPlanDoneCriterionInputs);
        if ($taskPlanDoneCriterionVerdict === null || $taskPlanDoneCriterionVerdict->isStillOpen()
            || ($modelBlocked && $taskPlanDoneCriterionVerdict->status === TaskPlanItem::STATUS_BLOCKED)
            || ($modelNotApplicable && $taskPlanDoneCriterionVerdict->status === TaskPlanItem::STATUS_NOT_APPLICABLE)
            || (($platformBlocked || $platformNotApplicable) && !($taskPlanDoneCriterionVerdict->status === TaskPlanItem::STATUS_DONE && $taskPlanDoneCriterionVerdict->overridesPlatformBlock))
        ) {
            return false;
        }
        // Provenance: the platform decided this leaf from live state — at seed (before the dialog) or in the post-write
        // re-sweep — it is not the agent's progress (the customer stepper must not read it as work reached; the judge
        // trace renders the moment as `(platform-resolved @N)`).
        $this->taskPlanItem->closeFromCriterionVerdict($taskPlanDoneCriterionVerdict, $conversationMessages?->getNewestMessageIndex() ?? 0);
        return true;
    }

    /**
     * The verdict a completed call of `$toolName` proves for this leaf, or null when it proves none (or two — the platform
     * never guesses between two verdicts): `done` when the leaf names the tool as its done-evidence, a completed call passes
     * the argument filters and nothing else is asked (no widget, no delivered answer, no connected channel, not a criterion
     * leaf under a purpose whose criteria decide); `blocked` when its {@see TaskPlanItem::$blockedRequiresToolCall} record landed;
     * `not_applicable` when its {@see TaskPlanItem::$notApplicableRequiresToolCall} record landed and no contradiction gate forbids it —
     * never on a leaf whose widget the OWNER answers ({@see TaskPlanItem::$doneRequiresWidgetAnswered}): there the
     * owner's answer decides (a decline closes it n/a) and the call only allows the model's n/a tick (a search runs
     * whether or not it finds something). Reads the evidence's messages and
     * {@see TaskPlanEvidence::$criteriaDecideTicks}.
     */
    public function getStatusProvenByCompletedCall(string $toolName, TaskPlanEvidence $taskPlanEvidence): ?string
    {
        $criteriaDecideTicks = $taskPlanEvidence->criteriaDecideTicks;
        $conversationMessages = $taskPlanEvidence->conversationMessages;
        $provenStatuses = [];
        if (in_array($toolName, $this->taskPlanItem->getDoneRequiresToolCallAlternatives(), true)
            && !($criteriaDecideTicks && $this->taskPlanItem->doneCriterion !== null)
            && ($this->taskPlanItem->doneRequiresWidget ?? null) === null
            && !$this->taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn
            && ($this->taskPlanItem->doneRequiresConnectedChannel ?? null) === null
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                $this->taskPlanItem->getDoneRequiresToolCallAlternatives(),
                $this->taskPlanItem->doneRequiresToolCallArgumentsAbsent,
                $this->taskPlanItem->doneRequiresToolCallArgumentsPresent,
            ) !== null
        ) {
            $provenStatuses[] = TaskPlanItem::STATUS_DONE;
        }
        if ($this->taskPlanItem->blockedRequiresToolCall === $toolName
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                [$toolName],
                null,
                $this->taskPlanItem->blockedRequiresToolCallArgumentsPresent,
            ) !== null
        ) {
            $provenStatuses[] = TaskPlanItem::STATUS_BLOCKED;
        }
        if ($this->taskPlanItem->notApplicableRequiresToolCall === $toolName
            && !$this->taskPlanItem->doneRequiresWidgetAnswered
            && !(($this->taskPlanItem->notApplicableForbiddenAfterToolCall ?? null) !== null
                && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName([$this->taskPlanItem->notApplicableForbiddenAfterToolCall]) !== null)
            && $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                [$toolName],
                $this->taskPlanItem->notApplicableRequiresToolCallArgumentsAbsent,
                $this->taskPlanItem->notApplicableRequiresToolCallArgumentsPresent,
            ) !== null
        ) {
            $provenStatuses[] = TaskPlanItem::STATUS_NOT_APPLICABLE;
        }
        return count($provenStatuses) === 1 ? $provenStatuses[0] : null;
    }

    /**
     * Close this leaf when the completed call `$toolName` at `$toolCallMessageIndex` proves a verdict for it
     * ({@see getStatusProvenByCompletedCall()}) — open leaves and leaves the model blocked; the note names the tool and the
     * call's index. Returns the status written, or null.
     */
    public function closeWhenCompletedCallProvesStatus(string $toolName, int $toolCallMessageIndex, TaskPlanEvidence $taskPlanEvidence): ?string
    {
        if (!$this->taskPlanItem->isOpenOrBlockedByTheModel()) {
            return null;
        }
        $closingStatus = $this->getStatusProvenByCompletedCall($toolName, $taskPlanEvidence);
        if ($closingStatus === null) {
            return null;
        }
        $this->taskPlanItem->closeOnCompletedCall($closingStatus, $toolName, $toolCallMessageIndex);
        return $closingStatus;
    }

    /**
     * The TURN-END half for this leaf ({@see TaskPlanItems::closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd()}):
     * a widget leaf whose widget (the model's or a platform-written one, or the candidate answer's) is rendered and
     * whose tool gate, if declared, is satisfied or entitlement-waived; a widget the OWNER answers closes on the
     * answer only — a decline closes it not_applicable; a delivery leaf
     * ({@see TaskPlanItem::$doneRequiresOwnerVisibleAnswerInSameTurn}) whose evidence
     * call lies in THIS turn and is followed by an owner-visible answer, or that the previous turn delivered. Offers that
     * wait for the owner's click (a connected channel, a done-widget that is the blocked offer) never
     * close here. Returns the status written, or null.
     */
    public function applyTurnEndEvidenceClosing(TaskPlanEvidence $taskPlanEvidence): ?string
    {
        $conversationMessages = $taskPlanEvidence->conversationMessages;
        $currentTurnInitiatorMessageIndex = $taskPlanEvidence->currentTurnInitiatorMessageIndex;
        $candidateAnswerText = $taskPlanEvidence->candidateAnswerText;
        if (!$this->taskPlanItem->isOpenOrBlockedByTheModel()
            || ($taskPlanEvidence->criteriaDecideTicks && $this->taskPlanItem->doneCriterion !== null)
            || ($this->taskPlanItem->doneRequiresConnectedChannel ?? null) !== null
            || (($this->taskPlanItem->doneRequiresWidget ?? null) !== null && $this->taskPlanItem->doneRequiresWidget === $this->taskPlanItem->blockedRequiresWidget)
            || (($this->taskPlanItem->doneRequiresWidget ?? null) === null && !$this->taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn)
        ) {
            return null;
        }
        $evidenceCallIndex = null;
        if ($this->taskPlanItem->doneRequiresToolCall !== null) {
            $toolGateWaived = $taskPlanEvidence->locksAnyOfTools($this->taskPlanItem->getDoneRequiresToolCallAlternatives());
            $evidenceCall = $toolGateWaived ? null : $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                $this->taskPlanItem->getDoneRequiresToolCallAlternatives(),
                $this->taskPlanItem->doneRequiresToolCallArgumentsAbsent,
                $this->taskPlanItem->doneRequiresToolCallArgumentsPresent,
            );
            if (!$toolGateWaived && $evidenceCall === null) {
                return null;
            }
            $evidenceCallIndex = $evidenceCall !== null ? $conversationMessages->buildToolCallHistory()->getMessageIndexOfToolCall($evidenceCall) : null;
        }
        if (($this->taskPlanItem->doneRequiresWidget ?? null) !== null
            && !$conversationMessages->hasAssistantWidgetBlockOfTypeWithFilledFields($this->taskPlanItem->doneRequiresWidget, $this->taskPlanItem->doneRequiresWidgetFilledFields)
            && !($candidateAnswerText !== null && AIConversationMessages::textCarriesWidgetBlockOfTypeWithFilledFields($candidateAnswerText, $this->taskPlanItem->doneRequiresWidget, $this->taskPlanItem->doneRequiresWidgetFilledFields))
        ) {
            return null;
        }
        // The widget is the OWNER's question: the render alone closes nothing — the owner's answer does (else the
        // leaf closes on the render while the owner has picked nothing). A DECLINE closes the leaf
        // not_applicable (nothing to confirm — the capture continues in the next leaf); the leaf would otherwise
        // stay "awaiting" for the rest of the run.
        if (($this->taskPlanItem->doneRequiresWidget ?? null) !== null && $this->taskPlanItem->doneRequiresWidgetAnswered) {
            $ownerAnswer = self::resolveOwnerAnswerOnNewestWidgetOfType($conversationMessages, $this->taskPlanItem->doneRequiresWidget);
            if ($ownerAnswer === false) {
                $this->taskPlanItem->closeOnDeclinedOwnerWidget($conversationMessages->getNewestMessageIndex() ?? 0, $conversationMessages->getNewestMessageIndex());
                return TaskPlanItem::STATUS_NOT_APPLICABLE;
            }
            if ($ownerAnswer !== true) {
                return null;
            }
        }
        $ownerQuestionEvidenceDescription = null;
        if ($this->taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn && $this->waitsForAnOwnerQuestion()) {
            // A leaf that waits for its question: a message that only names the result is no delivery — the asked
            // question (or the owner's answer on the result's widget) closes it, in whichever turn it lands.
            $ownerQuestionEvidenceDescription = $this->describeOwnerQuestionAskedOrWidgetAnsweredAfter($conversationMessages, $evidenceCallIndex);
            if ($ownerQuestionEvidenceDescription === null) {
                return null;
            }
        } elseif ($this->taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn) {
            // The SAME floor as the tick tool: the turn's initiator, raised to the item's own evidence call when
            // that call lies in this turn (an earlier evidence call is served by any answer of this turn; a leaf
            // without a tool gate by the turn's answer alone). The answer is a persisted owner-visible text after
            // the floor, or the candidate text. A leaf that declares `previousTurnDeliveryEvidenceToolNames`
            // also closes on the previous turn's delivery, as the tick tool accepts it.
            // (Else a leaf closes on a text written before its result existed.) Without the leaf's own
            // evidence call in THIS turn the platform cannot know that an answer delivered THIS result — such a
            // leaf closes on the previous turn's delivery where the seed declares it, otherwise it stays open.
            if ($evidenceCallIndex === null || $evidenceCallIndex <= $currentTurnInitiatorMessageIndex) {
                $deliveredThisTurn = false;
            } else {
                $deliveredThisTurn = self::isOwnerVisibleCandidateAnswerText($candidateAnswerText) || $conversationMessages->hasOwnerVisibleAssistantAnswerAfterIndex($evidenceCallIndex);
            }
            $deliveredInPreviousTurn = !$deliveredThisTurn && $this->taskPlanItem->previousTurnDeliveryEvidenceToolNames !== null
                && $conversationMessages->findPreviousTurnDeliveryEvidenceIndex($currentTurnInitiatorMessageIndex, $this->taskPlanItem->previousTurnDeliveryEvidenceToolNames) !== null;
            if (!$deliveredThisTurn && !$deliveredInPreviousTurn) {
                return null;
            }
        }
        $evidenceDescription = ($this->taskPlanItem->doneRequiresWidget ?? null) !== null
            ? $this->taskPlanItem->doneRequiresWidget . ($this->taskPlanItem->doneRequiresWidgetAnswered ? ' widget answered by the owner' : ' widget rendered')
            : ($ownerQuestionEvidenceDescription ?? 'delivered to the owner');
        if ($evidenceCallIndex !== null) {
            $evidenceDescription .= ' after ' . implode('|', $this->taskPlanItem->getDoneRequiresToolCallAlternatives()) . ' @' . $evidenceCallIndex;
        }
        $this->taskPlanItem->closeOnTurnEndEvidence($evidenceDescription, $conversationMessages->getNewestMessageIndex() ?? 0, $conversationMessages->getNewestMessageIndex());
        return TaskPlanItem::STATUS_DONE;
    }

    /** Whether this leaf closes on an asked owner question ({@see TaskPlanItem::$doneRequiresOwnerQuestionKey}). */
    public function waitsForAnOwnerQuestion(): bool
    {
        return ($this->taskPlanItem->doneRequiresOwnerQuestionKey ?? null) !== null;
    }

    /**
     * What proves this leaf's question ({@see TaskPlanItem::$doneRequiresOwnerQuestionKey}), as the closing note words
     * it — or null while nothing does: a completed `askOwner` call carrying that catalogue key, else a completed FREE
     * `askOwner` call (no catalogue key — the platform cannot read which question it is, the model's own question after
     * the result is taken as the ask), each after the leaf's evidence call (`$evidenceCallIndex`, the newest one; null =
     * anywhere) or answered by the owner before that evidence call ({@see isAskedAfterEvidenceOrAnsweredBeforeIt()}); else
     * the owner's answer or decline on the newest widget of {@see TaskPlanItem::$doneAlsoOnOwnerAnswerOfWidget}.
     * Structural only — the calls' recorded arguments, the rows' order and authors, the widget's click rows; never
     * the words. The ONE check the turn-end closing and the model's done tick read.
     */
    public function describeOwnerQuestionAskedOrWidgetAnsweredAfter(AIConversationMessages $conversationMessages, ?int $evidenceCallIndex): ?string
    {
        $questionKey = (string)$this->taskPlanItem->doneRequiresOwnerQuestionKey;
        $toolCallHistory = $conversationMessages->buildToolCallHistory();
        $keyedAskOwnerCall = $toolCallHistory->getNewestResolvedToolCallByAnyToolName(
            [AskOwnerTool::NAME],
            null,
            ['questionKey' => [$questionKey]],
        );
        $keyedAskOwnerCallIndex = $keyedAskOwnerCall !== null ? $toolCallHistory->getMessageIndexOfToolCall($keyedAskOwnerCall) : null;
        if ($this->isAskedAfterEvidenceOrAnsweredBeforeIt($conversationMessages, $keyedAskOwnerCallIndex, $evidenceCallIndex)) {
            return 'the ' . $questionKey . ' question asked @' . $keyedAskOwnerCallIndex;
        }
        // a FREE question: a call whose questionKey is no catalogue key (absent, or one the tool dropped as unknown)
        $freeAskOwnerCall = $toolCallHistory->getNewestResolvedToolCallByAnyToolName(
            [AskOwnerTool::NAME],
            ['questionKey' => AskOwnerQuestionCatalogue::getQuestionKeys()],
        );
        $freeAskOwnerCallIndex = $freeAskOwnerCall !== null ? $toolCallHistory->getMessageIndexOfToolCall($freeAskOwnerCall) : null;
        if ($this->isAskedAfterEvidenceOrAnsweredBeforeIt($conversationMessages, $freeAskOwnerCallIndex, $evidenceCallIndex)) {
            return 'a free owner question asked @' . $freeAskOwnerCallIndex;
        }
        $answeredWidgetType = $this->taskPlanItem->doneAlsoOnOwnerAnswerOfWidget ?? null;
        if ($answeredWidgetType !== null && self::resolveOwnerAnswerOnNewestWidgetOfType($conversationMessages, $answeredWidgetType) !== null) {
            return 'the ' . $answeredWidgetType . ' widget answered by the owner';
        }
        return null;
    }

    /**
     * Whether the ask at `$askOwnerCallIndex` counts for this leaf: it lies after the newest evidence call
     * (`$evidenceCallIndex`; null = no evidence index to compare, any ask counts), or the owner ANSWERED it — an inbound
     * of the owner (text or click) between the ask and the newest evidence call — and an evidence call lay before it.
     * The second arm keeps an answered ask closing the leaf when the owner's answer leads to a new write of the result
     * (the owner changes the saved list, the agent applies it with the update tool): that write does not reopen a
     * question the owner already answered. A call of {@see TaskPlanItem::$doneOwnerQuestionMayFollowToolCall} (a proposal
     * that saves nothing) counts as the earlier call that showed the result: the owner approves the proposal,
     * then the save lands.
     */
    protected function isAskedAfterEvidenceOrAnsweredBeforeIt(AIConversationMessages $conversationMessages, ?int $askOwnerCallIndex, ?int $evidenceCallIndex): bool
    {
        if ($askOwnerCallIndex === null) {
            return false;
        }
        if ($evidenceCallIndex === null || $askOwnerCallIndex > $evidenceCallIndex) {
            return true;
        }
        $toolCallHistory = $conversationMessages->buildToolCallHistory();
        $oldestEvidenceCall = $toolCallHistory->getOldestResolvedToolCallByAnyToolName(array_merge(
            $this->taskPlanItem->getDoneRequiresToolCallAlternatives(),
            $this->taskPlanItem->getOwnerQuestionMayFollowToolCallAlternatives(),
        ));
        $oldestEvidenceCallIndex = $oldestEvidenceCall !== null ? $toolCallHistory->getMessageIndexOfToolCall($oldestEvidenceCall) : null;
        if ($oldestEvidenceCallIndex === null || $oldestEvidenceCallIndex >= $askOwnerCallIndex) {
            return false;
        }
        return self::hasOwnerInboundBetween($conversationMessages, $askOwnerCallIndex, $evidenceCallIndex);
    }

    /** Whether an inbound of the owner (their text or a widget click) lies strictly between the two message indexes. */
    protected static function hasOwnerInboundBetween(AIConversationMessages $conversationMessages, int $afterMessageIndex, int $beforeMessageIndex): bool
    {
        foreach ($conversationMessages->getElements() as $message) {
            $messageIndex = $message->indexInConversation ?? null;
            if ($messageIndex !== null && $messageIndex > $afterMessageIndex && $messageIndex < $beforeMessageIndex && $message->isOwnerAuthoredInbound()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The owner's answer on the newest `$widgetType` widget the rows in memory show — what a leaf whose widget is the
     * owner's question ({@see TaskPlanItem::$doneRequiresWidgetAnswered}) closes on: true = answered, false = declined
     * (and not answered), null = no answer yet or no widget. Read from the shown-widget view of `$conversationMessages`
     * ({@see AIConversationMessages::buildShownWidgets()}): a widget whose class reads its own answer
     * by that class, picked by the registry ({@see ShownConversationWidget::wasAnswered()}), any other type by the
     * generic rules. Where one widget's reader cannot answer ({@see isAnswerReadOverTheWholeRows()}) the type's class
     * reads the whole rows ({@see ConversationWidget::readOwnerAnswerOnTheNewestWidgetOfThisType()}, found by the
     * registry — the AI core names no domain widget), a type without a class the generic rules. Read by the turn-end
     * closing and the tick's G16.
     */
    public static function resolveOwnerAnswerOnNewestWidgetOfType(AIConversationMessages $conversationMessages, string $widgetType): ?bool
    {
        $shownConversationWidgets = $conversationMessages->buildShownWidgets();
        $newestShownWidget = $shownConversationWidgets->findNewestOfType($widgetType);
        if ($newestShownWidget === null || self::isAnswerReadOverTheWholeRows($newestShownWidget, $conversationMessages)) {
            $widgetClass = ConversationWidgetClasses::getWidgetClassForWidgetType($widgetType);
            // the exact type only: a declared `…Directive` spelling is read by the generic rules
            return $widgetClass !== null && $widgetClass::WIDGET_TYPE === $widgetType
                ? $widgetClass::readOwnerAnswerOnTheNewestWidgetOfThisType($conversationMessages, $shownConversationWidgets)
                : $shownConversationWidgets->readOwnerAnswerOnNewestOfTypeByGenericRule($widgetType);
        }
        if ($newestShownWidget->wasAnswered()) {
            return true;
        }
        return $newestShownWidget->isDeclined() ? false : null;
    }

    /**
     * Whether the newest widget's answer is read over the whole rows by its type's class instead of by the one widget's
     * reader ({@see resolveOwnerAnswerOnNewestWidgetOfType()}): the widget stands on a row without an index, or — for a
     * widget whose class reads its own answer ({@see AnswerableConversationWidget}) — its host row carries a second block
     * of its type (the rows of every block in the row count), a widget click names its type that the class gives to
     * another widget (the whole-rows reading counts it), or the tool that wrote the widget ran again
     * after it (the answer after that call counts). A widget of a type without a class reader never is: the generic
     * rules answer the same either way.
     */
    protected static function isAnswerReadOverTheWholeRows(ShownConversationWidget $shownConversationWidget, AIConversationMessages $conversationMessages): bool
    {
        if ($shownConversationWidget->hostIndexInConversation === null) {
            return true;
        }
        $conversationWidget = $shownConversationWidget->buildWidget();
        if (!$conversationWidget instanceof AnswerableConversationWidget) {
            return false;
        }
        if ($shownConversationWidget->ordinalAmongBlocksOfType > 0) {
            return true;
        }
        foreach ($shownConversationWidget->getReactions() as $conversationWidgetReaction) {
            if ($conversationWidgetReaction->isWidgetClickSignal()
                && $conversationWidgetReaction->namesWidgetType($shownConversationWidget->widgetType)
                && !$conversationWidget->isReactionToThisWidget($conversationWidgetReaction)
            ) {
                return true;
            }
        }
        return self::hasCallOfTheWritingToolAfterTheWidget($shownConversationWidget, $conversationMessages);
    }

    /**
     * Whether a TOOL_CALL row of the tool that wrote the widget lies after the widget's host row. The writing tool is the
     * block's `emittedBy` tool, else the label of the tool row that carries the block (a row written before the field);
     * a widget the model or the platform wrote names no tool, so any TOOL_CALL row after it counts.
     */
    protected static function hasCallOfTheWritingToolAfterTheWidget(ShownConversationWidget $shownConversationWidget, AIConversationMessages $conversationMessages): bool
    {
        $writingToolName = self::findWritingToolName($shownConversationWidget, $conversationMessages);
        foreach ($conversationMessages->getElements() as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_TOOL_CALL
                && ($message->indexInConversation ?? -1) > $shownConversationWidget->hostIndexInConversation
                && ($writingToolName === null || ($message->toolCall->name ?? null) === $writingToolName)
            ) {
                return true;
            }
        }
        return false;
    }

    /** The tool that wrote the widget ({@see hasCallOfTheWritingToolAfterTheWidget()}); null when no tool did or none is named. */
    protected static function findWritingToolName(ShownConversationWidget $shownConversationWidget, AIConversationMessages $conversationMessages): ?string
    {
        $conversationWidgetOrigin = $shownConversationWidget->getOrigin();
        if ($conversationWidgetOrigin->isTool()) {
            return $conversationWidgetOrigin->toolName;
        }
        if ($shownConversationWidget->hostMessageType !== AIConversationMessage::MESSAGE_TOOL_MESSAGE) {
            return null;
        }
        foreach ($conversationMessages->getElements() as $message) {
            if (($message->indexInConversation ?? null) === $shownConversationWidget->hostIndexInConversation) {
                $hostRowLabel = $message->signalSource ?? null;
                return is_string($hostRowLabel) && $hostRowLabel !== '' && !str_starts_with($hostRowLabel, ConversationWidgetOrigin::PLATFORM_PREFIX)
                    ? $hostRowLabel : null;
            }
        }
        return null;
    }

    /**
     * The candidate answer (the final text the checked stop reads on a copy of the plan — a delivering text never bounces
     * on the item it delivers) counts as the owner-visible answer; a pre-call announcement line does not.
     */
    protected static function isOwnerVisibleCandidateAnswerText(?string $candidateAnswerText): bool
    {
        return $candidateAnswerText !== null
            && trim($candidateAnswerText) !== ''
            && !str_starts_with(trim($candidateAnswerText), AIConversationMessage::PRE_CALL_MESSAGE_MARKER);
    }
}
