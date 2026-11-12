<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArgumentsUpdate;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArgumentsUpdates;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArgumentsItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArgumentsItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The application of ONE `update_task_plan` call the check accepted, onto the plan it is
 * given. Every level the call lists is rebuilt from the STORED item objects in the order sent — so an item keeps
 * everything it holds: its 30 seeded declarations, its platform records, its status and note, by construction — a
 * new item is created and minted (pre-order: a new parent before its new children; a number is never reused), a dropped
 * item leaves with its subtree. Then the statuses the call sent apply under the field-presence rules: an omitted field
 * changes nothing; the stored status sent again is no transition (a note refresh on a terminal item, flags on a blocked
 * one); a different status is a TRANSITION through the tick application of `set_task_status` — its
 * gates, its cascade cap and its feedback lines, one gate set for both plan tools; a status a gate refuses leaves the stored one, and no status ever rejects the call. A working
 * value of one call.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateApplication extends ValueObject
{
    /** The plan being changed — the caller's working copy. */
    protected ?TaskPlan $taskPlan = null;

    protected ?TaskPlanUpdateContext $taskPlanUpdateContext = null;

    protected ?TaskPlanUpdateOutcome $taskPlanUpdateOutcome = null;

    /** @var TaskPlanItem[] the plan's items before the call, by key */
    protected array $itemsByKey = [];

    /** @var array<int, true> the added items, by object id */
    protected array $addedItemObjectIds = [];

    /** @var TaskPlanUpdateItemStatusRequest[] the elements that sent a status, a note or a flag, in the order sent (pre-order) */
    protected array $statusRequests = [];

    /**
     * Apply the accepted `$updateTaskPlanArguments` onto `$taskPlan` in place (an empty or item-less plan is filled from
     * scratch). Returns the outcome without its plan; the caller decides whether the call changed anything.
     */
    public static function applyToPlan(TaskPlan $taskPlan, UpdateTaskPlanArguments $updateTaskPlanArguments, TaskPlanUpdateContext $taskPlanUpdateContext): TaskPlanUpdateOutcome
    {
        $taskPlanUpdateApplication = new static();
        $taskPlanUpdateApplication->taskPlan = $taskPlan;
        $taskPlanUpdateApplication->taskPlanUpdateContext = $taskPlanUpdateContext;
        $taskPlanUpdateApplication->taskPlanUpdateOutcome = new TaskPlanUpdateOutcome();
        foreach ($taskPlan->items?->buildItemAddresses()->getElements() ?? [] as $taskPlanItemAddress) {
            $taskPlanUpdateApplication->itemsByKey[$taskPlanItemAddress->key] = $taskPlanItemAddress->item;
        }
        // the top-level counter as it persists, before anything is minted on it
        $taskPlan->highestItemKeyNumber = $taskPlan->getHighestItemKeyNumber();
        if ($updateTaskPlanArguments->items !== null) {
            $taskPlan->items = $taskPlanUpdateApplication->buildLevel($updateTaskPlanArguments->items, null);
        }
        $taskPlanUpdateApplication->applyStatusRequests();
        $taskPlanUpdateApplication->describeAddedItems();
        return $taskPlanUpdateApplication->taskPlanUpdateOutcome;
    }

    /** Rebuild one level the call lists below `$parentItem` (null = the top level), recursing into the levels it lists. */
    protected function buildLevel(UpdateTaskPlanArgumentsItems $argumentsItems, ?TaskPlanItem $parentItem): TaskPlanItems
    {
        $taskPlanItems = TaskPlanItems::newInstance();
        foreach ($argumentsItems->getElements() as $argumentsItem) {
            if ($argumentsItem->isRemovalOfKey()) {
                $droppedItem = $this->itemsByKey[(string)$argumentsItem->key] ?? null;
                if ($droppedItem !== null) {
                    $this->taskPlanUpdateOutcome->droppedItemDescriptions[] = self::describeDroppedItem($droppedItem);
                }
                continue;
            }
            if ($argumentsItem->isNewItem()) {
                $taskPlanItem = TaskPlanItem::newInstance();
                $taskPlanItem->text = (string)$argumentsItem->text;
                $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
                $taskPlanItem->seededByPurpose = false;
                // minted before its children: they start at `.1` below it (the check ruled out segment 1000, R24)
                $taskPlanItem->key = $parentItem !== null ? $parentItem->mintSubtaskKey() : $this->taskPlan->mintTopLevelKey();
                $this->addedItemObjectIds[spl_object_id($taskPlanItem)] = true;
            } else {
                $taskPlanItem = $this->itemsByKey[(string)$argumentsItem->key];
            }
            if ($argumentsItem->hasStatusField || $argumentsItem->hasStatusNoteOrFlag()) {
                $this->statusRequests[] = TaskPlanUpdateItemStatusRequest::forItem($taskPlanItem, $argumentsItem);
            }
            if ($argumentsItem->subtasks !== null) {
                $taskPlanItem->subtasks = $this->buildLevel($argumentsItem->subtasks, $taskPlanItem);
            }
            // ObjectSet::add() is BY-REFERENCE — pass the loop variable, never a method return
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    /**
     * The statuses the call sent: an element without a status changes nothing but the same-status
     * fields; the stored status sent again is no transition (no gate is asked, {@see applySameStatusFields()}); every
     * other status — a transition, or a status the tick names as not recognized — passes the tick application of
     * `set_task_status` in the order sent ({@see applyStatusTransitionsThroughTheTick()}), with its gates, its cascade cap
     * and its feedback lines. A status never rejects the call.
     */
    protected function applyStatusRequests(): void
    {
        $tickUpdates = new SetTaskStatusArgumentsUpdates();
        foreach ($this->statusRequests as $statusRequest) {
            $taskPlanItem = $statusRequest->taskPlanItem;
            $argumentsItem = $statusRequest->argumentsItem;
            if (!$argumentsItem->hasStatusField) {
                $this->applySameStatusFields($taskPlanItem, $taskPlanItem->status, $argumentsItem, false);
                continue;
            }
            $status = UpdateTaskPlanTool::STATUS_ALIASES[$argumentsItem->rawStatus] ?? null;
            if ($status !== null && $status === $taskPlanItem->status) {
                if ($status !== $argumentsItem->rawStatus) {
                    $this->addFeedback("Item \"{$taskPlanItem->key}\": status '{$argumentsItem->rawStatus}' normalized to '{$status}'.");
                }
                $this->applySameStatusFields($taskPlanItem, $status, $argumentsItem, true);
                continue;
            }
            // ObjectSet::add() is BY-REFERENCE — pass a variable, never a method return
            $tickUpdate = SetTaskStatusArgumentsUpdate::forStatusOfResendElement(
                $argumentsItem,
                (string)$taskPlanItem->key,
                $tickUpdates->count()
            );
            $tickUpdates->add($tickUpdate);
        }
        if ($tickUpdates->count() > 0) {
            $this->applyStatusTransitionsThroughTheTick(SetTaskStatusArguments::fromUpdates($tickUpdates));
        }
    }

    /**
     * The call's status transitions through the tick application (one gate set for both
     * plan tools): {@see TaskPlan::applyStatusUpdatesWithoutTheTurnStamps()} over the plan as the call rebuilt it, judged by
     * the context's evidence, criteria and inputs. The status of an item the call adds — every item of a plan creation —
     * is a declaration of finished work; every gate applies.
     * Its feedback lines join the call's feedback as the tick words them; a
     * status the tick did not apply as sent (a gate NOTED it, the cascade cap, a done item stays done, not recognized)
     * marks the call's gate refusal; an item that stood before the call and is resolved stamps the plan's resolution —
     * an item the call added never does (a created-terminal item is a declaration, not progress).
     */
    protected function applyStatusTransitionsThroughTheTick(SetTaskStatusArguments $setTaskStatusArguments): void
    {
        $taskPlanUpdateContext = $this->taskPlanUpdateContext;
        // the live call reads its tick judgement only now — a call without a transition never loads it
        $taskPlanUpdateContext->readStatusTickJudgementOver($this->taskPlan->items);
        $agentTaskPlanTickOutcome = $this->taskPlan->applyStatusUpdatesWithoutTheTurnStamps(
            $setTaskStatusArguments,
            $taskPlanUpdateContext->taskPlanEvidence,
            $taskPlanUpdateContext->taskPlanDoneCriteria,
            $taskPlanUpdateContext->buildDoneCriterionInputsOver($this->taskPlan->items)
        );
        foreach ($agentTaskPlanTickOutcome->feedback as $feedbackLine) {
            $this->addFeedback($feedbackLine);
        }
        if ($agentTaskPlanTickOutcome->updatedCount < $setTaskStatusArguments->countUpdates()) {
            $this->taskPlanUpdateOutcome->hasGateRefusedStatus = true;
        }
        foreach ($agentTaskPlanTickOutcome->resolvingTaskAddresses as $resolvingTaskAddress) {
            if (isset($this->itemsByKey[$resolvingTaskAddress])) {
                $this->taskPlan->markResolvedInTurn($taskPlanUpdateContext->currentTurnInitiatorMessageIndex);
                return;
            }
        }
    }

    /**
     * No transition (the stored status sent again, or a note / flag without a status): a non-empty note refreshes the
     * note of a terminal item unless the platform resolved it at seed; the flags are taken only on a blocked item; on an
     * open item a note or a flag without a status is named as changing nothing.
     */
    protected function applySameStatusFields(TaskPlanItem $taskPlanItem, string $status, UpdateTaskPlanArgumentsItem $argumentsItem, bool $statusWasSent): void
    {
        if ($status === TaskPlanItem::STATUS_OPEN) {
            if (!$statusWasSent && $argumentsItem->hasStatusNoteOrFlag()) {
                $this->addFeedback('Item "' . $taskPlanItem->key . '": statusNote/flags without a status change nothing on an open item — use set_task_status.');
            }
            return;
        }
        if ($argumentsItem->statusNote !== '' && !$taskPlanItem->preResolvedAtSeed) {
            $taskPlanItem->statusNote = mb_substr($argumentsItem->statusNote, 0, TaskPlanItem::STATUS_NOTE_MAX_LENGTH);
        }
        if ($status === TaskPlanItem::STATUS_BLOCKED) {
            if ($argumentsItem->requiresUserAction !== null) {
                $taskPlanItem->requiresUserAction = $argumentsItem->requiresUserAction;
            }
            if ($argumentsItem->retryable !== null) {
                $taskPlanItem->retryable = $argumentsItem->retryable;
            }
        }
    }

    /**
     * The `Added:` entries in plan order with each item's place — `after <previous key>`, `first under <parent key>` (or
     * `first at the top level`), `at the end` for the last top-level item — and whether an added key stands before a
     * sibling with a lower number.
     */
    protected function describeAddedItems(): void
    {
        if ($this->addedItemObjectIds === [] || $this->taskPlan->items === null) {
            return;
        }
        foreach ($this->taskPlan->items->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if (!isset($this->addedItemObjectIds[spl_object_id($taskPlanItem)])) {
                continue;
            }
            $parentItem = $taskPlanItemAddress->ancestors !== [] ? $taskPlanItemAddress->ancestors[count($taskPlanItemAddress->ancestors) - 1] : null;
            $siblingItems = array_values(($parentItem !== null ? $parentItem->subtasks : $this->taskPlan->items)?->getElements() ?? []);
            $position = array_search($taskPlanItem, $siblingItems, true);
            $previousSibling = is_int($position) && $position > 0 ? $siblingItems[$position - 1] : null;
            $place = match (true) {
                $previousSibling === null => $parentItem !== null ? 'first under ' . $parentItem->key : 'first at the top level',
                $parentItem === null && $position === count($siblingItems) - 1 => 'at the end',
                default => 'after ' . $previousSibling->key,
            };
            $this->taskPlanUpdateOutcome->addedItemDescriptions[] = $taskPlanItem->key . " '"
                . TaskPlanUpdateRejection::quoteText($taskPlanItem->text) . "' (" . $place . ')';
            $addedKeySegment = (int)TaskPlanItem::getLastKeySegment((string)$taskPlanItem->key);
            foreach (array_slice($siblingItems, is_int($position) ? $position + 1 : count($siblingItems)) as $laterSibling) {
                if ((int)TaskPlanItem::getLastKeySegment((string)$laterSibling->key) < $addedKeySegment) {
                    $this->taskPlanUpdateOutcome->addedKeyStandsOutOfOrder = true;
                }
            }
        }
    }

    /** `<key> '<text>'[ with its subtasks 3.1, 3.2][ — its state leaves with it]` — the state: a status other than open or a note anywhere in the dropped subtree. */
    protected static function describeDroppedItem(TaskPlanItem $droppedItem): string
    {
        $holdsState = self::itemHoldsState($droppedItem);
        $descendantKeys = [];
        foreach ($droppedItem->subtasks?->buildItemAddresses()->getElements() ?? [] as $descendantAddress) {
            $descendantKeys[] = $descendantAddress->item->key ?? $descendantAddress->key;
            $holdsState = $holdsState || self::itemHoldsState($descendantAddress->item);
        }
        return $droppedItem->key . " '" . TaskPlanUpdateRejection::quoteText($droppedItem->text) . "'"
            . ($descendantKeys !== [] ? ' with its subtasks ' . implode(', ', $descendantKeys) : '')
            . ($holdsState ? ' — its state leaves with it' : '');
    }

    protected static function itemHoldsState(TaskPlanItem $taskPlanItem): bool
    {
        return $taskPlanItem->status !== TaskPlanItem::STATUS_OPEN
            || trim((string)($taskPlanItem->statusNote ?? '')) !== '';
    }

    protected function addFeedback(string $feedbackLine): void
    {
        $this->taskPlanUpdateOutcome->feedback[] = $feedbackLine;
    }
}
