<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;

/**
 * The agent run's TASK PLAN — the persisted current-state artifact of a multi-part task, maintained
 * by the model via the `update_task_plan` meta-tool (the keyed contract: {@see checkUpdate()},
 * {@see applyUpdate()}, {@see createFromUpdate()}; the persisted TOOL_CALL messages are the forensic history, this VO
 * holds only the CURRENT state). The items form a TREE
 * ({@see TaskPlanItems} of {@see TaskPlanItem}, each with optional subtasks) — a task may decompose while the
 * agent works. Lives inside {@see AgentWorkingState} on the `agentWorkingState` merge column; rendered as a
 * transient wire-tail hint each step ({@see toTailHintText()}); gates the checked stop via
 * {@see hasOpenItems()} + {@see $updatedAtTurnInitiatorMessageIndex}.
 *
 * SERIALIZATION CONTRACT (the RFC-7396 rule): the column merges via JSON_MERGE_PATCH,
 * where nested objects merge recursively and OMITTED keys SURVIVE from the previous write — so
 * {@see mapToRepository()} emits EVERY key explicitly, the item tree as plain NESTED ARRAYS (arrays replace
 * wholesale — full-replace semantics hold), and clearing requires an explicit null. Locked by a unit test.
 *
 * @phpstan-consistent-constructor
 */
class TaskPlan extends ValueObject
{
    /**
     * The tools that WRITE the plan — the ONE-WRITER invariant's enforcement set (keying
     * chokepoints on a single tool name would let the second tool bypass them all). EVERY chokepoint
     * consults THIS set, never an individual name: the loop-level `persistTaskPlanSlice` gate, the work-tool
     * exclusions (forced tool_choice / hasCompletedWorkToolCall), the read-dedup + reads-hint write-exemptions, the
     * summary ACTION-line exclusion, and the repeat-guard exemption.
     */
    public const array PLAN_TOOL_NAMES = [
        \DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool::NAME,
        \DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool::NAME,
    ];

    /**
     * @var int More BLOCKED updates than this in ONE call = the give-up cascade ("cannot X before Y" stamped on every
     * downstream item after one obstructed step) — every blocked update of that call is noted, not applied, because a
     * cascade has exactly ONE blocker: the obstructed step itself; downstream items stay OPEN until their turn. 4 keeps the
     * legitimate multi-channel park (the connect leaves + publish) applicable in one call. The status transitions an
     * `update_task_plan` call sends pass the same application and so the same cap
     * ({@see TaskPlanUpdateApplication}), or the sweep would simply move there.
     */
    public const int MAX_BLOCKED_UPDATES_PER_CALL = 4;

    /**
     * The ONE tick rule set: the `set_task_status` description, the plan header of the render and the
     * maintenance rules of the first plan write say exactly this — the same text for a plan the model made and a plan a
     * purpose seeded. The word for what an item may not apply to is the application's
     * ({@see AgentApplicationWording::$agentSpaceContextSubjectName}).
     */
    public static function buildTickRules(): string
    {
        return 'Tick an item via set_task_status (its key, a statusNote) once its result is in your context — together with'
            . ' the call for the next item or with your answer text, never before its call has returned and never as a step of its own;'
            . ' done: what you produced — an item whose read or call returned nothing usable is not done, it is blocked; blocked: the obstacle (requiresUserAction when only the owner can lift it; once it is lifted,'
            . ' work the item and tick it done); not_applicable: why it does not apply to this ' . AgentApplicationWording::current()->agentSpaceContextSubjectName . '. An item that waits for the'
            . ' owner\'s answer stays open and your answer asks for it — never done before the answer is in your context, never blocked'
            . ' in place of asking. An item the platform closes from the record (a completed call it names, a'
            . ' widget, a delivered answer, the owner\'s recorded statement) closes itself when done — its line says so; never tick it'
            . ' done, and mark it blocked or not_applicable only for an obstacle or a reason its record cannot show; never tick an'
            . ' item listed under ✓ done again. Before your final answer every item is done, blocked, not_applicable or waiting for'
            . ' the answer your text asks for, and the answer reports each outcome'
            . ' to the owner (statusNotes are internal bookkeeping the owner never sees).';
    }

    /**
     * The result text of an `update_task_plan` call that sent the plan exactly as it stands. It says that nothing
     * failed and what follows from the plan as it stands — the work of the next open item, or the answer when the open
     * items wait for the owner. It orders no work: an owner who asked to be asked before the next item is answered,
     * not overruled by a result text. The load_tool route stays named — a missing tool was the observed reason for
     * the resend.
     */
    public static function buildUnchangedResendResultText(): string
    {
        return 'Task plan NOT updated: it is ALREADY in exactly this state — resending it does no work. Nothing failed'
            . ' and nothing is unavailable. Go on from the plan as it stands: the work of the next open item — or your'
            . ' answer, when the owner asked to be asked first or to stop, or the open items wait for them. If a tool you'
            . ' need is missing from your tools list, call load_tool with its name (its schema lists every available'
            . ' tool). For status changes use set_task_status.';
    }

    /** @var TaskPlanItems|null The plan's item TREE — top level of the uniform per-depth set type. Null/empty = no plan content. */
    public ?TaskPlanItems $items = null;

    /**
     * @var int|null The `indexInConversation` of the USER-turn INITIATOR message under which this plan was last
     * written ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages::getNewestInitiatorMessageIndex()})
     * — the checked stop's staleness gate: bounce only when the plan was touched in the CURRENT turn. Deliberately
     * the STABLE persisted message index, never the user-turn COUNT: the count is derived over the LOADED window
     * and shifts when a compaction landing between chunks covers earlier turns — which would silently disarm the
     * bounce mid-task.
     */
    public ?int $updatedAtTurnInitiatorMessageIndex = null;

    /**
     * @var int|null The initiator message index under which an item last TRANSITIONED into a terminal status
     * (done/blocked/not_applicable) via `set_task_status` — the checked stop's progress exemption: a turn that
     * RESOLVED ≥1 item demonstrably worked the plan, so its final answer (typically the question to the owner
     * that the next open item needs — an ending a purpose's skill may explicitly allow) is never bounced.
     * Distinct from {@see $updatedAtTurnInitiatorMessageIndex}, which any plan write stamps (including a
     * full-replace or a no-transition re-tick).
     */
    public ?int $resolvedAtTurnInitiatorMessageIndex = null;

    /** The plan-set fields below: a plan is one of a SET — the active one plus the finished ones. */

    /** @var string|null A short title: the owner's request the plan was made for (first ~120 chars), else the first item. Stamped at retirement. */
    public ?string $title = null;

    /** @var int|null The initiator message index of the turn that CREATED the plan (a seed carries null). */
    public ?int $createdAtMessageIndex = null;

    /** @var int|null The message index at which the plan was RETIRED as finished (null while active). */
    public ?int $completedAtMessageIndex = null;

    /** @var string|null `Y-m-d H:i:s` of the retirement (null while active). */
    public ?string $completedAt = null;

    /** @var string|null One line per terminal item, `done: text — note · blocked: text — why · n/a: text — why`, composed at retirement. */
    public ?string $outcomeSummary = null;

    /**
     * @var string|null The leaves the platform closed at the END of the last turn,
     *      written as the PLAN_BOOKKEEPING signal at the start of the next turn and cleared then. Persisted with the
     *      plan so a worker handover between the turns loses nothing.
     */
    public ?string $platformClosedAtTurnEndNote = null;

    /**
     * @var int The highest segment ever minted for a TOP-LEVEL item of this plan — the next new
     *      top-level item is `n + 1` ({@see mintTopLevelKey()}); an item that leaves the plan never lowers it, and a key's
     *      scope is this one plan. A plan created by `update_task_plan` starts at its top-level item count.
     */
    public int $highestItemKeyNumber = 0;

    /**
     * @var TaskPlanKeyIntegrityViolations|null What the read of a stored KEYED tree found irregular —
     *      transient, rebuilt on every read, never persisted ({@see mapToRepository()} does not emit it); null when the
     *      plan was not read from the repository or its keys are in order. Hidden from every API payload and absent from
     *      the OpenAPI schema: an admin inspector reads the active plan's lines through its own projection.
     */
    #[HideProperty, Ignore]
    public ?TaskPlanKeyIntegrityViolations $keyIntegrityViolations = null;

    /** The top-level counter as it persists: never below the highest top-level key segment the tree holds (a seed sets no counter). */
    public function getHighestItemKeyNumber(): int
    {
        return max($this->highestItemKeyNumber, $this->items?->getHighestKeySegmentOfLevel() ?? 0);
    }

    /**
     * Mint the key of a NEW top-level item: `n + 1` from the persisted counter, which it raises. Null — and
     * nothing raised — when the top level used all 999 numbers ({@see TaskPlanItem::allocateKeySegmentAfter()}).
     */
    public function mintTopLevelKey(): ?string
    {
        $keySegment = TaskPlanItem::allocateKeySegmentAfter($this->getHighestItemKeyNumber());
        if ($keySegment === null) {
            return null;
        }
        $this->highestItemKeyNumber = $keySegment;
        return (string)$keySegment;
    }

    /** The plan holds at least one item — an `update_task_plan` call over it is a change, not a creation. */
    public function hasItems(): bool
    {
        return $this->items !== null && $this->items->count() > 0;
    }

    /** The key of the first top-level item a call may drop (not seeded by a purpose); null when the plan has none. */
    public function findFirstDroppableTopLevelItemKey(): ?string
    {
        foreach ($this->items?->getElements() ?? [] as $taskPlanItem) {
            if (!$taskPlanItem->seededByPurpose && $taskPlanItem->key !== null && $taskPlanItem->key !== '') {
                return $taskPlanItem->key;
            }
        }
        return null;
    }

    /**
     * The checks of a keyed `update_task_plan` call against this plan — pure, collected in one
     * pass; an empty result accepts the call. On a plan without items every item of the call must be new.
     */
    public function checkUpdate(UpdateTaskPlanArguments $updateTaskPlanArguments, ?TaskPlanUpdateContext $taskPlanUpdateContext = null): TaskPlanUpdateRejections
    {
        return TaskPlanUpdateCheck::checkArgumentsAgainstPlan(
            $updateTaskPlanArguments,
            $this->hasItems() ? $this : null,
            $taskPlanUpdateContext ?? TaskPlanUpdateContext::forTurn(-1)
        );
    }

    /**
     * Apply a keyed `update_task_plan` call to this standing plan: checked first — any violation rejects the
     * whole call and this plan is left byte-identical, counters and stamps included. An accepted call is applied to a
     * deep copy that keeps every stored item with all it holds (its declarations, records, status), adds the new items
     * with minted keys and drops the removed ones ({@see TaskPlanUpdateApplication}); the copy is the outcome's plan, stamped
     * as written in the turn. A call that leaves the items exactly as they are is `unchanged` (with the gate refusal
     * marked when a sent status was refused). Without items this is a
     * creation ({@see createFromUpdate()}).
     */
    public function applyUpdate(UpdateTaskPlanArguments $updateTaskPlanArguments, ?TaskPlanUpdateContext $taskPlanUpdateContext = null): TaskPlanUpdateOutcome
    {
        $taskPlanUpdateContext ??= TaskPlanUpdateContext::forTurn(-1);
        if (!$this->hasItems()) {
            return self::createFromUpdate($updateTaskPlanArguments, $taskPlanUpdateContext);
        }
        $taskPlanUpdateRejections = $this->checkUpdate($updateTaskPlanArguments, $taskPlanUpdateContext);
        if ($taskPlanUpdateRejections->hasRejections()) {
            return TaskPlanUpdateOutcome::rejected($taskPlanUpdateRejections);
        }
        $updatedTaskPlan = $this->buildDeepCopy();
        $taskPlanUpdateOutcome = TaskPlanUpdateApplication::applyToPlan($updatedTaskPlan, $updateTaskPlanArguments, $taskPlanUpdateContext);
        if ($updatedTaskPlan->hasSameItemsAs($this)) {
            return TaskPlanUpdateOutcome::unchanged($taskPlanUpdateOutcome->feedback, $taskPlanUpdateOutcome->hasGateRefusedStatus);
        }
        $updatedTaskPlan->markUpdatedInTurn($taskPlanUpdateContext->currentTurnInitiatorMessageIndex);
        $taskPlanUpdateOutcome->taskPlan = $updatedTaskPlan;
        return $taskPlanUpdateOutcome;
    }

    /**
     * Create a plan from a keyed `update_task_plan` call when no plan stands: checked like any call —
     * every item must be new (a key names nothing yet, R6) — then every item minted in pre-order (`1`, `1.1`, `1.2`, `2`
     * …) and the statuses the call sent applied as transitions from open.
     */
    public static function createFromUpdate(UpdateTaskPlanArguments $updateTaskPlanArguments, ?TaskPlanUpdateContext $taskPlanUpdateContext = null): TaskPlanUpdateOutcome
    {
        $taskPlanUpdateContext ??= TaskPlanUpdateContext::forTurn(-1);
        $createdTaskPlan = new static();
        $taskPlanUpdateRejections = $createdTaskPlan->checkUpdate($updateTaskPlanArguments, $taskPlanUpdateContext);
        if ($taskPlanUpdateRejections->hasRejections()) {
            return TaskPlanUpdateOutcome::rejected($taskPlanUpdateRejections);
        }
        $createdTaskPlan->items = TaskPlanItems::newInstance();
        $taskPlanUpdateOutcome = TaskPlanUpdateApplication::applyToPlan($createdTaskPlan, $updateTaskPlanArguments, $taskPlanUpdateContext);
        $createdTaskPlan->markUpdatedInTurn($taskPlanUpdateContext->currentTurnInitiatorMessageIndex);
        $taskPlanUpdateOutcome->isCreation = true;
        $taskPlanUpdateOutcome->taskPlan = $createdTaskPlan;
        return $taskPlanUpdateOutcome;
    }

    /**
     * Apply a `set_task_status` call's updates onto this plan IN PLACE (the former 762-line tool
     * method): the address pre-pass first — one address that is not a key of the plan rejects the whole call, nothing
     * applies ({@see SetTaskStatusAddressCheck}); then per update, in the order sent: an unrecognized status
     * is named, the echo of this turn's platform closing, the cascade cap ({@see isGiveUpCascade()}), the
     * information lines, then the item's gate groups and its transition ({@see TaskPlanItemTickGate}). The call-level
     * cascade lines follow every per-update line; the plan is stamped as touched / resolved in the turn. Every resolution
     * failure, normalization and omission is named in the feedback — never silent. The COLUMN persist stays with the
     * caller, like every plan write.
     *
     * The live call ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService::applySetTaskStatusCall()})
     * and the supervisor replay ({@see TaskPlanEvidence::forReplay()}) run this same application. The purpose's criteria
     * are evaluated LAZILY: `$taskPlanDoneCriteria` answers a key only when a gate reaches an item that
     * names it, over `$taskPlanDoneCriterionInputs`, built once for the call over this plan's live items — a criterion
     * that reads the plan sees it as the updates before mutated it. The replay passes an empty registry (no criterion
     * answers) and inputs without a channel reader. An `update_task_plan` call's status transitions run the same
     * application without the stamps ({@see applyStatusUpdatesWithoutTheTurnStamps()}).
     */
    public function applyStatusUpdates(
        SetTaskStatusArguments $setTaskStatusArguments,
        TaskPlanEvidence $taskPlanEvidence,
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs
    ): AgentTaskPlanTickOutcome {
        $agentTaskPlanTickOutcome = $this->applyStatusUpdatesWithoutTheTurnStamps($setTaskStatusArguments, $taskPlanEvidence, $taskPlanDoneCriteria, $taskPlanDoneCriterionInputs);
        $currentTurnInitiatorMessageIndex = $taskPlanEvidence->currentTurnInitiatorMessageIndex;
        if ($agentTaskPlanTickOutcome->touchesThePlan()) {
            $this->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
        }
        if ($agentTaskPlanTickOutcome->resolvesAnItem()) {
            $this->markResolvedInTurn($currentTurnInitiatorMessageIndex);
        }
        return $agentTaskPlanTickOutcome;
    }

    /**
     * {@see applyStatusUpdates()} without the plan's turn stamps: the address pre-pass, every update through its gates and
     * its transition, the call-level cascade lines. The caller stamps — `set_task_status` as touched / resolved from the
     * outcome, `update_task_plan` the resolution only for an item that stood before the call
     * ({@see AgentTaskPlanTickOutcome::$resolvingTaskAddresses}) and the touch by its own rule.
     */
    public function applyStatusUpdatesWithoutTheTurnStamps(
        SetTaskStatusArguments $setTaskStatusArguments,
        TaskPlanEvidence $taskPlanEvidence,
        TaskPlanDoneCriteria $taskPlanDoneCriteria,
        TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs
    ): AgentTaskPlanTickOutcome {
        if (!$setTaskStatusArguments->hasUpdatesList) {
            return AgentTaskPlanTickOutcome::withoutUpdatesList();
        }
        $addressCheck = SetTaskStatusAddressCheck::ofArguments($setTaskStatusArguments, $this->items);
        if ($addressCheck->rejectsTheCall()) {
            return AgentTaskPlanTickOutcome::rejectedForItsAddresses($addressCheck->renderRejection($this->items));
        }
        // read once, before any update — as the call has always read them
        $entitlementLockedToolNames = $taskPlanEvidence->getEntitlementLockedToolNames();
        $notesBlockedUpdatesAsCascade = self::isGiveUpCascade($setTaskStatusArguments, $addressCheck, TaskPlanItem::STATUS_BLOCKED);
        $notesNotApplicableUpdatesAsCascade = self::isGiveUpCascade($setTaskStatusArguments, $addressCheck, TaskPlanItem::STATUS_NOT_APPLICABLE);
        $agentTaskPlanTickOutcome = AgentTaskPlanTickOutcome::forUpdateCount($setTaskStatusArguments->countUpdates());
        foreach ($setTaskStatusArguments->updates->getElements() as $setTaskStatusArgumentsUpdate) {
            // the pre-pass resolved every update to exactly one item; the address IS that item's key
            $taskPlanItem = $addressCheck->getItemOfUpdate($setTaskStatusArgumentsUpdate->position);
            if (!$setTaskStatusArgumentsUpdate->sentAsArray || $taskPlanItem === null) {
                continue;
            }
            $taskAddress = $setTaskStatusArgumentsUpdate->getTaskAddressAsSent();
            $status = $setTaskStatusArgumentsUpdate->status;
            if ($status === null) {
                $agentTaskPlanTickOutcome->addFeedbackLines(["Task '{$taskAddress}': status '{$setTaskStatusArgumentsUpdate->rawStatus}' not recognized — use open|done|blocked|not_applicable; not applied."]);
                continue;
            }
            $taskPlanItemTickGate = TaskPlanItemTickGate::forUpdate(
                $taskPlanItem,
                $setTaskStatusArgumentsUpdate,
                $taskPlanEvidence,
                $entitlementLockedToolNames,
                $taskPlanDoneCriteria,
                $taskPlanDoneCriterionInputs
            );
            $echoVerdict = $taskPlanItemTickGate->checkEchoOfThisTurnsPlatformClosing();
            if ($echoVerdict !== null) {
                $agentTaskPlanTickOutcome->addVerdict($echoVerdict);
                continue;
            }
            // the cascade before the per-update information lines — a 20-update sweep produces ONE line, not 20
            if (($status === TaskPlanItem::STATUS_BLOCKED && $notesBlockedUpdatesAsCascade)
                || ($status === TaskPlanItem::STATUS_NOT_APPLICABLE && $notesNotApplicableUpdatesAsCascade)
            ) {
                $agentTaskPlanTickOutcome->noteCascadeUpdate($status, $taskAddress);
                continue;
            }
            $agentTaskPlanTickOutcome->addFeedbackLines($taskPlanItemTickGate->buildStatusInformationLines());
            $agentTaskPlanTickOutcome->addVerdict(
                $taskPlanItemTickGate->checkAgainstTheRecordCriterion()
                ?? $taskPlanItemTickGate->checkDoneEvidence()
                ?? $taskPlanItemTickGate->checkNotApplicableEvidence()
                ?? $taskPlanItemTickGate->checkBlockedEvidence()
                ?? $taskPlanItemTickGate->applyTick($this->items, $agentTaskPlanTickOutcome->hasLecturedAlreadyInStatus()),
                $taskAddress
            );
        }
        $agentTaskPlanTickOutcome->addCascadeLines();
        return $agentTaskPlanTickOutcome;
    }

    /**
     * The CASCADE cap of one call: after one obstructed step, blocking — or setting not_applicable — EVERY
     * downstream item in one sweep is a give-up, not N real verdicts (eight items "does not apply yet" at turn
     * one). Counted are DISTINCT items the call asks to move into `$status`; above
     * {@see MAX_BLOCKED_UPDATES_PER_CALL} every such update of the call is NOTED, not applied.
     */
    protected static function isGiveUpCascade(SetTaskStatusArguments $setTaskStatusArguments, SetTaskStatusAddressCheck $addressCheck, string $status): bool
    {
        $targetItemObjectIds = [];
        foreach ($setTaskStatusArguments->updates->getElements() as $setTaskStatusArgumentsUpdate) {
            if (!$setTaskStatusArgumentsUpdate->sentAsArray || $setTaskStatusArgumentsUpdate->status !== $status) {
                continue;
            }
            $targetItem = $addressCheck->getItemOfUpdate($setTaskStatusArgumentsUpdate->position);
            if ($targetItem !== null) {
                $targetItemObjectIds[spl_object_id($targetItem)] = true;
            }
        }
        return count($targetItemObjectIds) > self::MAX_BLOCKED_UPDATES_PER_CALL;
    }

    /** Whether the plan still has OPEN work anywhere in the tree ({@see TaskPlanItems::hasOpenItems()}). */
    public function hasOpenItems(): bool
    {
        return $this->items !== null && $this->items->hasOpenItems();
    }

    /**
     * The `taskPlanOpenAt` marker a write of this plan stores on the conversation row: `$now` while an
     * item is open anywhere in the tree, null otherwise.
     */
    public function getOpenMarkerAt(DateTime $now): ?string
    {
        return $this->hasOpenItems() ? $now->format('Y-m-d H:i:s') : null;
    }

    /**
     * The `taskPlanRetryableAt` marker a write of this plan stores on the conversation row: `$now` while a
     * blocked item may be retried, null otherwise.
     */
    public function getRetryableMarkerAt(DateTime $now): ?string
    {
        return ($this->items?->hasRetryableBlockedItems() ?? false) ? $now->format('Y-m-d H:i:s') : null;
    }

    /** A plan with items and no open item anywhere — the retirement predicate. */
    public function isFinished(): bool
    {
        return $this->items !== null && $this->items->count() > 0 && !$this->items->hasOpenItems();
    }

    /**
     * Retire this plan as FINISHED: stamp when, give it a title and compose the outcome line the chat model reads
     * back later ("what did I finish?") — one entry per terminal leaf with its status note. Pure; the caller
     * moves the plan into the finished set and persists.
     */
    public function retire(int $atMessageIndex, string $completedAt, ?string $title = null): void
    {
        $this->completedAtMessageIndex = $atMessageIndex;
        $this->completedAt = $completedAt;
        $this->title = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 120) : ($this->title ?? $this->deriveTitleFromItems());
        $this->outcomeSummary = $this->buildOutcomeSummary();
    }

    /**
     * Whether this FINISHED plan reopens for the owner turn whose initiator message has $ownerTurnInitiatorMessageIndex
     * (option A narrow): the owner turn RIGHT BEFORE it closed the plan — the
     * plan was written or an item resolved in that turn ({@see $updatedAtTurnInitiatorMessageIndex},
     * {@see $resolvedAtTurnInitiatorMessageIndex}) and the plan retired in it ({@see $completedAtMessageIndex}) — and the
     * new turn's message is the owner's very next row after the retirement. Structural only: message indexes, turn
     * initiators and owner rows, never the text. A plan reopened and closed again without any write keeps its earlier
     * turn stamps, so it never reopens a second time.
     */
    public function reopensForOwnerTurn(AIConversationMessages $conversationMessages, int $ownerTurnInitiatorMessageIndex): bool
    {
        $completedAtMessageIndex = $this->completedAtMessageIndex;
        if (!$this->isFinished() || $completedAtMessageIndex === null || $completedAtMessageIndex >= $ownerTurnInitiatorMessageIndex) {
            return false;
        }
        $previousTurnInitiatorMessage = null;
        foreach ($conversationMessages->getElements() as $conversationMessage) {
            $messageIndex = $conversationMessage->indexInConversation ?? null;
            if ($messageIndex === null || $messageIndex >= $ownerTurnInitiatorMessageIndex) {
                continue;
            }
            if ($messageIndex > $completedAtMessageIndex && $conversationMessage->isOwnerAuthoredInbound()) {
                return false; // an owner row came between the retirement and this turn's message
            }
            if (AIConversationMessages::isTurnInitiatorMessage($conversationMessage)
                && ($previousTurnInitiatorMessage === null || $messageIndex > (int)$previousTurnInitiatorMessage->indexInConversation)
            ) {
                $previousTurnInitiatorMessage = $conversationMessage;
            }
        }
        if ($previousTurnInitiatorMessage === null || !$previousTurnInitiatorMessage->isOwnerAuthoredInbound()) {
            return false; // the turn right before was not the owner's
        }
        $previousTurnInitiatorMessageIndex = (int)$previousTurnInitiatorMessage->indexInConversation;
        return $completedAtMessageIndex >= $previousTurnInitiatorMessageIndex
            && ($this->wasUpdatedInTurn($previousTurnInitiatorMessageIndex) || $this->wasResolvedInTurn($previousTurnInitiatorMessageIndex));
    }

    /**
     * Take back the retirement stamps ({@see retire()}): the plan is the active one again. The title stays — the plan was
     * made for the same request. Pure; the caller moves the plan out of the finished set and persists.
     */
    public function reopen(): void
    {
        $this->completedAtMessageIndex = null;
        $this->completedAt = null;
        $this->outcomeSummary = null;
    }

    /** The top-level item keys, in plan order. @return string[] */
    public function getTopLevelItemKeys(): array
    {
        $topLevelItemKeys = [];
        foreach ($this->items?->getElements() ?? [] as $taskPlanItem) {
            if ($taskPlanItem->key !== null && $taskPlanItem->key !== '') {
                $topLevelItemKeys[] = $taskPlanItem->key;
            }
        }
        return $topLevelItemKeys;
    }

    /** The first top-level item's text as a fallback title. */
    protected function deriveTitleFromItems(): ?string
    {
        $firstItem = $this->items?->first();
        return $firstItem !== null ? mb_substr($firstItem->text, 0, 120) : null;
    }

    /**
     * The outcome per LEAF (containers derive their state), status-labelled, capped at {@see OUTCOME_SUMMARY_MAX_LENGTH}:
     * `done: Attribut gesetzt — auf ja gesetzt · blocked: Leistung ergänzen — Tool lehnte ab`.
     */
    public function buildOutcomeSummary(): string
    {
        if ($this->items === null) {
            return '';
        }
        $entries = [];
        foreach ($this->items->buildItemAddresses()->getLeaves() as $taskPlanItemAddress) {
            $item = $taskPlanItemAddress->item;
            $label = match ($item->status) {
                TaskPlanItem::STATUS_BLOCKED => 'blocked',
                TaskPlanItem::STATUS_NOT_APPLICABLE => 'n/a',
                TaskPlanItem::STATUS_DONE => 'done',
                default => 'open',
            };
            $note = trim((string)($item->statusNote ?? ''));
            $entries[] = $label . ': ' . $item->text . ($note !== '' ? ' — ' . $note : '');
        }
        return mb_substr(implode(' · ', $entries), 0, self::OUTCOME_SUMMARY_MAX_LENGTH);
    }

    /** The outcome line is capped so twenty finished plans never weigh on the column or the tool result. */
    public const int OUTCOME_SUMMARY_MAX_LENGTH = 1200;

    /**
     * The one-line entry of a FINISHED plan for the chat model's list (`get_task_plans` without a number) and the
     * inspector: `#2 · finished 2026-09-26 10:12 · "Move the booking to …" — 3 items: 2 done, 1 blocked`.
     */
    public function toFinishedLine(int $number): string
    {
        $statusCounts = $this->items?->countItemsByStatus() ?? new TaskPlanItemStatusCounts();
        $itemCount = $this->items?->countAllItems() ?? 0;
        return sprintf(
            '#%d · finished %s · "%s" — %d item%s: %d done, %d blocked, %d open',
            $number,
            (string)($this->completedAt ?? '?'),
            (string)($this->title ?? 'untitled plan'),
            $itemCount,
            $itemCount === 1 ? '' : 's',
            $statusCounts->done,
            $statusCounts->blocked,
            $statusCounts->open
        );
    }

    /** The full item tree of a finished plan (the tail-hint render without its maintenance header) plus the outcome line. */
    public function toFinishedDetailText(int $number): string
    {
        $renderLines = $this->items?->buildTailHintLines(withNextMarker: false) ?? []; // a finished plan has no "next"
        return $this->toFinishedLine($number) . "\n" . implode("\n", $renderLines);
    }

    /** Stamp a plan write under the turn whose initiator message has $turnInitiatorMessageIndex ({@see $updatedAtTurnInitiatorMessageIndex}). */
    public function markUpdatedInTurn(int $turnInitiatorMessageIndex): void
    {
        $this->updatedAtTurnInitiatorMessageIndex = $turnInitiatorMessageIndex;
    }

    /** Stamp an item's transition into a terminal status under that turn ({@see $resolvedAtTurnInitiatorMessageIndex}). */
    public function markResolvedInTurn(int $turnInitiatorMessageIndex): void
    {
        $this->resolvedAtTurnInitiatorMessageIndex = $turnInitiatorMessageIndex;
    }

    /** The plan was written in the turn whose initiator message has $turnInitiatorMessageIndex. */
    public function wasUpdatedInTurn(int $turnInitiatorMessageIndex): bool
    {
        return $this->updatedAtTurnInitiatorMessageIndex === $turnInitiatorMessageIndex;
    }

    /** An item of the plan reached a terminal status in that turn. */
    public function wasResolvedInTurn(int $turnInitiatorMessageIndex): bool
    {
        return $this->resolvedAtTurnInitiatorMessageIndex === $turnInitiatorMessageIndex;
    }

    /** Identity inside the finished set: the creation/retirement stamps plus the item tree (two retirements of one plan never collide). */
    public function uniqueKey(): string
    {
        return 'plan:' . ($this->createdAtMessageIndex ?? 'x') . ':' . ($this->completedAtMessageIndex ?? 'x') . ':'
            . md5((string)json_encode($this->items?->toRepositoryArray() ?? []));
    }

    /**
     * The open items as tree PATHS ("parent > child") — the bounce signal's and the run report's unambiguous
     * naming of the remaining work ({@see TaskPlanItems::getOpenItemPathTexts()}).
     *
     * @return string[]
     */
    public function getOpenItemPathTexts(): array
    {
        return $this->items?->getOpenItemPathTexts() ?? [];
    }

    /**
     * The transient wire-TAIL render: the model's per-step view of its current plan — a multi-line
     * indented tree, `← next` on the first open actionable leaf. Volatile, never persisted, appended after the
     * recent-reads hint and BEFORE the reply-language cue. Empty string when the plan has no items.
     */
    /**
     * The JUDGE-ONLY render: the same tree with the provenance suffixes
     * ({@see TaskPlanItem::toJudgeLine()}) — the eval trace's final plan artifact. Never the agent's view.
     */
    public function toJudgeText(): string
    {
        if ($this->items === null || $this->items->count() === 0) {
            return '';
        }
        return implode("\n", $this->items->buildJudgeLines());
    }

    /**
     * What the result of a completed work tool says about the plan, built AFTER the platform looked at the open
     * items: the items the platform closed from the record are named with their verdict, the items whose tick the
     * model owes are named for the tick. Null when the call changed nothing the model has to know.
     *
     * Every item is named by its key.
     *
     * @param string[] $keysTickableByModel {@see TaskPlanItems::getItemNumberPathsTickableAfterToolCall()}
     */
    public static function buildNoteAfterCompletedToolCall(TaskPlanLeafClosings $closedByPlatform, array $keysTickableByModel): ?string
    {
        $sentences = [];
        if ($closedByPlatform->hasClosings()) {
            $closedItems = [];
            foreach ($closedByPlatform->getStatusByNumberPath() as $itemKey => $status) {
                $closedItems[] = $itemKey . ' (' . $status . ')';
            }
            $sentences[] = 'PLAN: the platform closed plan ' . (count($closedItems) === 1 ? 'item ' : 'items ')
                . implode(', ', $closedItems) . ' from the record — do NOT tick '
                . (count($closedItems) === 1 ? 'it' : 'them') . '.';
        }
        if ($keysTickableByModel !== []) {
            $sentences[] = 'GATE SATISFIED: this call is the done-evidence of plan '
                . (count($keysTickableByModel) === 1 ? 'item ' : 'items ') . implode(', ', $keysTickableByModel)
                . ' — tick ' . (count($keysTickableByModel) === 1 ? 'it' : 'them')
                . ' done via set_task_status NOW (statusNote: what was persisted).';
        }
        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * The turn-start note for the leaves the platform closed at the end of the previous turn:
     * each leaf, named by its key, with its verdict and its statusNote, then the one rule — never tick them again.
     */
    public static function buildNoteForLeavesClosedAtTurnEnd(TaskPlanLeafClosings $closedAtTurnEnd, ?TaskPlanItems $taskPlanItems, bool $atTurnStart = false): ?string
    {
        if (!$closedAtTurnEnd->hasClosings()) {
            return null;
        }
        $closedLines = [];
        foreach ($closedAtTurnEnd->getStatusByNumberPath() as $itemKey => $status) {
            $statusNote = $taskPlanItems?->getItemByKey((string)$itemKey)?->statusNote;
            $closedLines[] = '- ' . $itemKey . ' → ' . $status . ($statusNote !== null && $statusNote !== '' ? ' (' . $statusNote . ')' : '');
        }
        return 'PLAN: ' . ($atTurnStart ? 'the owner\'s answer just closed' : 'at the end of your last turn the platform closed') . " these plan items from the record:\n"
            . implode("\n", $closedLines)
            . "\nThey show as terminal in your task plan — do NOT tick them again; continue with the next open item.";
    }

    /**
     * The plan as the model reads it in EVERY call: the header with the one tick rule set
     * ({@see buildTickRules()}) and the compact tree ({@see TaskPlanItems::buildCompactTailHintLines()}) — key, status and
     * title per item, the full text only for the `← next` item. Empty string when the plan has no items.
     */
    public function toTailHintText(): string
    {
        if ($this->items === null || $this->items->count() === 0) {
            return '';
        }
        return 'Your task plan (the keys below address the items). ' . static::buildTickRules() . "\n"
            . implode("\n", $this->items->buildCompactTailHintLines());
    }

    /** The decision model's plan line width ({@see toDecisionPlanText()}): the act and its skill name fit, the rule prose behind them does not. */
    public const int DECISION_PLAN_LINE_CHARS = 120;
    /** The `← next` item's width: the item the owner's message answers keeps enough of its text to show what it still asks of the owner. */
    public const int DECISION_PLAN_NEXT_LINE_CHARS = 400;

    /**
     * The plan as the DECISION MODEL reads it (a sub-skill pass can only know that the next act
     * begins when it sees the plan): the tail-hint lines without the header sentence, every line clipped to
     * {@see DECISION_PLAN_LINE_CHARS} — the status mark, the item key, the act title and the "(load Workshop.X)" it
     * names survive, the rule prose and a long closing note do not; the `← next` marker is kept behind the clip, and its
     * line keeps {@see DECISION_PLAN_NEXT_LINE_CHARS} — whether that item still comes back to the owner (a widget to
     * confirm, a further question) stands in its text.
     */
    public function toDecisionPlanText(int $maxLineChars = self::DECISION_PLAN_LINE_CHARS, int $maxNextLineChars = self::DECISION_PLAN_NEXT_LINE_CHARS): string
    {
        if ($this->items === null || $this->items->count() === 0) {
            return '';
        }
        $renderLines = $this->items->buildTailHintLines();
        $clippedLines = [];
        foreach ($renderLines as $renderLine) {
            $nextMarker = '';
            if (str_ends_with($renderLine, ' ← next')) {
                $nextMarker = ' ← next';
                $renderLine = mb_substr($renderLine, 0, -mb_strlen($nextMarker));
            }
            $lineChars = $nextMarker !== '' ? max($maxLineChars, $maxNextLineChars) : $maxLineChars;
            if (mb_strlen($renderLine) > $lineChars) {
                $renderLine = rtrim(mb_substr($renderLine, 0, $lineChars - 1)) . '…';
            }
            $clippedLines[] = $renderLine . $nextMarker;
        }
        return implode("\n", $clippedLines);
    }

    /**
     * The ACTS AHEAD for the decision model: the open items in plan order whose skill is not
     * resident yet — one line per skill, at the first item that names it ("item 4 → Workshop.Slots"). The platform
     * walks the plan, the decision model judges only whether the owner's message closes the current item so that
     * the first act ahead begins in this turn. Empty when every act's skill is loaded or the plan names none.
     *
     * @param string[] $residentSkillNames the skills loaded in the conversation (root + sub-skills)
     * @return string[]
     */
    public function toActsAheadLines(array $residentSkillNames): array
    {
        if ($this->items === null || $this->items->count() === 0) {
            return [];
        }
        $lines = [];
        $listedSkillNames = [];
        foreach ($this->items->collectOwedOpenLeafSkillNamesByNumberPath() as $itemKey => $skillNames) {
            foreach ($skillNames as $skillName) {
                if (in_array($skillName, $residentSkillNames, true) || in_array($skillName, $listedSkillNames, true)) {
                    continue;
                }
                $listedSkillNames[] = $skillName;
                $lines[] = 'item ' . $itemKey . ' → ' . $skillName;
            }
        }
        return $lines;
    }

    /**
     * A copy that shares no object with this plan — through the repository round-trip, so every later in-place
     * mutation of the live plan leaves the copy as it was (a briefing snapshot, a per-message trace).
     */
    public function buildDeepCopy(): self
    {
        return self::fromRepositoryArray($this->mapToRepository());
    }

    /**
     * The item trees persist identically (statuses, notes, declarations, records) — the plan-level stamps aside. Both
     * sides are compared as READ back, so an item built in memory without a key compares by the key the read gives it
     * and a no-op resend of such a plan stays a no-op.
     */
    public function hasSameItemsAs(TaskPlan $otherTaskPlan): bool
    {
        return ($this->buildDeepCopy()->items?->toRepositoryArray() ?? []) === ($otherTaskPlan->buildDeepCopy()->items?->toRepositoryArray() ?? []);
    }

    /** This plan persists to exactly $storedTaskPlanArray, plan-level keys included (null = nothing stored). */
    public function hasSameStoredStateAs(?array $storedTaskPlanArray): bool
    {
        return (array)$this->mapToRepository() === $storedTaskPlanArray;
    }

    /** A plan rebuilt from its persisted array ({@see mapToRepository()}). */
    public static function fromRepositoryArray(mixed $repoArray): static
    {
        $taskPlan = new static();
        $taskPlan->mapFromRepository($repoArray);
        return $taskPlan;
    }

    /**
     * @return array<string, mixed> EVERY key explicitly present; the item tree as plain nested arrays
     * ({@see TaskPlanItems::toRepositoryArray()} — the RFC-7396 complete-slice rule).
     */
    public function mapToRepository(): mixed
    {
        return [
            'items' => $this->items?->toRepositoryArray() ?? [],
            'updatedAtTurnInitiatorMessageIndex' => $this->updatedAtTurnInitiatorMessageIndex,
            'resolvedAtTurnInitiatorMessageIndex' => $this->resolvedAtTurnInitiatorMessageIndex,
            'title' => $this->title,
            'createdAtMessageIndex' => $this->createdAtMessageIndex,
            'completedAtMessageIndex' => $this->completedAtMessageIndex,
            'completedAt' => $this->completedAt,
            'outcomeSummary' => $this->outcomeSummary,
            'platformClosedAtTurnEndNote' => $this->platformClosedAtTurnEndNote,
            'highestItemKeyNumber' => $this->getHighestItemKeyNumber(),
        ];
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        $repoArray = is_array($repoObject) ? $repoObject : [];
        // every reader of a stored plan comes through here — a keyless tree gets its number paths as keys,
        // a keyed one keeps its keys and lists what is irregular
        $this->items = TaskPlanItems::buildFromRepositoryArrayAsStored(is_array($repoArray['items'] ?? null) ? $repoArray['items'] : []);
        $keyIntegrityViolations = new TaskPlanKeyIntegrityViolations();
        $this->highestItemKeyNumber = TaskPlanStoredKeyPreservation::deriveOrPreserveKeys(
            $this->items,
            max(0, self::readRepositoryInt($repoArray, 'highestItemKeyNumber') ?? 0),
            $keyIntegrityViolations
        );
        $this->keyIntegrityViolations = $keyIntegrityViolations->hasViolations() ? $keyIntegrityViolations : null;
        $this->updatedAtTurnInitiatorMessageIndex = isset($repoArray['updatedAtTurnInitiatorMessageIndex'])
            ? (int)$repoArray['updatedAtTurnInitiatorMessageIndex']
            : null;
        $this->resolvedAtTurnInitiatorMessageIndex = isset($repoArray['resolvedAtTurnInitiatorMessageIndex'])
            ? (int)$repoArray['resolvedAtTurnInitiatorMessageIndex']
            : null;
        $this->title = self::readRepositoryString($repoArray, 'title');
        $this->createdAtMessageIndex = self::readRepositoryInt($repoArray, 'createdAtMessageIndex');
        $this->completedAtMessageIndex = self::readRepositoryInt($repoArray, 'completedAtMessageIndex');
        $this->completedAt = self::readRepositoryString($repoArray, 'completedAt');
        $this->outcomeSummary = self::readRepositoryString($repoArray, 'outcomeSummary');
        $this->platformClosedAtTurnEndNote = self::readRepositoryString($repoArray, 'platformClosedAtTurnEndNote');
    }

    /** A trimmed non-empty string of the persisted plan, else null. @param array<array-key, mixed> $repoArray */
    protected static function readRepositoryString(array $repoArray, string $key): ?string
    {
        $value = trim((string)($repoArray[$key] ?? ''));
        return $value !== '' ? $value : null;
    }

    /** A numeric value of the persisted plan as int, else null. @param array<array-key, mixed> $repoArray */
    protected static function readRepositoryInt(array $repoArray, string $key): ?int
    {
        return isset($repoArray[$key]) && is_numeric($repoArray[$key]) ? (int)$repoArray[$key] : null;
    }
}
