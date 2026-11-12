<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanUpdateContext;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteria;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\TaskPlanDoneCriterionInputsWithFixedChannelStates;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The status part of an `update_task_plan` RESEND over the gate table of `set_task_status`
 * ({@see TaskPlanGateTableTestCase}): every cell's tick is turned into the resend that asks the same — every standing
 * item named by its key, the addressed item carrying the tick's status, note and flags
 * ({@see buildResendItems()}) — and applied the way the live path applies it ({@see TaskPlan::applyUpdate()} on the
 * plan as stored, with the evidence, criteria and channel reader the tick gets; the record service's stamp on a call that
 * changed nothing).
 *
 * Two proofs:
 * - the observed outcome per cell is pinned in `Fixtures/UpdateTaskPlanResendGateTableCharacterizationTest.json`: the
 *   call's outcome, its feedback lines (bytes, in order), the two plan stamps, every changed persisted field of every
 *   item (before → after) and the criterion keys and channels the purpose was asked. Recorded first on the code where
 *   the resend had its own gate copy, then re-recorded after the next behaviour change; the difference is that
 *   change, a failing cell after a later commit is a BEHAVIOUR CHANGE to be named;
 * - ONE EXPECTATION SET: every cell equals the `set_task_status` golden of
 *   {@see SetTaskStatusGateTableCharacterizationTest} — feedback, item fields, stamps, the purpose's reads — or differs by
 *   exactly one rule the plan states ({@see classifyResendAgainstTheTick()}, the RULE_* constants), each rule's own
 *   assertions hold, and the number of cells per rule is pinned.
 */
class UpdateTaskPlanResendGateTableCharacterizationTest extends TaskPlanGateTableTestCase
{
    protected const string GOLDEN_FILE = __DIR__ . '/Fixtures/UpdateTaskPlanResendGateTableCharacterizationTest.json';

    /** The call was rejected as a whole — nothing changed. */
    public const string OUTCOME_REJECTED = 'rejected';

    /** The call left the items exactly as they were, and no status it sent was refused. */
    public const string OUTCOME_UNCHANGED = 'unchanged';

    /** The call left the items exactly as they were because a status it sent was refused. */
    public const string OUTCOME_UNCHANGED_STATUS_REFUSED = 'unchangedStatusRefused';

    /** The call changed the plan. */
    public const string OUTCOME_APPLIED = 'applied';

    /** The resend meets the tick's expectation byte for byte. */
    public const string RULE_IDENTICAL = 'identical';

    /**
     * The record service: every `update_task_plan` call over a standing plan is the turn's plan touch
     * — rejected, unchanged or applied; a tick stamps the touch only when an update was applied or noted.
     */
    public const string RULE_PLAN_TOUCH = 'planTouch';

    /**
     * An address that names no item rejects the whole call in both tools, each with its own block —
     * the R-lines of `update_task_plan` (R3 / R6 / R10 / R19), the address lines of `set_task_status`.
     */
    public const string RULE_ADDRESS = 'address';

    /** An `update_task_plan` call names each key once — a second status for one item rejects the call. */
    public const string RULE_DUPLICATE_KEY = 'duplicateKey';

    /** No `updates` list: the resend sends no status, so nothing is judged and nothing is named. */
    public const string RULE_NO_STATUS_ROW = 'noStatusRow';

    /**
     * The stored status sent again is no transition — no gate is asked (no echo, no ALREADY line,
     * no transient-obstacle re-open), the note refreshes unless the item was pre-resolved at seed, the flags are taken on a
     * blocked item; such a row is no tick, so it neither counts toward the cascade cap nor clears a note on open → open.
     */
    public const string RULE_SAME_STATUS = 'sameStatus';

    /**
     * The no-op path: a call that leaves every item as it is changes no plan stamp but the touch (no cell of
     * the table reaches it since the judgement park left).
     */
    public const string RULE_UNCHANGED_CALL_KEEPS_THE_STAMPS = 'unchangedCallKeepsTheStamps';

    /** A difference no rule explains — a defect. */
    public const string RULE_UNEXPLAINED = 'unexplained';

    /** @var array<string, int> how many cells each rule sets apart */
    protected const array CELL_COUNTS_PER_RULE = [
        self::RULE_ADDRESS => 42,
        self::RULE_DUPLICATE_KEY => 2,
        self::RULE_IDENTICAL => 299, // the G27 row and the never-gating / parked-note cells left the table; later the G20 row (notApplicableForbiddenAfterWidget) left it
        self::RULE_NO_STATUS_ROW => 2,
        self::RULE_PLAN_TOUCH => 14,
        self::RULE_SAME_STATUS => 25,
    ];

    /** The case's criteria (null = the case names no record answer) — they record the keys asked. */
    protected ?TaskPlanDoneCriteriaWithFixedVerdicts $recordAnswers = null;

    /** The case's channel reader (null = none) — it records the channels asked. */
    protected ?TaskPlanChannelConnectionStatesFixture $channelConnectionStates = null;

    /** @var array<string, mixed>|null the golden outcomes keyed by case name */
    protected static ?array $goldenOutcomesByCaseName = null;

    #[DataProvider('provideGateTableCases')]
    public function testGateTable(string $rowName, string $requestedStatus, string $environmentName): void
    {
        $case = self::buildGateTableCase(self::provideGateTableRows()[$rowName], $requestedStatus, $environmentName);

        self::assertSame($this->findGoldenOutcome($rowName . ' | ' . $requestedStatus . ' | ' . $environmentName), $this->runResendCase($case));
    }

    #[DataProvider('provideVariantCases')]
    public function testVariant(array $case, string $expectedLabel): void
    {
        self::assertSame($this->findGoldenOutcome($this->dataName()), $this->runResendCase($case));
    }

    /** Every golden entry belongs to a case of the two providers — a removed case must take its golden with it. */
    public function testTheGoldenHoldsExactlyTheCasesOfTheProviders(): void
    {
        $caseNames = array_merge(array_keys(self::provideGateTableCases()), array_keys(self::provideVariantCases()));
        $goldenCaseNames = array_keys(self::loadGoldenOutcomes());
        sort($caseNames);
        sort($goldenCaseNames);
        self::assertSame($caseNames, $goldenCaseNames);
    }

    /** @return array<string, array{string, array<string, mixed>}> every cell and every variant: its name and its case */
    public static function provideEveryCase(): array
    {
        $everyCase = [];
        foreach (self::provideGateTableCases() as $caseName => [$rowName, $requestedStatus, $environmentName]) {
            $everyCase[$caseName] = [$caseName, self::buildGateTableCase(self::provideGateTableRows()[$rowName], $requestedStatus, $environmentName)];
        }
        foreach (self::provideVariantCases() as $caseName => [$case]) {
            $everyCase[$caseName] = [$caseName, $case];
        }
        return $everyCase;
    }

    /**
     * ONE EXPECTATION SET: the resend equals the `set_task_status` golden of the cell, or one stated rule sets it apart
     * and that rule's own assertions hold.
     *
     * @param array<string, mixed> $case
     */
    #[DataProvider('provideEveryCase')]
    public function testTheResendMeetsTheTicksExpectationOrAStatedRule(string $caseName, array $case): void
    {
        $tickOutcome = SetTaskStatusGateTableCharacterizationTest::loadGoldenOutcomes()[$caseName];
        $resendOutcome = $this->runResendCase($case);
        $rule = $this->classifyResendAgainstTheTick($case, $tickOutcome, $resendOutcome);

        self::assertNotSame(self::RULE_UNEXPLAINED, $rule, 'a difference from set_task_status that no rule of the table explains');
        $nothingChanged = $resendOutcome['itemFieldChanges'] === [] && $resendOutcome['planStamps']['resolvedAtTurnInitiatorMessageIndex'] === null;
        match ($rule) {
            self::RULE_ADDRESS, self::RULE_DUPLICATE_KEY => self::assertTrue($resendOutcome['outcome'] === self::OUTCOME_REJECTED && $nothingChanged, 'rejected, nothing changed'),
            self::RULE_NO_STATUS_ROW => self::assertTrue($resendOutcome['outcome'] === self::OUTCOME_UNCHANGED && $resendOutcome['feedback'] === [] && $nothingChanged, 'nothing judged, nothing named'),
            self::RULE_SAME_STATUS => $this->assertSameStatusRowsWereNoTicks($case, $resendOutcome),
            self::RULE_UNCHANGED_CALL_KEEPS_THE_STAMPS => self::assertSame(self::OUTCOME_UNCHANGED, $resendOutcome['outcome']),
            default => self::assertSame(self::CURRENT_TURN_INITIATOR_INDEX, $resendOutcome['planStamps']['updatedAtTurnInitiatorMessageIndex'] ?? self::CURRENT_TURN_INITIATOR_INDEX),
        };
        if ($rule === self::RULE_DUPLICATE_KEY) {
            self::assertMatchesRegularExpression('/^Key "[^"]+" appears \d+ times/', implode("\n", $resendOutcome['feedback']));
        }
    }

    /** How many cells each rule sets apart — read from the two goldens, pinned. */
    public function testTheCellsEachRuleSetsApartArePinned(): void
    {
        $tickOutcomes = SetTaskStatusGateTableCharacterizationTest::loadGoldenOutcomes();
        $resendOutcomes = self::loadGoldenOutcomes();
        $cellCountsPerRule = [];
        foreach (self::provideEveryCase() as $caseName => [, $case]) {
            $rule = $this->classifyResendAgainstTheTick($case, $tickOutcomes[$caseName], $resendOutcomes[$caseName]);
            $cellCountsPerRule[$rule] = ($cellCountsPerRule[$rule] ?? 0) + 1;
        }
        ksort($cellCountsPerRule);

        self::assertSame(self::CELL_COUNTS_PER_RULE, $cellCountsPerRule);
    }

    /**
     * Which rule sets the resend apart from the tick's expectation of the cell: none (identical), the plan touch, or the
     * rule the case's shape names — no updates list, an address that names no item, a key sent twice, a status equal to
     * the stored one — else an unchanged call's stamps; anything else is unexplained.
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $tickOutcome
     * @param array<string, mixed> $resendOutcome
     */
    public function classifyResendAgainstTheTick(array $case, array $tickOutcome, array $resendOutcome): string
    {
        $differingFields = self::findDifferingFields($tickOutcome, $resendOutcome);
        if ($differingFields === []) {
            return self::RULE_IDENTICAL;
        }
        $rawUpdates = $case['arguments']['updates'] ?? null;
        if (!is_array($rawUpdates)) {
            return self::RULE_NO_STATUS_ROW;
        }
        if ($tickOutcome['reachedGate'] === 'ADDRESS') {
            return self::RULE_ADDRESS;
        }
        if (self::namesAnAddressTwice($rawUpdates)) {
            return self::RULE_DUPLICATE_KEY;
        }
        if ($this->findSameStatusKeys($case) !== []) {
            // a mixed cell: the same-status rows are no ticks, every transitioning row must still meet the tick
            return $this->findTransitioningKeysThatMissTheTick($case, $tickOutcome, $resendOutcome) === []
                ? self::RULE_SAME_STATUS
                : self::RULE_UNEXPLAINED;
        }
        if ($differingFields === ['updatedAt'] && $resendOutcome['planStamps']['updatedAtTurnInitiatorMessageIndex'] === (int)($case['initiator'] ?? self::CURRENT_TURN_INITIATOR_INDEX)) {
            return self::RULE_PLAN_TOUCH;
        }
        if (array_diff($differingFields, ['updatedAt', 'resolvedAt']) === [] && $resendOutcome['outcome'] === self::OUTCOME_UNCHANGED) {
            return self::RULE_UNCHANGED_CALL_KEEPS_THE_STAMPS;
        }
        return self::RULE_UNEXPLAINED;
    }

    /**
     * The fields of the observable outcome both tools share in which the resend differs from the tick: the feedback lines,
     * the item fields, each plan stamp, the purpose's reads.
     *
     * @param array<string, mixed> $tickOutcome
     * @param array<string, mixed> $resendOutcome
     * @return string[]
     */
    protected static function findDifferingFields(array $tickOutcome, array $resendOutcome): array
    {
        $differingFields = [];
        if ($tickOutcome['feedback'] !== $resendOutcome['feedback']) {
            $differingFields[] = 'feedback';
        }
        if ($tickOutcome['itemFieldChanges'] !== $resendOutcome['itemFieldChanges']) {
            $differingFields[] = 'itemFieldChanges';
        }
        foreach (['updatedAt' => 'updatedAtTurnInitiatorMessageIndex', 'resolvedAt' => 'resolvedAtTurnInitiatorMessageIndex'] as $fieldName => $stampName) {
            if ($tickOutcome['planStamps'][$stampName] !== $resendOutcome['planStamps'][$stampName]) {
                $differingFields[] = $fieldName;
            }
        }
        if ($tickOutcome['resolverCalls'] !== $resendOutcome['resolverCalls']) {
            $differingFields[] = 'resolverCalls';
        }
        return $differingFields;
    }

    /** @param array<int, mixed> $rawUpdates */
    protected static function namesAnAddressTwice(array $rawUpdates): bool
    {
        $addresses = [];
        foreach ($rawUpdates as $rawUpdate) {
            $address = is_array($rawUpdate) ? ($rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null) : null;
            if (is_string($address)) {
                if (isset($addresses[$address])) {
                    return true;
                }
                $addresses[$address] = true;
            }
        }
        return false;
    }

    /**
     * The keys of the case's updates that send the item's stored status again (aliases normalized) — the same-status rows that are
     * no transition.
     *
     * @param array<string, mixed> $case
     * @return string[]
     */
    protected function findSameStatusKeys(array $case): array
    {
        $standingTaskPlan = $this->buildPlan((string)$case['plan']);
        $this->applyItemOverrides($standingTaskPlan, $case['itemOverrides'] ?? []);
        $sameStatusKeys = [];
        foreach ($case['arguments']['updates'] ?? [] as $rawUpdate) {
            $address = is_array($rawUpdate) ? ($rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null) : null;
            $standingItem = is_string($address) ? $standingTaskPlan->items?->getItemByKey($address) : null;
            $rawStatus = strtolower(trim((string)($rawUpdate['status'] ?? $rawUpdate['state'] ?? '')));
            if ($standingItem !== null && (UpdateTaskPlanTool::STATUS_ALIASES[$rawStatus] ?? null) === $standingItem->status) {
                $sameStatusKeys[] = $address;
            }
        }
        return $sameStatusKeys;
    }

    /**
     * The keys of the case's TRANSITIONING updates (a standing item, a status other than its stored one) on which the
     * resend differs from the tick: the item's field changes, or the lines that name the item in the tick's words, in
     * order. Empty when the cell has none or every one meets the tick — a same-status row beside them sets the cell
     * apart only for itself.
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $tickOutcome
     * @param array<string, mixed> $resendOutcome
     * @return string[]
     */
    protected function findTransitioningKeysThatMissTheTick(array $case, array $tickOutcome, array $resendOutcome): array
    {
        $standingTaskPlan = $this->buildPlan((string)$case['plan']);
        $this->applyItemOverrides($standingTaskPlan, $case['itemOverrides'] ?? []);
        $sameStatusKeys = $this->findSameStatusKeys($case);
        $statusByTransitioningKey = [];
        $statusByStandingKey = [];
        foreach ($case['arguments']['updates'] ?? [] as $rawUpdate) {
            $address = is_array($rawUpdate) ? ($rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null) : null;
            if (!is_string($address) || $standingTaskPlan->items?->getItemByKey($address) === null) {
                continue;
            }
            $status = UpdateTaskPlanTool::STATUS_ALIASES[strtolower(trim((string)($rawUpdate['status'] ?? $rawUpdate['state'] ?? '')))] ?? null;
            $statusByStandingKey[$address] = $status;
            if (!in_array($address, $sameStatusKeys, true)) {
                $statusByTransitioningKey[$address] = $status;
            }
        }
        $missingKeys = [];
        foreach ($statusByTransitioningKey as $transitioningKey => $status) {
            $transitioningKey = (string)$transitioningKey;
            if (self::isLiftedOutOfTheCascade($status, $statusByStandingKey, $statusByTransitioningKey)) {
                // the tick counted the same-status rows toward the cascade cap and noted this row; the resend does not
                // count them (the same-status rule), so the row is judged on its own: no cascade line names it, and it is applied
                // or a line of its own names it
                $isJudgedOnItsOwn = !str_contains(implode("\n", $resendOutcome['feedback']), 'give-up cascade')
                    && (($resendOutcome['itemFieldChanges'][$transitioningKey]['status'][1] ?? null) === $status
                        || self::findLinesNamingTask($resendOutcome['feedback'], $transitioningKey) !== []);
                if (!$isJudgedOnItsOwn) {
                    $missingKeys[] = $transitioningKey;
                }
                continue;
            }
            if (($tickOutcome['itemFieldChanges'][$transitioningKey] ?? []) !== ($resendOutcome['itemFieldChanges'][$transitioningKey] ?? [])
                || self::findLinesNamingTask($tickOutcome['feedback'], $transitioningKey) !== self::findLinesNamingTask($resendOutcome['feedback'], $transitioningKey)
            ) {
                $missingKeys[] = $transitioningKey;
            }
        }
        return $missingKeys;
    }

    /**
     * The cascade cap ({@see TaskPlan::MAX_BLOCKED_UPDATES_PER_CALL}) holds `$status` for the tick, which counts every
     * standing item the call sends it to, but not for the resend, which counts only the transitions.
     *
     * @param array<string, ?string> $statusByStandingKey
     * @param array<string, ?string> $statusByTransitioningKey
     */
    protected static function isLiftedOutOfTheCascade(?string $status, array $statusByStandingKey, array $statusByTransitioningKey): bool
    {
        if (!in_array($status, [TaskPlanItem::STATUS_BLOCKED, TaskPlanItem::STATUS_NOT_APPLICABLE], true)) {
            return false;
        }
        return count(array_keys($statusByStandingKey, $status, true)) > TaskPlan::MAX_BLOCKED_UPDATES_PER_CALL
            && count(array_keys($statusByTransitioningKey, $status, true)) <= TaskPlan::MAX_BLOCKED_UPDATES_PER_CALL;
    }

    /**
     * @param string[] $feedbackLines
     * @return string[] the lines that name `$taskKey` in the tick's words, in order
     */
    protected static function findLinesNamingTask(array $feedbackLines, string $taskKey): array
    {
        return array_values(array_filter($feedbackLines, fn (string $feedbackLine): bool => str_starts_with($feedbackLine, "Task '{$taskKey}'")));
    }

    /**
     * The same-status rule held for every update that sent the stored status again: the item kept its status, and no line of the
     * call names it in the tick's words (no echo, no ALREADY line, no gate).
     *
     * @param array<string, mixed> $case
     * @param array<string, mixed> $resendOutcome
     */
    protected function assertSameStatusRowsWereNoTicks(array $case, array $resendOutcome): void
    {
        foreach ($this->findSameStatusKeys($case) as $sameStatusKey) {
            self::assertArrayNotHasKey('status', $resendOutcome['itemFieldChanges'][$sameStatusKey] ?? [], "item {$sameStatusKey} keeps its status");
            foreach ($resendOutcome['feedback'] as $feedbackLine) {
                self::assertStringStartsNotWith("Task '{$sameStatusKey}'", $feedbackLine, 'a same-status row is no tick');
            }
        }
    }

    /**
     * Run one case as an `update_task_plan` resend and return everything observable. Public so the golden can be
     * regenerated from a script (never from the test run).
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    public function runResendCase(array $case): array
    {
        $inMemoryTaskPlan = $this->buildPlan((string)$case['plan']);
        $this->applyItemOverrides($inMemoryTaskPlan, $case['itemOverrides'] ?? []);
        // the plan as the live path reads it: every item carries its key
        $standingTaskPlan = $inMemoryTaskPlan->buildDeepCopy();
        $conversationMessages = isset($case['messages']) ? $this->buildMessages((string)$case['messages']) : null;
        $currentTurnInitiatorMessageIndex = (int)($case['initiator'] ?? self::CURRENT_TURN_INITIATOR_INDEX);
        $itemFieldsBefore = $this->buildItemFieldsByNumberPath($standingTaskPlan->items);
        $this->resolverAnswers = array_intersect_key($case, array_flip(['criterionVerdict', 'doneCriterion', 'channelState']));
        $this->resolverCalls = [];

        $updateTaskPlanArguments = UpdateTaskPlanArguments::fromArguments(['items' => self::buildResendItems($case['arguments'], $standingTaskPlan)]);
        $taskPlanUpdateContext = $this->buildResendContext($case, $conversationMessages, $currentTurnInitiatorMessageIndex);
        $taskPlanUpdateOutcome = $standingTaskPlan->hasItems()
            ? $standingTaskPlan->applyUpdate($updateTaskPlanArguments, $taskPlanUpdateContext)
            : TaskPlan::createFromUpdate($updateTaskPlanArguments, $taskPlanUpdateContext);
        $this->recordResolverCalls();
        $resultingTaskPlan = $taskPlanUpdateOutcome->taskPlan ?? $standingTaskPlan;
        if ($taskPlanUpdateOutcome->taskPlan === null && $standingTaskPlan->hasItems()) {
            // the record service: a rejected or unchanged call still counts as this turn's plan touch
            $standingTaskPlan->markUpdatedInTurn($currentTurnInitiatorMessageIndex);
        }

        return [
            'outcome' => match (true) {
                $taskPlanUpdateOutcome->isRejected() => self::OUTCOME_REJECTED,
                $taskPlanUpdateOutcome->isUnchanged && $taskPlanUpdateOutcome->hasGateRefusedStatus => self::OUTCOME_UNCHANGED_STATUS_REFUSED,
                $taskPlanUpdateOutcome->isUnchanged => self::OUTCOME_UNCHANGED,
                default => self::OUTCOME_APPLIED,
            },
            'feedback' => $taskPlanUpdateOutcome->isRejected() ? $taskPlanUpdateOutcome->rejections->getLines() : $taskPlanUpdateOutcome->feedback,
            'planStamps' => [
                'updatedAtTurnInitiatorMessageIndex' => $resultingTaskPlan->updatedAtTurnInitiatorMessageIndex,
                'resolvedAtTurnInitiatorMessageIndex' => $resultingTaskPlan->resolvedAtTurnInitiatorMessageIndex,
            ],
            'itemFieldChanges' => self::buildItemFieldChanges($itemFieldsBefore, $this->buildItemFieldsByNumberPath($resultingTaskPlan->items)),
            'resolverCalls' => $this->resolverCalls,
        ];
    }

    /**
     * What the resend is judged against besides the plan — as the record service builds it for the live call: exactly the evidence, criteria and channel reader the case's `set_task_status` tick gets
     * ({@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick}); the replay environment
     * gives the turn and its messages only, as the supervisor replay does. The criteria asked and the channels read are
     * recorded once the call ran ({@see recordResolverCalls()}).
     *
     * @param array<string, mixed> $case
     */
    protected function buildResendContext(array $case, ?AIConversationMessages $conversationMessages, int $currentTurnInitiatorMessageIndex): TaskPlanUpdateContext
    {
        $this->recordAnswers = null;
        $this->channelConnectionStates = null;
        if ($case['replay'] ?? false) {
            return TaskPlanUpdateContext::forTurn($currentTurnInitiatorMessageIndex, $conversationMessages);
        }
        $criteriaDecideTicks = array_key_exists('doneCriterion', $case);
        $entitlementLockedToolNames = $case['entitlementLocked'] ?? [];
        $taskPlanEvidence = $conversationMessages !== null
            ? TaskPlanEvidence::forTurn($conversationMessages, $currentTurnInitiatorMessageIndex, criteriaDecideTicks: $criteriaDecideTicks, entitlementLockedToolNames: $entitlementLockedToolNames)
            : TaskPlanEvidence::forTurnWithoutTranscript($currentTurnInitiatorMessageIndex, $entitlementLockedToolNames);
        $taskPlanEvidence->criteriaDecideTicks = $criteriaDecideTicks;
        $taskPlanEvidence->widgetSelfHydrationKey = array_key_exists('shopId', $case) ? $case['shopId'] : self::SHOP_ID;
        $taskPlanEvidence->ownerIsOnThePhoneLine = (bool)($case['phone'] ?? false);
        $this->recordAnswers = $this->buildRecordAnswers();
        if (array_key_exists('channelState', $case)) {
            $this->channelConnectionStates = new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => $case['channelState'], TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL => $case['channelState']]);
        }
        return TaskPlanUpdateContext::forStatusTick(
            $currentTurnInitiatorMessageIndex,
            $taskPlanEvidence,
            $this->recordAnswers ?? TaskPlanDoneCriteria::fromClasses(),
            $this->channelConnectionStates !== null
                ? TaskPlanDoneCriterionInputsWithFixedChannelStates::forChannelStates($this->channelConnectionStates, $conversationMessages, null)
                : TaskPlanDoneCriterionInputs::forConversation(AIConversation::newInstance(), $conversationMessages, null)
        );
    }

    /** The criterion keys the purpose's criteria were asked and the channels the reader was asked, each in order. */
    protected function recordResolverCalls(): void
    {
        if ($this->recordAnswers !== null && $this->recordAnswers->askedDoneCriterionKeys !== []) {
            $this->resolverCalls['doneCriteria'] = $this->recordAnswers->askedDoneCriterionKeys;
        }
        if ($this->channelConnectionStates !== null && $this->channelConnectionStates->askedChannels !== []) {
            $this->resolverCalls['channelState'] = $this->channelConnectionStates->askedChannels;
        }
    }

    /**
     * The resend that asks what the case's `set_task_status` updates ask: every standing top-level item as `{"key": …}`,
     * each update's status fields (status, note and flags as sent, aliases included) on the element of the item its
     * address names — a subtask's parent lists its whole level — and every update whose address names no standing item
     * (an unknown key, a text, an element without an address, a second update of the same item, an element that is no
     * object) as an element of its own at the top level, where the update check judges it. Without an `updates` list
     * the resend names the items only.
     *
     * @param array<string, mixed> $arguments the case's `set_task_status` arguments
     * @return array<int, mixed> the resend's `items`
     */
    public static function buildResendItems(array $arguments, TaskPlan $standingTaskPlan): array
    {
        $resendItems = [];
        foreach ($standingTaskPlan->items?->getElements() ?? [] as $standingItem) {
            $resendItems[] = ['key' => $standingItem->key];
        }
        $rawUpdates = $arguments['updates'] ?? null;
        if (!is_array($rawUpdates)) {
            return $resendItems;
        }
        $mergedKeys = [];
        foreach ($rawUpdates as $rawUpdate) {
            if (!is_array($rawUpdate)) {
                $resendItems[] = $rawUpdate;
                continue;
            }
            $address = $rawUpdate['task'] ?? $rawUpdate['item'] ?? $rawUpdate['number'] ?? null;
            $statusFields = array_diff_key($rawUpdate, ['task' => true, 'item' => true, 'number' => true]);
            if (is_string($address) && !isset($mergedKeys[$address])
                && self::mergeStatusFieldsIntoElementOfKey($resendItems, $standingTaskPlan, $address, $statusFields)
            ) {
                $mergedKeys[$address] = true;
                continue;
            }
            $resendItems[] = ($address !== null ? ['key' => $address] : []) + $statusFields;
        }
        return $resendItems;
    }

    /**
     * Put `$statusFields` on the element of the standing item whose key is `$key`, listing the levels down to it (each
     * level as its standing keys); false when no standing item has that key.
     *
     * @param array<int, mixed> $resendItems
     * @param array<string, mixed> $statusFields
     */
    protected static function mergeStatusFieldsIntoElementOfKey(array &$resendItems, TaskPlan $standingTaskPlan, string $key, array $statusFields): bool
    {
        $standingItem = $standingTaskPlan->items?->getItemByKey($key);
        if ($standingItem === null) {
            return false;
        }
        $keySegments = explode('.', $key);
        $levelElements = &$resendItems;
        $levelItems = $standingTaskPlan->items;
        $walkedKey = '';
        foreach ($keySegments as $depth => $keySegment) {
            $walkedKey = $walkedKey === '' ? $keySegment : $walkedKey . '.' . $keySegment;
            $elementPosition = self::findElementPositionOfKey($levelElements, $walkedKey);
            if ($elementPosition === null) {
                return false;
            }
            if ($depth === count($keySegments) - 1) {
                $levelElements[$elementPosition] += $statusFields;
                return true;
            }
            $levelItem = $levelItems?->getItemByKey($walkedKey);
            if (!isset($levelElements[$elementPosition]['subtasks'])) {
                $levelElements[$elementPosition]['subtasks'] = [];
                foreach ($levelItem?->subtasks?->getElements() ?? [] as $subtaskItem) {
                    $levelElements[$elementPosition]['subtasks'][] = ['key' => $subtaskItem->key];
                }
            }
            $levelElements = &$levelElements[$elementPosition]['subtasks'];
            $levelItems = $levelItem?->subtasks;
        }
        return false;
    }

    /** @param array<int, mixed> $levelElements */
    protected static function findElementPositionOfKey(array $levelElements, string $key): ?int
    {
        foreach ($levelElements as $elementPosition => $levelElement) {
            if (is_array($levelElement) && ($levelElement['key'] ?? null) === $key) {
                return $elementPosition;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public static function loadGoldenOutcomes(): array
    {
        if (self::$goldenOutcomesByCaseName === null) {
            self::$goldenOutcomesByCaseName = is_file(self::GOLDEN_FILE)
                ? json_decode((string)file_get_contents(self::GOLDEN_FILE), true, 512, JSON_THROW_ON_ERROR)
                : [];
        }
        return self::$goldenOutcomesByCaseName;
    }

    /** @return array<string, mixed> */
    protected function findGoldenOutcome(string $caseName): array
    {
        $goldenOutcomes = self::loadGoldenOutcomes();
        self::assertArrayHasKey($caseName, $goldenOutcomes, 'no golden outcome for this case');
        return $goldenOutcomes[$caseName];
    }
}
