<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArgumentsItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArgumentsItem as ArgumentsItem;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The checks of ONE keyed `update_task_plan` call against the standing plan, collected in one pass
 * and pure — nothing is read but the plan and the call, nothing is written. Every violation is one
 * {@see TaskPlanUpdateRejection} line; a single one rejects the whole call. A node that fails its shape checks
 * (key type, key form, text) skips the identity checks (known key, level, text) for itself. While the stored tree has a key-integrity violation
 * the call is rejected with R25 alone: no other line could be acted on. A working value of one call; read it through
 * {@see TaskPlan::checkUpdate()}.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlanUpdateCheck extends ValueObject
{
    /** The standing tree; null when no plan stands (every item of the call must be new). */
    protected ?TaskPlanItems $standingTaskPlanItems = null;

    /** The standing tree's addresses, built once. */
    protected ?TaskPlanItemAddresses $standingItemAddresses = null;

    /** The standing plan's top-level counter. */
    protected int $standingHighestItemKeyNumber = 0;

    protected ?TaskPlanUpdateContext $taskPlanUpdateContext = null;

    protected ?TaskPlanUpdateRejections $taskPlanUpdateRejections = null;

    /** @var array<string, int> every conforming key the call sends → how often, in first-occurrence order */
    protected array $sentKeyCounts = [];

    /** @var array<string, true> every conforming key the call sends with `"remove": true` */
    protected array $removedKeys = [];

    /** @var TaskPlanItemAddress[] the stored items of a level the call lists that the call never names */
    protected array $omittedItemAddresses = [];

    /** Check `$updateTaskPlanArguments` against `$standingTaskPlan` (null or empty = no plan stands). */
    public static function checkArgumentsAgainstPlan(
        UpdateTaskPlanArguments $updateTaskPlanArguments,
        ?TaskPlan $standingTaskPlan,
        TaskPlanUpdateContext $taskPlanUpdateContext
    ): TaskPlanUpdateRejections {
        $taskPlanUpdateCheck = new static();
        $taskPlanUpdateCheck->taskPlanUpdateContext = $taskPlanUpdateContext;
        $taskPlanUpdateCheck->taskPlanUpdateRejections = new TaskPlanUpdateRejections();
        if ($standingTaskPlan?->items !== null && $standingTaskPlan->items->count() > 0) {
            $taskPlanUpdateCheck->standingTaskPlanItems = $standingTaskPlan->items;
            $taskPlanUpdateCheck->standingItemAddresses = $standingTaskPlan->items->buildItemAddresses();
            $taskPlanUpdateCheck->standingHighestItemKeyNumber = $standingTaskPlan->getHighestItemKeyNumber();
        }
        $taskPlanUpdateCheck->checkCall($updateTaskPlanArguments, $standingTaskPlan);
        return $taskPlanUpdateCheck->taskPlanUpdateRejections;
    }

    protected function checkCall(UpdateTaskPlanArguments $updateTaskPlanArguments, ?TaskPlan $standingTaskPlan): void
    {
        // the items form a non-empty list
        if (!$updateTaskPlanArguments->itemsIsList || $updateTaskPlanArguments->items === null) {
            $this->reject(TaskPlanUpdateRejection::itemsNotAList());
            return;
        }
        if ($updateTaskPlanArguments->hasNoItems()) {
            $this->reject(TaskPlanUpdateRejection::itemsEmpty());
            return;
        }
        // only valid structure is changed — the model is never asked to name an ambiguous key
        if ($this->standingTaskPlanItems !== null && ($standingTaskPlan?->keyIntegrityViolations?->hasViolations() ?? false)) {
            $this->reject(TaskPlanUpdateRejection::storedKeysInconsistent($standingTaskPlan->keyIntegrityViolations->lines));
            return;
        }
        $this->collectSentKeys($updateTaskPlanArguments->items);
        $topLevelItemCount = $this->checkLevel($updateTaskPlanArguments->items, null, null);
        // no key sent twice
        foreach ($this->sentKeyCounts as $sentKey => $occurrences) {
            if ($occurrences > 1) {
                $this->reject(TaskPlanUpdateRejection::keyRepeated((string)$sentKey, $occurrences));
            }
        }
        // no stored item of a listed level left out
        if ($this->omittedItemAddresses !== []) {
            $omittedItemDescriptions = [];
            $earlierTaskItemKeys = [];
            foreach ($this->omittedItemAddresses as $omittedItemAddress) {
                $omittedItemDescriptions[] = $this->describeOmittedItem($omittedItemAddress);
                if ($this->mayBelongToAnEarlierTask($omittedItemAddress)) {
                    $earlierTaskItemKeys[] = $omittedItemAddress->key;
                }
            }
            $this->reject(TaskPlanUpdateRejection::itemsLeftOut($omittedItemDescriptions, $this->omittedItemAddresses[0]->key, $earlierTaskItemKeys));
        }
        // at least one item stands — a list without any object element is R19 alone, never an emptied plan
        if ($topLevelItemCount === 0 && $updateTaskPlanArguments->items->hasObjectElement()) {
            $this->reject(TaskPlanUpdateRejection::planLeftEmpty());
        }
    }

    /** Every conforming key the call sends, at any depth — the omission check counts an item sent anywhere as named. */
    protected function collectSentKeys(UpdateTaskPlanArgumentsItems $argumentsItems): void
    {
        foreach ($argumentsItems->getElements() as $argumentsItem) {
            // a key sent in the `task` field names its item too: its own line (R10) asks for "key", the omission check does not repeat it
            $sentKey = $argumentsItem->hasConformingKey() ? (string)$argumentsItem->key : ($argumentsItem->carriesAKeyInTheTaskField() ? (string)$argumentsItem->text : null);
            if ($sentKey !== null) {
                $this->sentKeyCounts[$sentKey] = ($this->sentKeyCounts[$sentKey] ?? 0) + 1;
                if ($argumentsItem->isRemovalOfKey()) {
                    $this->removedKeys[(string)$argumentsItem->key] = true;
                }
            }
            if ($argumentsItem->subtasks !== null) {
                $this->collectSentKeys($argumentsItem->subtasks);
            }
        }
    }

    /**
     * Check one list of the call: the top level (both parents null), the subtasks of a stored item
     * (`$storedParentItem`), or the subtasks of a new item (`$newParentArgumentsItem`). Returns how many items the level
     * holds after the call (kept and new).
     */
    protected function checkLevel(
        UpdateTaskPlanArgumentsItems $argumentsItems,
        ?TaskPlanItem $storedParentItem,
        ?ArgumentsItem $newParentArgumentsItem
    ): int {
        $isTopLevel = $storedParentItem === null && $newParentArgumentsItem === null;
        $parentLevel = match (true) {
            $isTopLevel => TaskPlanUpdateRejection::describeStoredLevel(''),
            $storedParentItem !== null => TaskPlanUpdateRejection::describeStoredLevel($this->keyOfStoredItem($storedParentItem)),
            default => TaskPlanUpdateRejection::describeNewItemLevel((string)$newParentArgumentsItem->text),
        };
        // the parent the level's items belong to: "" = the top level, a stored key, null = a new item
        $levelParentKey = $isTopLevel ? '' : ($storedParentItem !== null ? $this->keyOfStoredItem($storedParentItem) : null);
        $storedLevelItems = $isTopLevel ? $this->standingTaskPlanItems : $storedParentItem?->subtasks;
        $resultingItemCount = 0;
        $newItemCount = 0;
        /** @var array<string, int> $newTextPositions normalized text → position of the first new item carrying it */
        $newTextPositions = [];
        /** @var TaskPlanKeptSeededItem[] $keptSeededItems in the order the call sends them */
        $keptSeededItems = [];
        foreach ($argumentsItems->getElements() as $argumentsItem) {
            if (!$argumentsItem->isObject) {
                $this->reject(TaskPlanUpdateRejection::itemNotAnObject($argumentsItem->position, $parentLevel, $argumentsItem->nonObjectDescription));
                continue;
            }
            if (!$argumentsItem->asksForRemoval()) {
                $resultingItemCount++;
            }
            $shapeIsValid = $this->checkShape($argumentsItem, $parentLevel);
            if ($argumentsItem->isNewItem()) {
                $newItemCount++;
                if ($shapeIsValid) {
                    $this->checkNewItemText($argumentsItem, $storedLevelItems, $parentLevel, $newTextPositions);
                }
                if ($argumentsItem->subtasks !== null) {
                    $this->checkLevel($argumentsItem->subtasks, null, $argumentsItem);
                }
                continue;
            }
            if (!$shapeIsValid || !$argumentsItem->hasConformingKey()) {
                continue;
            }
            $sentKey = (string)$argumentsItem->key;
            // the key names a stored item — the R25 pre-check leaves no key that names more than one item
            $storedItemAddress = $this->standingItemAddresses?->getAllByKey($sentKey)[0] ?? null;
            if ($storedItemAddress === null) {
                $this->reject(TaskPlanUpdateRejection::keyUnknown($sentKey));
                continue;
            }
            $storedItem = $storedItemAddress->item;
            $storedParentKey = self::parentKeyOf($storedItemAddress->key);
            // the item stays on its level
            if ($levelParentKey !== $storedParentKey) {
                $this->reject(TaskPlanUpdateRejection::itemMoved($sentKey, $parentLevel, TaskPlanUpdateRejection::describeStoredLevel($storedParentKey), $storedItem->seededByPurpose, $storedParentKey, $this->findSubtaskKeysOfStoredItemWithKey($storedParentKey)));
            } elseif ($storedItem->seededByPurpose && !$argumentsItem->isRemovalOfKey()) {
                $keptSeededItems[] = TaskPlanKeptSeededItem::forKeyAndStoredPosition($sentKey, self::positionOf($storedItemAddress));
            }
            // the item keeps its text
            if ($argumentsItem->hasUsableText()
                && TaskPlanItem::normalizeTextForComparison((string)$argumentsItem->text) !== TaskPlanItem::normalizeTextForComparison($storedItem->text)
            ) {
                $otherItemKey = $this->findOtherItemKeyWithText((string)$argumentsItem->text, $sentKey);
                $this->reject($otherItemKey !== null
                    ? TaskPlanUpdateRejection::textOfAnotherItem($sentKey, $storedItem->text, $otherItemKey)
                    : TaskPlanUpdateRejection::textChanged($sentKey, $storedItem->text, (string)$argumentsItem->text));
            }
            // no seeded item dropped, also inside a dropped subtree
            if ($argumentsItem->isRemovalOfKey()) {
                $this->checkSeededItemsOfDroppedItem($storedItem, $sentKey);
                continue;
            }
            if ($argumentsItem->subtasks !== null) {
                $this->checkLevel($argumentsItem->subtasks, $storedItem, null);
            }
        }
        if ($storedLevelItems !== null && $levelParentKey !== null) {
            $this->collectOmittedItems($storedLevelItems);
        }
        $this->checkSeededOrder($keptSeededItems);
        // a container stays a container
        if ($storedParentItem !== null && ($storedParentItem->subtasks?->count() ?? 0) > 0 && $resultingItemCount === 0 && $argumentsItems->hasObjectElement()) {
            $this->reject(TaskPlanUpdateRejection::containerLeftEmpty($this->keyOfStoredItem($storedParentItem), $storedParentItem->text));
        }
        // no level needs a segment above 999
        if ($newItemCount > 0) {
            $levelCounter = match (true) {
                $isTopLevel => $this->standingHighestItemKeyNumber,
                $storedParentItem !== null => max($storedParentItem->highestSubtaskKeyNumber, $storedParentItem->subtasks?->getHighestKeySegmentOfLevel() ?? 0),
                default => 0,
            };
            if ($levelCounter + $newItemCount > TaskPlanItem::KEY_SEGMENT_MAX) {
                $this->reject(TaskPlanUpdateRejection::keyNumbersExhausted($parentLevel));
            }
        }
        return $resultingItemCount;
    }

    /** The shape checks (and the R4 variants) for one element — every violation named; false when any was found. */
    protected function checkShape(ArgumentsItem $argumentsItem, string $parentLevel): bool
    {
        $shapeIsValid = true;
        if ($argumentsItem->carriesAKeyInTheTaskField()) {
            $this->reject(TaskPlanUpdateRejection::keySentAsTask($argumentsItem->position, $parentLevel, (string)$argumentsItem->text));
            return false;
        }
        if ($argumentsItem->hasKeyField) {
            $keyRejection = $this->checkKeyType($argumentsItem, $parentLevel);
            if ($keyRejection !== null) {
                $this->reject($keyRejection);
                $shapeIsValid = false;
            }
        } elseif (!$argumentsItem->hasTextField) {
            $this->reject(TaskPlanUpdateRejection::neitherKeyNorText($argumentsItem->position, $parentLevel));
            $shapeIsValid = false;
        }
        if ($argumentsItem->hasTextField && $argumentsItem->text === null) {
            $this->reject(TaskPlanUpdateRejection::textNotAString($argumentsItem->position, $parentLevel, $argumentsItem->textRawJson));
            $shapeIsValid = false;
        } elseif ($argumentsItem->hasTextField && $argumentsItem->text === '') {
            $this->reject(TaskPlanUpdateRejection::textEmpty($argumentsItem->position, $parentLevel));
            $shapeIsValid = false;
        }
        if ($argumentsItem->hasSubtasksField && !$argumentsItem->hasUsableSubtasks()) {
            $this->reject(TaskPlanUpdateRejection::subtasksMalformed($this->labelOf($argumentsItem, $parentLevel), $argumentsItem->subtasksRawJson));
            $shapeIsValid = false;
        }
        if ($argumentsItem->hasRemoveField) {
            if (!$argumentsItem->removeIsTrue) {
                $this->reject(TaskPlanUpdateRejection::removeNotTrue($this->labelOf($argumentsItem, $parentLevel), $argumentsItem->removeRawJson));
                $shapeIsValid = false;
            } elseif (!$argumentsItem->hasKeyField) {
                $this->reject(TaskPlanUpdateRejection::removeWithoutKey());
                $shapeIsValid = false;
            } elseif ($argumentsItem->fieldNamesBesidesKeyAndRemove !== []) {
                $this->reject(TaskPlanUpdateRejection::removeWithOtherFields($argumentsItem->key ?? $argumentsItem->keyRawJson, $argumentsItem->fieldNamesBesidesKeyAndRemove));
                $shapeIsValid = false;
            }
        }
        return $shapeIsValid;
    }

    /** The type and form of a sent `key`: R3 for a malformed string, the four R4 variants for every other JSON type; null when it conforms. */
    protected function checkKeyType(ArgumentsItem $argumentsItem, string $parentLevel): ?TaskPlanUpdateRejection
    {
        return match ($argumentsItem->keyJsonType) {
            ArgumentsItem::JSON_TYPE_STRING => $argumentsItem->hasConformingKey() ? null : $this->buildMalformedKeyRejection($argumentsItem),
            ArgumentsItem::JSON_TYPE_NUMBER => TaskPlanUpdateRejection::keySentAsNumber($argumentsItem->keyRawJson),
            ArgumentsItem::JSON_TYPE_NULL => TaskPlanUpdateRejection::keySentAsNull($argumentsItem->position, $parentLevel),
            ArgumentsItem::JSON_TYPE_BOOLEAN => TaskPlanUpdateRejection::keySentAsBoolean($argumentsItem->keyRawJson, $argumentsItem->position, $parentLevel),
            default => TaskPlanUpdateRejection::keySentAsListOrObject($argumentsItem->keyRawJson, $argumentsItem->position, $parentLevel, $argumentsItem->keyJsonType === ArgumentsItem::JSON_TYPE_LIST),
        };
    }

    /** R3 with the normalization's hint — never applied. */
    protected function buildMalformedKeyRejection(ArgumentsItem $argumentsItem): TaskPlanUpdateRejection
    {
        $candidateKey = TaskPlanItem::buildKeyCandidate((string)$argumentsItem->key);
        $candidateNamesAnItem = $candidateKey !== null && ($this->standingTaskPlanItems?->countItemsWithKey($candidateKey) ?? 0) === 1;
        return TaskPlanUpdateRejection::keyMalformed($argumentsItem->keyRawJson, $candidateKey, $candidateNamesAnItem);
    }

    /**
     * A new item's text is unique among its siblings — the stored items the level keeps (a sibling the call drops
     * does not count) and the new items before it.
     *
     * @param array<string, int> $newTextPositions
     */
    protected function checkNewItemText(ArgumentsItem $argumentsItem, ?TaskPlanItems $storedLevelItems, string $parentLevel, array &$newTextPositions): void
    {
        $newText = (string)$argumentsItem->text;
        $normalizedText = TaskPlanItem::normalizeTextForComparison($newText);
        foreach ($storedLevelItems?->getElements() ?? [] as $storedSibling) {
            $storedSiblingKey = $this->keyOfStoredItem($storedSibling);
            if (!isset($this->removedKeys[$storedSiblingKey]) && TaskPlanItem::normalizeTextForComparison($storedSibling->text) === $normalizedText) {
                $this->reject(TaskPlanUpdateRejection::newTextRepeatsAnItem($newText, $parentLevel, $storedSiblingKey, $storedSibling->text));
                return;
            }
        }
        if (isset($newTextPositions[$normalizedText])) {
            $this->reject(TaskPlanUpdateRejection::newTextsRepeatEachOther($newTextPositions[$normalizedText], $argumentsItem->position, $parentLevel, $newText));
            return;
        }
        $newTextPositions[$normalizedText] = $argumentsItem->position;
    }

    /** The seeded-item check for a dropped stored item: R14 for the item itself when seeded, else for the first seeded item of its subtree. */
    protected function checkSeededItemsOfDroppedItem(TaskPlanItem $droppedItem, string $droppedKey): void
    {
        if ($droppedItem->seededByPurpose) {
            $this->reject(TaskPlanUpdateRejection::seededItemDropped($droppedKey));
            return;
        }
        foreach ($droppedItem->subtasks?->buildItemAddresses()->getElements() ?? [] as $descendantAddress) {
            if ($descendantAddress->item->seededByPurpose) {
                $this->reject(TaskPlanUpdateRejection::seededItemDroppedWithItsParent($this->keyOfStoredItem($descendantAddress->item), $droppedKey));
                return;
            }
        }
    }

    /**
     * The seeded items a level keeps stand in their stored order — the first pair the call swaps is named.
     *
     * @param TaskPlanKeptSeededItem[] $keptSeededItems in the order the call sends them
     * @return void
     */
    protected function checkSeededOrder(array $keptSeededItems): void
    {
        for ($index = 1; $index < count($keptSeededItems); $index++) {
            if ($keptSeededItems[$index]->isStoredBefore($keptSeededItems[$index - 1])) {
                $this->reject(TaskPlanUpdateRejection::seededOrderChanged($keptSeededItems[$index]->key, $keptSeededItems[$index - 1]->key));
                return;
            }
        }
    }

    /** The omission check for one level the call lists: every stored item there that the call never names, anywhere. */
    protected function collectOmittedItems(TaskPlanItems $storedLevelItems): void
    {
        foreach ($storedLevelItems->getElements() as $storedItem) {
            if (!isset($this->sentKeyCounts[$this->keyOfStoredItem($storedItem)])) {
                $storedItemAddress = $this->standingItemAddresses?->getByItem($storedItem);
                if ($storedItemAddress !== null) {
                    $this->omittedItemAddresses[] = $storedItemAddress;
                }
            }
        }
    }

    /**
     * An omitted item the R8 earlier-task hint speaks of: a top-level item the model may drop — not seeded, not
     * added by an earlier call of this response.
     */
    protected function mayBelongToAnEarlierTask(TaskPlanItemAddress $omittedItemAddress): bool
    {
        return !str_contains($omittedItemAddress->key, '.')
            && !$omittedItemAddress->item->seededByPurpose
            && !($this->taskPlanUpdateContext?->wasKeyAddedEarlierInThisResponse($omittedItemAddress->key) ?? false);
    }

    /** `<key> '<text>'` of an omitted item, with what it is: added by an earlier call of this response, or seeded. */
    protected function describeOmittedItem(TaskPlanItemAddress $omittedItemAddress): string
    {
        $description = $omittedItemAddress->key . " '" . TaskPlanUpdateRejection::quoteText($omittedItemAddress->item->text) . "'";
        if ($this->taskPlanUpdateContext?->wasKeyAddedEarlierInThisResponse($omittedItemAddress->key) ?? false) {
            $description .= ' (added by an earlier call in this response)';
        }
        if ($omittedItemAddress->item->seededByPurpose) {
            $description .= ' (seeded — keep it; close it with set_task_status not_applicable and a reason if it does not apply)';
        }
        return $description;
    }

    /** The key of another item whose text equals `$sentText` (the R9 swap variant), when exactly one carries it. */
    protected function findOtherItemKeyWithText(string $sentText, string $sentKey): ?string
    {
        $normalizedSentText = TaskPlanItem::normalizeTextForComparison($sentText);
        $matchingKeys = [];
        foreach ($this->standingItemAddresses?->getElements() ?? [] as $standingItemAddress) {
            if ($standingItemAddress->key !== $sentKey && TaskPlanItem::normalizeTextForComparison($standingItemAddress->item->text) === $normalizedSentText) {
                $matchingKeys[] = $standingItemAddress->key;
            }
        }
        return count($matchingKeys) === 1 ? $matchingKeys[0] : null;
    }

    /** How a line names an element: `"<key>"`, `the new item '<text>'`, or `item <position> <parent>`. */
    protected function labelOf(ArgumentsItem $argumentsItem, string $parentLevel): string
    {
        if ($argumentsItem->key !== null) {
            return '"' . $argumentsItem->key . '"';
        }
        if ($argumentsItem->hasUsableText()) {
            return "the new item '" . TaskPlanUpdateRejection::quoteText((string)$argumentsItem->text) . "'";
        }
        return 'item ' . $argumentsItem->position . ' ' . $parentLevel;
    }

    /** The keys of the stored subtasks of the item with `$parentKey`, in order; [] for the top level or an unknown key. */
    protected function findSubtaskKeysOfStoredItemWithKey(string $parentKey): array
    {
        if ($parentKey === '') {
            return [];
        }
        $storedParentItem = ($this->standingItemAddresses?->getAllByKey($parentKey)[0] ?? null)?->item;
        $subtaskKeys = [];
        foreach ($storedParentItem?->subtasks?->getElements() ?? [] as $storedSubtask) {
            $subtaskKeys[] = $this->keyOfStoredItem($storedSubtask);
        }
        return $subtaskKeys;
    }

    /** A stored item's key — the address's key for an item built in memory without one (its number path). */
    protected function keyOfStoredItem(TaskPlanItem $storedItem): string
    {
        return $storedItem->key ?? ($this->standingItemAddresses?->getByItem($storedItem)->key ?? '');
    }

    /** The parent's key a key encodes: "7.2.11" → "7.2", "7" → "" (the top level). */
    protected static function parentKeyOf(string $key): string
    {
        $lastDotPosition = strrpos($key, '.');
        return $lastDotPosition === false ? '' : substr($key, 0, $lastDotPosition);
    }

    /** The 0-based position of a stored item within its level. */
    protected static function positionOf(TaskPlanItemAddress $taskPlanItemAddress): int
    {
        $numberPathSegments = explode('.', $taskPlanItemAddress->numberPath);
        return (int)end($numberPathSegments) - 1;
    }

    protected function reject(TaskPlanUpdateRejection $taskPlanUpdateRejection): void
    {
        $this->taskPlanUpdateRejections?->addRejection($taskPlanUpdateRejection);
    }
}
