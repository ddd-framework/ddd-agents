<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressStepMemberState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ORDERED collection of {@see TaskPlanItem} — used at BOTH tree levels of a {@see TaskPlan}: as the plan's
 * top-level item list AND as every item's {@see TaskPlanItem::$subtasks} (the tree edge), so one uniform set type
 * carries the whole plan tree at every depth. Identity is {@see TaskPlanItem::uniqueKey()} — the item KEY;
 * the seed and the `update_task_plan` parse still collapse a duplicate TEXT within one level, explicitly
 * ({@see containsItemWithText()}). Owns the TREE-AWARE folds the checked
 * stop and the run report read — openness, per-status counts, open-item paths — and the
 * repository round-trip halves ({@see toRepositoryArray()} / {@see fromRepositoryArray()}). Every address a fold
 * returns or takes is the item KEY — also where a method name still says "number path", a name from before
 * keys existed; render order and `← next` follow the stored order, never the key. The persisted shape is
 * plain NESTED JSON ARRAYS on purpose (RFC 7396: arrays replace wholesale under the column's JSON_MERGE_PATCH;
 * keyed objects would merge recursively and resurrect deleted nodes).
 *
 * @property TaskPlanItem[] $elements
 * @method TaskPlanItem[] getElements()
 * @method TaskPlanItem|null first()
 * @method TaskPlanItem getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class TaskPlanItems extends ObjectSet
{
    /**
     * Builds the plan tree from a purpose config's HIERARCHICAL seed definition (the single seed source —
     * `seedTaskPlanItems` in the purpose's `.md` frontmatter). Each definition item is either a plain STRING
     * (a criterion-less leaf) or a MAP: `text` (required; empty → skipped), `doneCriterion` (optional machine
     * criterion key for the seed-time pre-tick) and `subtasks` (optional nested list of the same shape). Pure
     * assembly — no repo, no config I/O (the caller passes the parsed definition). Mints the keys:
     * the positions in pre-order (`1`, `1.1`, `1.2`, `2` …), each item's subtask counter = its child count, every
     * item `seededByPurpose`; a duplicate text within one level is skipped, as the set always collapsed it.
     *
     * @param array $seedItemDefinitions
     */
    public static function fromPurposeSeedDefinition(array $seedItemDefinitions, ?string $inheritedCustomerProgressStepKey = null, string $parentKey = ''): static
    {
        $taskPlanItems = static::newInstance();
        foreach ($seedItemDefinitions as $seedItemDefinition) {
            if (is_string($seedItemDefinition)) {
                $seedItemDefinition = ['text' => $seedItemDefinition];
            }
            if (!is_array($seedItemDefinition)) {
                continue;
            }
            $itemText = trim((string)($seedItemDefinition['text'] ?? ''));
            if ($itemText === '' || $taskPlanItems->containsItemWithText($itemText)) {
                continue;
            }
            $taskPlanItem = TaskPlanItem::newInstance();
            $taskPlanItem->text = $itemText;
            $taskPlanItem->key = TaskPlanItem::composeChildKey($parentKey, $taskPlanItems->count() + 1);
            $taskPlanItem->seededByPurpose = true;
            $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
            TaskPlanItemDeclaration::readSeedDefinition($seedItemDefinition, $taskPlanItem, $inheritedCustomerProgressStepKey);
            $subtaskDefinitions = $seedItemDefinition['subtasks'] ?? null;
            if (is_array($subtaskDefinitions) && $subtaskDefinitions !== []) {
                $taskPlanItem->subtasks = self::fromPurposeSeedDefinition($subtaskDefinitions, $taskPlanItem->customerProgressStepKey, $taskPlanItem->key);
                $taskPlanItem->highestSubtaskKeyNumber = $taskPlanItem->subtasks->count();
            }
            // ObjectSet::add() is BY-REFERENCE — pass the loop variable, never a method return.
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    /**
     * Every node of this tree in pre-order with its {@see TaskPlanItemAddress} (key, number path, text path, depth, what it
     * inherits, which ancestor prunes it) — the one walk the read questions below filter. Built
     * from the tree as it is now; a mutation of the tree invalidates it.
     */
    public function buildItemAddresses(): TaskPlanItemAddresses
    {
        $taskPlanItemAddresses = new TaskPlanItemAddresses();
        $this->appendItemAddresses($taskPlanItemAddresses, null);
        return $taskPlanItemAddresses;
    }

    protected function appendItemAddresses(TaskPlanItemAddresses $taskPlanItemAddresses, ?TaskPlanItemAddress $parentAddress): void
    {
        foreach (array_values($this->getElements()) as $position => $taskPlanItem) {
            $taskPlanItemAddress = TaskPlanItemAddress::ofChild($taskPlanItem, $position, $parentAddress);
            // ObjectSet::add() is BY-REFERENCE — a copy keeps $taskPlanItemAddress typed
            $addedAddress = $taskPlanItemAddress;
            $taskPlanItemAddresses->add($addedAddress);
            $taskPlanItem->subtasks?->appendItemAddresses($taskPlanItemAddresses, $taskPlanItemAddress);
        }
    }

    /**
     * PRE-RESOLVE every seeded leaf the purpose's done criteria can already decide from live data
     * (through the criteria registry and its inputs): for each OPEN leaf carrying a
     * {@see TaskPlanItem::$doneCriterion}, ask the registry for the verdict of that key over the inputs — DONE (the data
     * exists) or NOT_APPLICABLE (the space's kind rules it out), each with its composed statusNote — or null to leave
     * the leaf open. Each key is evaluated LAZILY, when the walk reaches its leaf, over the inputs' LIVE plan as mutated
     * so far. Recurses into subtasks first. Pure tree logic: what a criterion key MEANS lives in the criterion the
     * purpose registers, so this is unit testable with a fixed registry. OPEN leaves are touched, and the record re-reads a
     * leaf the MODEL blocked or set n/a and a PLATFORM park or n/a ({@see TaskPlanItemPlatformCloser::applyPreResolution()});
     * a DONE leaf is never un-ticked, a criterion-less (agent-decided) item never touched. Returns
     * the number of leaves resolved (the caller's signal to stamp + persist).
     *
     * @param TaskPlanDoneCriteria $taskPlanDoneCriteria The purpose's criteria; a key without a class answers null
     *        (the leaf stays open).
     * @param TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs What the criteria read, built once per walk.
     * @param AIConversationMessages|null $conversationMessages The conversation so far (the mid-run re-sweep): the
     *        newest index stamps the platform's provenance on a resolved leaf.
     */
    public function applyPreResolutions(
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs,
        ?AIConversationMessages $conversationMessages = null,
        ?array $onlyDoneCriterionKeys = null
    ): int {
        $resolvedLeafCount = 0;
        foreach ($this->getElements() as $taskPlanItem) {
            $resolvedLeafCount += $taskPlanItem->subtasks?->applyPreResolutions($taskPlanDoneCriteria, $taskPlanDoneCriterionInputs, $conversationMessages, $onlyDoneCriterionKeys) ?? 0;
            if (TaskPlanItemPlatformCloser::forItem($taskPlanItem)->applyPreResolution($taskPlanDoneCriteria, $taskPlanDoneCriterionInputs, $conversationMessages, $onlyDoneCriterionKeys)) {
                $resolvedLeafCount++;
            }
        }
        return $resolvedLeafCount;
    }

    /**
     * Tally the customer-progress-step MEMBERSHIP of this level and all subtrees into `$memberStatesByStepKey`
     * (key → {@see CustomerProgressStepMemberState}) — the stepper's aggregation input
     * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Progress\CustomerProgressSteps::fromPurposeDeclarationAndPlanItems()}).
     * Openness is the tree-aware {@see TaskPlanItem::isOpen()} (blocked / not_applicable count as terminal — the
     * step completes instead of hanging on a pruned branch). Keys are materialized down the tree at seed-parse
     * time, so parent and subtask both tally — consistent, since a done parent with open subtasks IS open.
     * Items without a key (agent-added mid-run) are skipped. Pure fold over the tree.
     *
     * @param CustomerProgressStepMemberState[] $memberStatesByStepKey keyed by customer progress step key
     * @return void
     */
    public function collectCustomerProgressStepMemberStates(array &$memberStatesByStepKey): void
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->customerProgressStepKey === null) {
                continue;
            }
            $memberStatesByStepKey[$taskPlanItem->customerProgressStepKey] ??= CustomerProgressStepMemberState::forNoMembers();
            // Terminal by the DIALOG's doing (agent tick, owner deferral, platform deferral at the end) counts as
            // progress — a leaf the platform pre-resolved from live state before turn 1 does NOT.
            $memberStatesByStepKey[$taskPlanItem->customerProgressStepKey]->addMember($taskPlanItem->isOpen(), $taskPlanItem->preResolvedAtSeed);
        }
    }

    /**
     * Whether any item of this level — or of any subtree below it — is OPEN ({@see TaskPlanItem::isOpen()}). Stays a walk
     * over this level: {@see TaskPlanItem::isOpen()} of a container calls it, and the address walk reads isOpen() — on the
     * address walk it would rebuild every subtree for every container.
     */
    public function hasOpenItems(): bool
    {
        foreach ($this->getElements() as $taskPlanItem) {
            if ($taskPlanItem->isOpen()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any node of the tree was seeded by the purpose ({@see TaskPlanItem::$seededByPurpose}) — a purpose's
     * plan; false for a plan the model made.
     */
    public function hasItemSeededByPurpose(): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->seededByPurpose) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any open item of this level or any subtree is OWED by the agent — an awaiting non-blocking offer
     * ({@see TaskPlanItem::isAwaitingOwnerOffer()}) is open but not owed. The tail's `← next`, the bounce's
     * open-work list and the supervisor read this; completion and the run-end deferral read {@see hasOpenItems()}.
     */
    public function hasOwedOpenItems(): bool
    {
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->isOwedOpen()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first leaf (depth-first) whose `doneCriterion` is `$doneCriterionKey`, or null — the mandate gate reads a
     * purpose's offer leaf through it.
     */
    public function getLeafByDoneCriterion(string $doneCriterionKey): ?TaskPlanItem
    {
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->doneCriterion === $doneCriterionKey) {
                return $taskPlanItemAddress->item;
            }
        }
        return null;
    }

    /**
     * Rename every {@see TaskPlanItem::$doneCriterion} that carries a key its criterion decided under before a rename
     * (`$currentKeysByLegacyKey`, {@see \DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria::getCurrentKeysByLegacyKey()})
     * to the current key — a plan stored before a purpose renamed one of its criterion keys keeps its
     * gate. Returns how many items were renamed (the caller's cue to persist).
     *
     * @param array<string, string> $currentKeysByLegacyKey
     */
    public function renameLegacyDoneCriterionKeys(array $currentKeysByLegacyKey): int
    {
        $renamedCount = 0;
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->doneCriterion !== null && isset($currentKeysByLegacyKey[$taskPlanItem->doneCriterion])) {
                $taskPlanItem->doneCriterion = $currentKeysByLegacyKey[$taskPlanItem->doneCriterion];
                $renamedCount++;
            }
        }
        return $renamedCount;
    }

    /**
     * Drop every {@see TaskPlanItem::$doneCriterion} whose key the purpose registers no class for (`$registeredKeys`): a
     * stored plan can carry a key that left the purpose (a criterion the purpose removed since the plan was
     * stored) — no record can close such an item, so it is a plain model item and its plan line must not say it
     * closes itself. Returns how many items lost their key (the caller's cue to persist).
     *
     * @param string[] $registeredKeys
     */
    public function dropDoneCriteriaWithoutClass(array $registeredKeys): int
    {
        $droppedCount = 0;
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->doneCriterion !== null && !in_array($taskPlanItem->doneCriterion, $registeredKeys, true)) {
                $taskPlanItem->doneCriterion = null;
                $droppedCount++;
            }
        }
        return $droppedCount;
    }

    /**
     * Stamp every open leaf whose owner-answered widget ({@see TaskPlanItem::$doneRequiresWidgetAnswered},
     * {@see TaskPlanItem::$doneRequiresWidget}) is rendered in the conversation with the index of that render — from then
     * on the item is awaiting the owner.
     * Idempotent; returns how many items were stamped now. Pure fold over both trees.
     */
    public function stampRenderedNonBlockingOffers(AIConversationMessages $conversationMessages): int
    {
        $stampedCount = 0;
        foreach ($this->getElements() as $taskPlanItem) {
            if ($taskPlanItem->subtasks !== null && $taskPlanItem->subtasks->count() > 0) {
                $stampedCount += $taskPlanItem->subtasks->stampRenderedNonBlockingOffers($conversationMessages);
                continue;
            }
            if (!$taskPlanItem->isOfferWithoutRenderStamp()) {
                continue;
            }
            $renderedAtIndex = $conversationMessages->buildShownWidgets()->findNewestOfType($taskPlanItem->doneRequiresWidget)?->hostIndexInConversation;
            if ($renderedAtIndex !== null) {
                $taskPlanItem->markOfferRenderedAt($renderedAtIndex);
                $stampedCount++;
            }
        }
        return $stampedCount;
    }

    /**
     * The OPEN items' texts as TREE PATHS ("parent > child") across this level and all subtrees — the unambiguous
     * naming the bounce signal and the run report use ("Prepare bookings > Confirm slot" instead of an
     * ambiguous leaf name). An open item is listed itself only when it has NO open subtasks (the open descendants
     * name the remaining work more precisely than their parent).
     *
     * @return string[]
     */
    public function getOpenItemPathTexts(): array
    {
        $openItemPathTexts = [];
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            // an awaiting non-blocking offer is open but not OWED — never listed as remaining work
            if ($taskPlanItemAddress->item->isOwedOpen() && !$taskPlanItemAddress->hasNotOwedAncestor) {
                $openItemPathTexts[] = $taskPlanItemAddress->textPath;
            }
        }
        return $openItemPathTexts;
    }

    /**
     * The DISTINCT tool names any item of this level or any subtree declares as its done-evidence gate
     * ({@see TaskPlanItem::$doneRequiresToolCall}) — the input for the entitlement-waiver check (a gate tool the
     * account is structurally locked out of must not wedge its item). Pure fold over the tree.
     *
     * @return string[]
     */
    public function getDoneGateToolNames(): array
    {
        $doneGateToolNames = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            foreach ($taskPlanItemAddress->item->getDoneRequiresToolCallAlternatives() as $doneGateToolName) {
                $doneGateToolNames[$doneGateToolName] = true;
            }
        }
        return array_keys($doneGateToolNames);
    }

    /**
     * The KEYS of the leaves whose `done` tick the model owes after a call of the tool — and whose tick
     * will be ACCEPTED: the leaf names the tool as its done-evidence, a completed call that passes the leaf's
     * argument filters exists, and no further evidence is asked (a widget, a delivered answer, a connected channel — the
     * closer's own exclusions, so the "GATE SATISFIED … tick it done NOW" note never names a leaf whose channel is not
     * connected). A leaf the platform
     * decides from its record ({@see TaskPlanItem::$doneCriterion} under a purpose whose criteria decide ticks) is
     * left out: the platform closes it when the record shows it, a tick before that is refused. Open leaves count,
     * and a leaf the platform closed with another verdict than `done` in this step: the call the model made IS
     * the item's done-evidence, so its own tick decides.
     *
     * @param string[] $keysResolvedByPlatformInThisStep
     * @return string[]
     */
    public function getItemNumberPathsTickableAfterToolCall(string $toolName, TaskPlanEvidence $taskPlanEvidence, array $keysResolvedByPlatformInThisStep = []): array
    {
        $tickableKeys = [];
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            $itemKey = $taskPlanItemAddress->key;
            $closedByPlatformWithAnotherVerdict = in_array($itemKey, $keysResolvedByPlatformInThisStep, true)
                && $taskPlanItem->status !== TaskPlanItem::STATUS_DONE;
            if (!$taskPlanItem->isOpen() && !$closedByPlatformWithAnotherVerdict) {
                continue;
            }
            if (!in_array($toolName, $taskPlanItem->getDoneRequiresToolCallAlternatives(), true)
                || ($taskPlanEvidence->criteriaDecideTicks && $taskPlanItem->doneCriterion !== null)
                || ($taskPlanItem->doneRequiresWidget ?? null) !== null
                || $taskPlanItem->doneRequiresOwnerVisibleAnswerInSameTurn
                || ($taskPlanItem->doneRequiresConnectedChannel ?? null) !== null
            ) {
                continue;
            }
            $qualifyingToolCall = $taskPlanEvidence->conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
                $taskPlanItem->getDoneRequiresToolCallAlternatives(),
                $taskPlanItem->doneRequiresToolCallArgumentsAbsent,
                $taskPlanItem->doneRequiresToolCallArgumentsPresent,
            );
            if ($qualifyingToolCall !== null) {
                $tickableKeys[] = $itemKey;
            }
        }
        return $tickableKeys;
    }

    /**
     * The PLATFORM closes the leaves whose declared evidence the completed call just
     * delivered, instead of asking the model to tick them: a `done` leaf naming the tool as its done-evidence (same
     * predicate as {@see getItemNumberPathsTickableAfterToolCall()} — a qualifying completed call, no widget and no
     * delivered-answer gate on top, not a criterion leaf under a purpose whose criteria decide), a `blocked` leaf whose
     * {@see TaskPlanItem::$blockedRequiresToolCall} record landed (owner-caused, never retryable), a `not_applicable`
     * leaf whose {@see TaskPlanItem::$notApplicableRequiresToolCall} record landed and no contradiction gate forbids
     * it. A call that qualifies for BOTH the blocked and the not_applicable verdict of one leaf is left to the model
     * (the platform never guesses between two verdicts). Each closed leaf carries the platform's statusNote naming
     * the tool and the call's message index. Returns the closings (item key → the status written).
     */
    public function closeLeavesProvenByCompletedCall(string $toolName, int $toolCallMessageIndex, TaskPlanEvidence $taskPlanEvidence): TaskPlanLeafClosings
    {
        $taskPlanLeafClosings = new TaskPlanLeafClosings();
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $closingStatus = TaskPlanItemPlatformCloser::forItem($taskPlanItemAddress->item)
                ->closeWhenCompletedCallProvesStatus($toolName, $toolCallMessageIndex, $taskPlanEvidence);
            if ($closingStatus !== null) {
                $taskPlanLeafClosings->addClosing($taskPlanItemAddress->key, $closingStatus);
            }
        }
        return $taskPlanLeafClosings;
    }

    /**
     * The PLATFORM closing, the TURN-END half: once the turn's final text is persisted, close every open leaf
     * whose remaining evidence is in the transcript — a widget leaf whose widget (the model's or a platform-written
     * one) is rendered and whose tool gate, if declared, is satisfied; a delivery leaf
     * ({@see TaskPlanItem::$doneRequiresOwnerVisibleAnswerInSameTurn}) whose evidence call lies in THIS turn and
     * is followed by an owner-visible answer. Offers that wait for the owner's click
     * ({@see TaskPlanItem::$doneRequiresConnectedChannel}) are never
     * closed here. Returns the closings (item key → status written: `done` or `not_applicable` for a widget the owner
     * declined). The turn end is the one write (`AIConversationTaskPlanRecordService::closePlanLeavesAtTurnEnd()`); the
     * checked stop runs this closer on a COPY of the plan only ({@see hasOwedOpenItemsAfterCandidateTextEvidence()}).
     */
    public function closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd(TaskPlanEvidence $taskPlanEvidence): TaskPlanLeafClosings
    {
        $taskPlanLeafClosings = new TaskPlanLeafClosings();
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $closingStatus = TaskPlanItemPlatformCloser::forItem($taskPlanItemAddress->item)->applyTurnEndEvidenceClosing($taskPlanEvidence);
            if ($closingStatus !== null) {
                $taskPlanLeafClosings->addClosing($taskPlanItemAddress->key, $closingStatus);
            }
        }
        return $taskPlanLeafClosings;
    }

    /**
     * Whether owed open leaves remain once the candidate final text's evidence is counted — the turn-end closer
     * ({@see closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd()}) run on a COPY of this tree, so the checked stop
     * READS what the text would close and writes nothing; the one write is the turn end's, after the text is accepted.
     */
    public function hasOwedOpenItemsAfterCandidateTextEvidence(TaskPlanEvidence $taskPlanEvidence): bool
    {
        $taskPlanItemsCopy = self::buildFromRepositoryArrayAsStored($this->toRepositoryArray());
        $taskPlanItemsCopy->closeLeavesWhoseWidgetOrDeliveryEvidenceLandedAtTurnEnd($taskPlanEvidence);
        return $taskPlanItemsCopy->hasOwedOpenItems();
    }

    /**
     * The OWED open leaves ({@see TaskPlanItem::isOwedOpen()}) in document order, item key => text, at most
     * `$limit` — what the agent should do next, by address (the held-back `load_skills` answer names the first).
     *
     * @return array<string, string>
     */
    public function getOwedOpenLeafTextsByNumberPath(int $limit = 1): array
    {
        $leafTextsByKey = [];
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            if (count($leafTextsByKey) >= $limit) {
                break;
            }
            $taskPlanItem = $taskPlanItemAddress->item;
            // a leaf the RECORD closes through its criterion alone (no tool or widget gate) is never the model's next move —
            // named, it drew useless ticks and invented contact statements (31fd3942740 audit: 16 of 66 hints named 3.1)
            if ($taskPlanItem->isOwedOpen()
                && !($taskPlanItem->doneCriterion !== null
                    && $taskPlanItem->doneRequiresToolCall === null && $taskPlanItem->doneRequiresWidget === null)
            ) {
                $leafTextsByKey[$taskPlanItemAddress->key] = (string)$taskPlanItem->text;
            }
        }
        return $leafTextsByKey;
    }

    /**
     * The UNION of the skills the next `$lookahead` owed open leaves name or inherit: what a model's
     * `load_skills` may load now in a seeded-plan purpose — an act further ahead waits for its turn.
     *
     * @return string[]
     */
    public function collectSkillNamesOfNextOwedOpenItems(int $lookahead = 3): array
    {
        $skillNames = [];
        foreach (array_slice($this->collectOwedOpenLeafSkillNamesByNumberPath(), 0, max(1, $lookahead)) as $effectiveSkillNames) {
            $skillNames = array_merge($skillNames, $effectiveSkillNames);
        }
        return array_values(array_unique($skillNames));
    }

    /**
     * The skills DUE NOW for the platform's act preload: the union of the next three owed leaves' skills
     * ({@see collectSkillNamesOfNextOwedOpenItems()}) and the FIRST act ahead — the first skill an owed open leaf
     * names, in plan order, that is neither resident nor in that window. Read-ahead by one act:
     * the act after the one the owner is answering is resident before the answer arrives, so the switch costs no
     * `load_skills` step; one skill early in context is the price. The anchoring only preloads — a `load_skills`
     * of the model is never refused.
     *
     * @param string[] $residentSkillNames
     * @return string[]
     */
    public function collectSkillNamesDueNow(int $lookahead, array $residentSkillNames): array
    {
        $skillNames = $this->collectSkillNamesOfNextOwedOpenItems($lookahead);
        foreach ($this->collectOwedOpenLeafSkillNamesByNumberPath() as $leafSkillNames) {
            foreach ($leafSkillNames as $skillName) {
                if (!in_array($skillName, $residentSkillNames, true) && !in_array($skillName, $skillNames, true)) {
                    $skillNames[] = $skillName;
                    return $skillNames;
                }
            }
        }
        return $skillNames;
    }

    /** How many owed open leaves the plan-item preload looks at ({@see collectSkillNamesDueNow()}). */
    public const int PLAN_ITEM_PRELOAD_LEAF_LOOKAHEAD = 3;

    /**
     * The owed open LEAVES in plan order with the skills they name or inherit, keyed by item key — the walk the
     * decision model's `actsAhead` is built from ({@see TaskPlan::toActsAheadLines()}). Pure fold.
     *
     * @return array<string, string[]> item key → effective skill names (possibly empty)
     */
    public function collectOwedOpenLeafSkillNamesByNumberPath(): array
    {
        $skillNamesByKey = [];
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->isOwedOpen()) {
                $skillNamesByKey[$taskPlanItemAddress->key] = array_values($taskPlanItemAddress->effectiveSkillNames);
            }
        }
        return $skillNamesByKey;
    }

    /**
     * The stage tool the FIRST open leaf (document order, depth-first) owes as done-evidence — null when no open
     * item carries a `doneRequiresToolCall` gate. The forced first step of a work-signal turn pins tool_choice to
     * it when it is offered ({@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService}): on a
     * run that has done nothing yet that is the tool of the first stage, never a later one.
     */
    public function getFirstOpenDoneGateToolName(): ?string
    {
        return $this->getFirstOpenDoneGateLeaf()?->getDoneRequiresToolCallAlternatives()[0] ?? null;
    }

    /**
     * The gate tool the plan's NEXT gated leaf still owes, or null. "Next" is the FIRST open leaf in document order
     * that carries a `doneRequiresToolCall` (the same walk as {@see getFirstOpenDoneGateToolName()}); "owes" means no
     * COMPLETED call of that tool in $conversationMessages satisfies the leaf's own argument filters
     * ({@see TaskPlanItem::$doneRequiresToolCallArgumentsAbsent} / {@see TaskPlanItem::$doneRequiresToolCallArgumentsPresent}:
     * a call whose arguments the filters reject never resolves the gate). A gate tool in
     * $entitlementLockedToolNames (the account structurally cannot run it) counts as resolved — the same waiver the
     * final-stretch dictation and the tick apply. Deliberately ONLY the next gated leaf, not any open gate anywhere:
     * in a seeded run some later item owes a gate until the handover, and "any" would switch the read-dedup breaker
     * off for the whole run; the next leaf is the write the model is about to do NOW (a refused
     * repeat of a read must not take the place of that write). A resolved-but-unticked next gate reads
     * "nothing owed" — the write happened, forcing the answer is right, the tick rides the next step.
     *
     * @param string[] $entitlementLockedToolNames
     */
    public function getNextOwedDoneGateToolName(
        AIConversationMessages $conversationMessages,
        array $entitlementLockedToolNames = []
    ): ?string {
        $nextGateToolLeaf = $this->getFirstOpenDoneGateLeaf();
        if ($nextGateToolLeaf === null) {
            return null;
        }
        $doneGateToolNames = $nextGateToolLeaf->getDoneRequiresToolCallAlternatives();
        if (array_intersect($doneGateToolNames, $entitlementLockedToolNames) !== []) {
            return null;
        }
        $resolvingCall = $conversationMessages->buildToolCallHistory()->getNewestResolvedToolCallByAnyToolName(
            $doneGateToolNames,
            $nextGateToolLeaf->doneRequiresToolCallArgumentsAbsent,
            $nextGateToolLeaf->doneRequiresToolCallArgumentsPresent
        );
        return $resolvingCall === null ? ($doneGateToolNames[0] ?? null) : null;
    }

    /**
     * The FIRST open leaf (document order, depth-first) carrying a `doneRequiresToolCall` — the item behind
     * {@see getFirstOpenDoneGateToolName()}, returned whole so a caller can read its argument filters too.
     */
    public function getFirstOpenDoneGateLeaf(): ?TaskPlanItem
    {
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->isOpen() && !$taskPlanItemAddress->hasClosedAncestor && $taskPlanItem->doneRequiresToolCall !== null) {
                return $taskPlanItem;
            }
        }
        return null;
    }

    /**
     * The MINIMUM reasoning effort the next model call must run at — the highest `minReasoningEffort` along
     * the first OWED open chain (document order, depth-first: the first open item, then its first open subtask, …;
     * a parent's value covers its subtasks). Null when no open item declares one. Pure over the tree.
     */
    public function getMinReasoningEffortOfFirstOpenItem(): ?string
    {
        foreach ($this->getElements() as $taskPlanItem) {
            if (!$taskPlanItem->isOwedOpen()) {
                continue;
            }
            $minimum = $taskPlanItem->minReasoningEffort;
            if ($taskPlanItem->subtasks !== null && $taskPlanItem->subtasks->hasOwedOpenItems()) {
                $subtaskMinimum = $taskPlanItem->subtasks->getMinReasoningEffortOfFirstOpenItem();
                if ($subtaskMinimum !== null && ($minimum === null || self::reasoningEffortRank($subtaskMinimum) > self::reasoningEffortRank($minimum))) {
                    $minimum = $subtaskMinimum;
                }
            }
            return $minimum;
        }
        return null;
    }

    /** `low` = 0, `medium` = 1, `high` = 2, anything else −1 — the order the minimum comparison uses. */
    protected static function reasoningEffortRank(?string $reasoningEffort): int
    {
        return match ($reasoningEffort) {
            'low' => 0,
            'medium' => 1,
            'high' => 2,
            default => -1,
        };
    }

    /**
     * Whether any OPEN item of this level or any subtree carries a done-evidence gate
     * ({@see TaskPlanItem::$doneRequiresToolCall} / {@see TaskPlanItem::$doneRequiresWidget}) — i.e. hard,
     * tool-provable work is still owed. The announce-loop bounce keys on this (an open ungated item may be
     * legitimately conversational; an open GATED item never is). Pure fold over the tree.
     */
    public function hasOpenItemsRequiringDoneEvidence(): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            // an awaiting non-blocking offer owes nothing to the agent, nor does anything below it
            if ($taskPlanItem->isOwedOpen() && !$taskPlanItemAddress->hasNotOwedAncestor
                && ($taskPlanItem->doneRequiresToolCall !== null || $taskPlanItem->doneRequiresWidget !== null)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every item of this level and all subtrees whose RAW status equals the given one — a flat tree harvest
     * (raw status on purpose, not the tree-aware effective state: the cascade-block sweep compares what the
     * payload literally declares against what the prior plan literally held).
     *
     * @return TaskPlanItem[]
     */
    public function getItemsWithStatus(string $status): array
    {
        $itemsWithStatus = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->status === $status) {
                $itemsWithStatus[] = $taskPlanItemAddress->item;
            }
        }
        return $itemsWithStatus;
    }

    /** Whether any item (at any depth) carries the given customer-progress step key. */
    public function hasItemsForProgressStepKey(string $customerProgressStepKey): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->customerProgressStepKey === $customerProgressStepKey) {
                return true;
            }
        }
        return false;
    }

    /** Whether a node WITHOUT a subtask level of the given progress step was resolved by the PLATFORM (from the record), not only by the model's ticks. */
    public function hasPlatformResolvedItemsForProgressStepKey(string $customerProgressStepKey): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            // only nodes WITHOUT a subtask level are read — an empty level counts as a container here
            if ($taskPlanItem->subtasks === null && $taskPlanItem->customerProgressStepKey === $customerProgressStepKey
                && ($taskPlanItem->platformResolvedAtMessageIndex ?? null) !== null
            ) {
                return true;
            }
        }
        return false;
    }

    /** Whether a node WITHOUT a subtask level carries the given progress step and is still OWED open (a container's own key never counts). */
    public function hasOwedOpenItemsForProgressStepKey(string $customerProgressStepKey): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            // only nodes WITHOUT a subtask level are read — an empty level counts as a container here
            if ($taskPlanItem->subtasks === null && $taskPlanItem->customerProgressStepKey === $customerProgressStepKey && $taskPlanItem->isOwedOpen()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The open items' KEYS ("9.2", "10") across this level and all subtrees — the exact addresses
     * `set_task_status` resolves. A container with open subtasks is represented by its open DESCENDANTS (the
     * actionable leaves), mirroring {@see getOpenItemPathTexts()}.
     *
     * @return string[]
     */
    public function getOpenItemNumberPaths(): array
    {
        $openItemKeys = [];
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->isOpen() && !$taskPlanItemAddress->hasClosedAncestor) {
                $openItemKeys[] = $taskPlanItemAddress->key;
            }
        }
        return $openItemKeys;
    }

    /**
     * The keys of the open leaves BELOW `$containerItem` (a node of this tree) — the same open-leaf rule as
     * {@see getOpenItemNumberPaths()}, read inside the container's subtree, each leaf named by its key in THIS tree. The
     * container corrective of `set_task_status` names them.
     *
     * @return string[]
     */
    public function getOpenSubtaskKeysOf(TaskPlanItem $containerItem): array
    {
        $taskPlanItemAddresses = $this->buildItemAddresses();
        $openSubtaskKeys = [];
        foreach ($containerItem->subtasks?->buildItemAddresses()->getLeaves() ?? [] as $subtaskAddress) {
            if ($subtaskAddress->item->isOpen() && !$subtaskAddress->hasClosedAncestor) {
                $openSubtaskKeys[] = $taskPlanItemAddresses->getByItem($subtaskAddress->item)->key ?? $subtaskAddress->key;
            }
        }
        return $openSubtaskKeys;
    }

    /**
     * The open leaves (as {@see getOpenItemNumberPaths()} lists them) with their texts, `<key> '<text>'`, each text clipped
     * to `$maxTextChars` — the address list a rejected `set_task_status` call ends on. A key that names more than one
     * item (a stored tree with a duplicate) is left out: it addresses nothing.
     *
     * @return string[]
     */
    public function getOpenItemKeyLines(int $maxTextChars = 50): array
    {
        $taskPlanItemAddresses = $this->buildItemAddresses();
        $openItemKeyLines = [];
        foreach ($taskPlanItemAddresses->getLeaves() as $taskPlanItemAddress) {
            if (!$taskPlanItemAddress->item->isOpen() || $taskPlanItemAddress->hasClosedAncestor
                || count($taskPlanItemAddresses->getAllByKey($taskPlanItemAddress->key)) !== 1
            ) {
                continue;
            }
            $openItemKeyLines[] = self::buildKeyLine($taskPlanItemAddress, $maxTextChars);
        }
        return $openItemKeyLines;
    }

    /**
     * The OWED open leaves (the membership of {@see getOpenItemPathTexts()}: an awaiting offer is no remaining work) as
     * `<key> '<title>'` lines, the title as the plan line shows it ({@see TaskPlanItem::buildPlanLineTitle()}) — the
     * open-work lists of the checked-stop rejection: `$closedByTheRecord` false = the items the model ticks, true = the
     * items the record closes (the model does their work). A key that names more than one item is left out: it addresses
     * nothing.
     *
     * @return string[]
     */
    public function getOwedOpenItemTitleLines(bool $closedByTheRecord): array
    {
        $taskPlanItemAddresses = $this->buildItemAddresses();
        $owedOpenItemTitleLines = [];
        foreach ($taskPlanItemAddresses->getLeaves() as $taskPlanItemAddress) {
            if (!$taskPlanItemAddress->item->isOwedOpen() || $taskPlanItemAddress->hasNotOwedAncestor
                || $taskPlanItemAddress->item->isOpenAndClosedByTheRecord() !== $closedByTheRecord
                || count($taskPlanItemAddresses->getAllByKey($taskPlanItemAddress->key)) !== 1
            ) {
                continue;
            }
            $owedOpenItemTitleLines[] = $taskPlanItemAddress->key . " '" . $taskPlanItemAddress->item->buildPlanLineTitle() . "'";
        }
        return $owedOpenItemTitleLines;
    }

    /**
     * The OWED open leaves as `<key> '<title>'` lines (both kinds of {@see getOwedOpenItemTitleLines()}, plan order),
     * without the leaves whose done-evidence is a call of `$doneGateToolName` — the items still open beside the step
     * that tool completes (a purpose's handover names what it leaves parked).
     *
     * @return string[]
     */
    public function getOwedOpenItemTitleLinesBesideDoneGateTool(string $doneGateToolName): array
    {
        $taskPlanItemAddresses = $this->buildItemAddresses();
        $owedOpenItemTitleLines = [];
        foreach ($taskPlanItemAddresses->getLeaves() as $taskPlanItemAddress) {
            if (!$taskPlanItemAddress->item->isOwedOpen() || $taskPlanItemAddress->hasNotOwedAncestor
                || in_array($doneGateToolName, $taskPlanItemAddress->item->getDoneRequiresToolCallAlternatives(), true)
                || count($taskPlanItemAddresses->getAllByKey($taskPlanItemAddress->key)) !== 1
            ) {
                continue;
            }
            $owedOpenItemTitleLines[] = $taskPlanItemAddress->key . " '" . $taskPlanItemAddress->item->buildPlanLineTitle() . "'";
        }
        return $owedOpenItemTitleLines;
    }

    /**
     * EVERY item in plan order (pre-order) as `<key> '<text>'`, each text clipped to `$maxTextChars` — the key list a
     * rejected `update_task_plan` call ends on. A key that names more than one item (a stored tree with a
     * duplicate) is left out: it addresses nothing.
     *
     * @return string[]
     */
    public function getKeyLinesNamingOneItem(int $maxTextChars = 50): array
    {
        $taskPlanItemAddresses = $this->buildItemAddresses();
        $keyLines = [];
        foreach ($taskPlanItemAddresses->getElements() as $taskPlanItemAddress) {
            if (count($taskPlanItemAddresses->getAllByKey($taskPlanItemAddress->key)) !== 1) {
                continue;
            }
            $keyLines[] = self::buildKeyLine($taskPlanItemAddress, $maxTextChars);
        }
        return $keyLines;
    }

    /** One `<key> '<text>'` line, the text clipped to `$maxTextChars`. */
    protected static function buildKeyLine(TaskPlanItemAddress $taskPlanItemAddress, int $maxTextChars): string
    {
        $itemText = trim($taskPlanItemAddress->item->text);
        if (mb_strlen($itemText) > $maxTextChars) {
            $itemText = rtrim(mb_substr($itemText, 0, $maxTextChars - 1)) . '…';
        }
        return $taskPlanItemAddress->key . " '" . $itemText . "'";
    }

    /**
     * Tree-wide item counts per EFFECTIVE state (this level + all subtrees) — the tool result's honest summary
     * line ("13 items (4 done, 7 open, 2 blocked)"). Buckets by the same tree-aware predicate the checked stop
     * enforces ({@see TaskPlanItem::isOpen()}), not the raw status — so the summary, the tail render, the bounce
     * and the eval completion gate can never disagree: `blocked` counts blocked (raw) and PRUNES its subtree
     * (mirroring {@see TaskPlanItem::isOpen()} — the named blocker covers the children, so they count neither
     * open nor done; counting them `open` made the summary say "2 open" on a plan the checked stop accepted,
     * which was wrong); everything else counts `open` iff it is effectively open (a `done` parent
     * with open subtasks counts open; an `open` container whose subtasks are all terminal counts done).
     *
     */
    public function countItemsByStatus(): TaskPlanItemStatusCounts
    {
        $taskPlanItemStatusCounts = new TaskPlanItemStatusCounts();
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            // a blocked node prunes its subtree — isOpen() treats it as covered by the blocker (a not_applicable one does not)
            if ($taskPlanItemAddress->hasBlockedAncestor) {
                continue;
            }
            if ($taskPlanItemAddress->item->status === TaskPlanItem::STATUS_BLOCKED) {
                $taskPlanItemStatusCounts->blocked++;
            } elseif ($taskPlanItemAddress->itemIsOpen) {
                $taskPlanItemStatusCounts->open++;
            } else {
                $taskPlanItemStatusCounts->done++;
            }
        }
        return $taskPlanItemStatusCounts;
    }

    /**
     * Whether any item of this level — or of any subtree — is BLOCKED with a non-empty {@see TaskPlanItem::$statusNote}
     * (the taxonomy in use): the eval gate `taskPlan.hasBlockedWithReason` reads THIS predicate, so the eval's
     * definition of "blocked with reason" can never diverge from the domain's.
     */
    public function hasBlockedItemWithReason(): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED && trim((string)($taskPlanItem->statusNote ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any item of this level — or of any subtree — is BLOCKED with {@see TaskPlanItem::$requiresUserAction}
     * (only the user can unblock it — the axis stage 4 parks on): the eval gate `taskPlan.hasRequiresUserAction`.
     */
    public function hasBlockedItemRequiringUserAction(): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->status === TaskPlanItem::STATUS_BLOCKED && $taskPlanItemAddress->item->requiresUserAction) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a BLOCKED item flagged requiresUserAction carries `$textNeedle` in its text (case-insensitive) — the eval
     * gate `taskPlan.blockedRequiringUserActionItemTextContains` names the item it expects blocked (an
     * unrelated item blocked with requiresUserAction no longer passes the case).
     */
    public function hasBlockedItemRequiringUserActionWithTextContaining(string $textNeedle): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED && $taskPlanItem->requiresUserAction
                && mb_stripos($taskPlanItem->text, $textNeedle) !== false
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The text of the FIRST done LEAF without a {@see TaskPlanItem::$statusNote} (containers derive their state, so
     * only leaves are checked), or null when every done leaf states its result — the eval gate
     * `taskPlan.hasCompletionNotes` names the offending item from this.
     */
    public function getDoneLeafTextWithoutStatusNote(): ?string
    {
        foreach ($this->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->status === TaskPlanItem::STATUS_DONE && trim((string)($taskPlanItem->statusNote ?? '')) === '') {
                return $taskPlanItem->text;
            }
        }
        return null;
    }

    /**
     * Whether any item of this level — or of any subtree — is BLOCKED with `retryable: true` (a transient external
     * failure the system may retry on a later run): the {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$taskPlanRetryableAt}
     * marker's derivation, consumed by the stage-5 retry sweep.
     */
    public function hasRetryableBlockedItems(): bool
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->status === TaskPlanItem::STATUS_BLOCKED && $taskPlanItemAddress->item->retryable) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tree-wide LEAF count (nodes without subtasks — the actual work units; containers derive their state from
     * their children and add no work of their own). The plan-adaptive step budget scales on this
     * ({@see AIConversationTurnBudget::$totalMaxSteps}).
     */
    public function countLeafItems(): int
    {
        return count($this->buildItemAddresses()->getLeaves());
    }

    /** Tree-wide NODE count (this level + all subtrees) — the parser's total-size cap reads this. */
    public function countAllItems(): int
    {
        return $this->buildItemAddresses()->count();
    }

    /**
     * The node the render marks `← next`: the FIRST node in the tail hint's own pre-order walk —
     * every node visited, blocked / n-a subtrees included, exactly as {@see buildTailHintLines()} descends — whose
     * {@see TaskPlanItem::claimsNextMarker()} holds. A NODE, not always a leaf. Null when nothing is open.
     */
    public function getNextMarkedItem(): ?TaskPlanItem
    {
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($taskPlanItemAddress->item->claimsNextMarker()) {
                return $taskPlanItemAddress->item;
            }
        }
        return null;
    }

    /** Whether the node the render marks `← next` belongs to the progress step $customerProgressStepKey. */
    public function nextMarkedItemHasProgressStepKey(string $customerProgressStepKey): bool
    {
        return ($this->getNextMarkedItem()?->customerProgressStepKey ?? null) === $customerProgressStepKey;
    }

    /**
     * The tail-hint render lines of the tree (two-space indent per depth), in the STORED order (pre-order — never sorted
     * by key). Every line carries the item's KEY ("2.1"; the number path for an item built in memory without
     * one) — the address the `set_task_status` tool resolves via {@see getItemByKey()}, so the render doubles as the
     * model's address book. With `$withNextMarker` exactly one line carries `← next`: the node
     * {@see getNextMarkedItem()} names (a finished plan renders without one).
     *
     * @return string[]
     */
    public function buildTailHintLines(bool $withNextMarker = true): array
    {
        $nextMarkedItem = $withNextMarker ? $this->getNextMarkedItem() : null;
        $renderLines = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            $renderLines[] = str_repeat('  ', $taskPlanItemAddress->depth)
                . $taskPlanItem->toTailHintLine($taskPlanItemAddress->key, $nextMarkedItem !== null && $taskPlanItem === $nextMarkedItem);
        }
        return $renderLines;
    }

    /** Leads the one summary line of the done items on the compact plan. */
    public const string COMPACT_DONE_SUMMARY_PREFIX = '✓ done: ';

    /** Sibling keys in a row from this many on are written as a range (`1.1–1.4`). */
    public const int COMPACT_DONE_RANGE_MIN_LENGTH = 3;

    /**
     * The COMPACT render lines of the tree — the plan the model reads in every call: ONE summary line of
     * the done items first (`✓ done: 1.1–1.4, 2.1, 3.2` — keys only, sibling runs as ranges; an item under a closed
     * ancestor is covered by it; a blocked / n-a item under a done item rides the summary as `(1.1 n/a, 3.4 blocked)` —
     * on a line of its own without its parent it read as owed and was re-ticked), then every other item in the walk and
     * the indent of {@see buildTailHintLines()} as
     * {@see TaskPlanItem::toCompactTailHintLine()} — key, status and title, a short reason on blocked / n-a items, the full
     * text only on the one `← next` line. Deterministic: an unchanged plan renders the same bytes.
     *
     * @return string[]
     */
    public function buildCompactTailHintLines(): array
    {
        $nextMarkedItem = $this->getNextMarkedItem();
        $doneKeys = [];
        $terminalKeysUnderDoneItems = [];
        $renderLines = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $taskPlanItem = $taskPlanItemAddress->item;
            if ($taskPlanItem->rendersAsDoneOnPlanLine()) {
                if (!$taskPlanItemAddress->hasClosedAncestor) {
                    $doneKeys[] = $taskPlanItemAddress->key;
                }
                continue;
            }
            if ($taskPlanItemAddress->hasAncestorRenderingAsDoneOnPlanLine()) {
                // a blocked / n-a subtree below it is named once, by its top node
                if (!$taskPlanItemAddress->isInsideTerminalContainer) {
                    $terminalKeysUnderDoneItems[] = $taskPlanItemAddress->key . ' '
                        . ($taskPlanItem->status === TaskPlanItem::STATUS_BLOCKED ? 'blocked' : 'n/a');
                }
                continue;
            }
            $renderLines[] = str_repeat('  ', $taskPlanItemAddress->depth)
                . $taskPlanItem->toCompactTailHintLine($taskPlanItemAddress->key, $nextMarkedItem !== null && $taskPlanItem === $nextMarkedItem);
        }
        if ($doneKeys === []) {
            return $renderLines;
        }
        return [
            self::COMPACT_DONE_SUMMARY_PREFIX . implode(', ', self::buildKeyRanges($doneKeys))
                . ($terminalKeysUnderDoneItems !== [] ? ' (' . implode(', ', $terminalKeysUnderDoneItems) . ')' : ''),
            ...$renderLines,
        ];
    }

    /**
     * The done keys in walk order, each run of consecutive siblings (the same parent key, the last segment one higher) of
     * at least {@see COMPACT_DONE_RANGE_MIN_LENGTH} written as `first–last`.
     *
     * @param string[] $keys
     * @return string[]
     */
    protected static function buildKeyRanges(array $keys): array
    {
        $keyRanges = [];
        $currentRun = [];
        foreach ($keys as $key) {
            if ($currentRun !== [] && !self::isNextSiblingKey(end($currentRun), $key)) {
                $keyRanges = [...$keyRanges, ...self::renderKeyRun($currentRun)];
                $currentRun = [];
            }
            $currentRun[] = $key;
        }
        return [...$keyRanges, ...self::renderKeyRun($currentRun)];
    }

    /** Whether `$key` is the sibling right after `$previousKey` (same parent key, last segment one higher). */
    protected static function isNextSiblingKey(string $previousKey, string $key): bool
    {
        $previousSegments = explode('.', $previousKey);
        $segments = explode('.', $key);
        if (count($previousSegments) !== count($segments)) {
            return false;
        }
        $previousLastSegment = array_pop($previousSegments);
        $lastSegment = array_pop($segments);
        return $previousSegments === $segments && ctype_digit($previousLastSegment) && ctype_digit($lastSegment)
            && (int)$lastSegment === (int)$previousLastSegment + 1;
    }

    /**
     * @param string[] $keyRun
     * @return string[]
     */
    protected static function renderKeyRun(array $keyRun): array
    {
        if (count($keyRun) >= self::COMPACT_DONE_RANGE_MIN_LENGTH) {
            return [$keyRun[0] . '–' . $keyRun[count($keyRun) - 1]];
        }
        return $keyRun;
    }

    /**
     * The JUDGE render of the tree ({@see TaskPlanItem::toJudgeLine()}) — same numbering and indentation as the
     * agent's tail hint, plus the provenance suffixes the evaluator reads. Never shown to the agent.
     *
     * @return string[]
     */
    public function buildJudgeLines(): array
    {
        $renderLines = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            $renderLines[] = str_repeat('  ', $taskPlanItemAddress->depth)
                . $taskPlanItemAddress->item->toJudgeLine($taskPlanItemAddress->key);
        }
        return $renderLines;
    }

    /**
     * The ONE item this KEY names — the address every consumer resolves: the `set_task_status` tick, the
     * closers' notes, the bookkeeping verdicts, the candidate-text revert. Exact: no trimming, no title, no text. Null when
     * no item carries the key, and when MORE than one does (a stored tree with a duplicate key) — never the first
     * match. An item built in memory and not minted yet answers to its number path ({@see TaskPlanItemAddress::$key}).
     */
    public function getItemByKey(string $key): ?TaskPlanItem
    {
        $matchingAddresses = $this->buildItemAddresses()->getAllByKey($key);
        return count($matchingAddresses) === 1 ? $matchingAddresses[0]->item : null;
    }

    /** How many items of this tree carry this key — 1 for a key that addresses, 0 for an unknown one, more for a stored duplicate. */
    public function countItemsWithKey(string $key): int
    {
        return count($this->buildItemAddresses()->getAllByKey($key));
    }

    /**
     * Resolve a NUMBER PATH ("2" / "2.1" — 1-based positions per level) to its {@see TaskPlanItem} by POSITION, or null
     * when any segment is out of range. No consumer on the live path addresses by position (the key
     * does, {@see getItemByKey()}); the replay of ticks recorded before keys existed resolves through it
     * (the tolerant addressing of such ticks on the live path has been removed).
     */
    public function getItemByNumberPath(string $numberPath): ?TaskPlanItem
    {
        $positionSegments = explode('.', trim($numberPath));
        $currentLevelItems = $this;
        $resolvedItem = null;
        foreach ($positionSegments as $positionSegment) {
            if (!ctype_digit($positionSegment) || $currentLevelItems === null) {
                return null;
            }
            $levelElements = array_values($currentLevelItems->getElements());
            $resolvedItem = $levelElements[(int)$positionSegment - 1] ?? null;
            if ($resolvedItem === null) {
                return null;
            }
            $currentLevelItems = $resolvedItem->subtasks;
        }
        return $resolvedItem;
    }

    /**
     * Resolve an item by its TEXT across the whole tree. Case-insensitive; returns null when the text matches NO item or
     * MORE than one. Never a model address: the seed re-derivers match a seed edited after the
     * conversation started, and the replay of ticks recorded before keys existed resolves their text addresses.
     */
    public function getSingleItemByText(string $itemText): ?TaskPlanItem
    {
        $lowercasedText = mb_strtolower(trim($itemText));
        $matches = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if (mb_strtolower($taskPlanItemAddress->item->text) === $lowercasedText) {
                $matches[] = $taskPlanItemAddress->item;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Resolve an item by its OWNER-FACING TITLE across the whole tree — the stable identity of a seeded item when
     * its text was reworded. Case-insensitive; null when the title matches NO item or MORE than one.
     */
    public function getSingleItemByOwnerFacingTitle(string $ownerFacingTitle): ?TaskPlanItem
    {
        $lowercasedTitle = mb_strtolower(trim($ownerFacingTitle));
        $matches = [];
        foreach ($this->buildItemAddresses()->getElements() as $taskPlanItemAddress) {
            if ($lowercasedTitle !== '' && mb_strtolower(trim((string)($taskPlanItemAddress->item->ownerFacingTitle ?? ''))) === $lowercasedTitle) {
                $matches[] = $taskPlanItemAddress->item;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * This level as plain nested arrays for the JSON column — EVERY key of every item explicitly present (the
     * RFC-7396 complete-slice rule), `subtasks` always a (possibly empty) ARRAY.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toRepositoryArray(): array
    {
        return array_map(
            static fn (TaskPlanItem $taskPlanItem): array => $taskPlanItem->toRepositoryFields(),
            $this->getElements()
        );
    }

    /**
     * Rebuild a whole tree from the persisted nested arrays — the inverse of {@see toRepositoryArray()} — with its keys
     * derived or preserved ({@see TaskPlanStoredKeyPreservation::deriveOrPreserveKeys()}). The plan's own read is {@see TaskPlan::mapFromRepository()},
     * which keeps the top-level counter and the violations; this entry drops both.
     *
     * @param array<int, mixed> $repositoryItems
     */
    public static function fromRepositoryArray(array $repositoryItems): self
    {
        $taskPlanItems = self::buildFromRepositoryArrayAsStored($repositoryItems);
        TaskPlanStoredKeyPreservation::deriveOrPreserveKeys($taskPlanItems, 0, new TaskPlanKeyIntegrityViolations());
        return $taskPlanItems;
    }

    /**
     * Rebuild a level (and its subtrees) exactly as stored, keys included — malformed entries are skipped defensively
     * (a plan column is agent-written state, never trusted blindly on read). An item WITH a stored key is kept also
     * when its key repeats a sibling's (a duplicate is reported, never collapsed); an item without one
     * collapses on a repeated text, as every stored plan always did.
     *
     * @param array<int, mixed> $repositoryItems
     */
    public static function buildFromRepositoryArrayAsStored(array $repositoryItems): static
    {
        $taskPlanItems = static::newInstance();
        foreach ($repositoryItems as $itemFields) {
            if (!is_array($itemFields)) {
                continue;
            }
            $taskPlanItem = TaskPlanItem::fromRepositoryFields($itemFields);
            if ($taskPlanItem->key !== null && $taskPlanItems->holdsItemWithUniqueKey($taskPlanItem->uniqueKey())) {
                $taskPlanItems->appendItemKeepingDuplicateKey($taskPlanItem);
                continue;
            }
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    /** Append $taskPlanItem although a sibling carries its key — the stored duplicate stays visible (and is reported). */
    protected function appendItemKeepingDuplicateKey(TaskPlanItem $taskPlanItem): void
    {
        $this->elements[] = $taskPlanItem;
        $this->elementCount++;
        $taskPlanItem->setParent($this);
        if ($this->addAsChild) {
            $this->addChildren($taskPlanItem);
        }
    }

    /** Whether a sibling of this level already holds $uniqueKey. */
    protected function holdsItemWithUniqueKey(string $uniqueKey): bool
    {
        return isset($this->elementsByUniqueKey[$uniqueKey]);
    }

    /** Whether this LEVEL already holds an item with exactly this text — the seed's and the parse's text dedup. */
    public function containsItemWithText(string $itemText): bool
    {
        foreach ($this->getElements() as $taskPlanItem) {
            if ($taskPlanItem->text === $itemText) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mint a key for every node of the tree by its position: `parentKey.position`, each item's
     * subtask counter raised to its child count. Today's full replace creates all-new identities, so the key equals the
     * number path. Returns the level's item count (the counter of the level's parent).
     */
    public function assignPositionalKeys(string $parentKey = ''): int
    {
        foreach (array_values($this->getElements()) as $position => $taskPlanItem) {
            $taskPlanItem->key = TaskPlanItem::composeChildKey($parentKey, $position + 1);
            if ($taskPlanItem->subtasks !== null) {
                $taskPlanItem->highestSubtaskKeyNumber = max($taskPlanItem->highestSubtaskKeyNumber, $taskPlanItem->subtasks->assignPositionalKeys($taskPlanItem->key));
            }
        }
        $this->regenerateElementsByUniqueKey();
        return $this->count();
    }

    /** The highest last segment among this level's conforming keys — the floor of the level's counter. */
    public function getHighestKeySegmentOfLevel(): int
    {
        $highestSegment = 0;
        foreach ($this->getElements() as $taskPlanItem) {
            if ($taskPlanItem->key !== null) {
                $highestSegment = max($highestSegment, (int)TaskPlanItem::getLastKeySegment($taskPlanItem->key));
            }
        }
        return $highestSegment;
    }
}
