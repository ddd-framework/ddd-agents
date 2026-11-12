<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use PHPUnit\Framework\Attributes\DataProvider;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\SetTaskStatusTestTick;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanEvidence;

/**
 * Group b — the GATE TABLE of `set_task_status`: recorded on the former tool method
 * (`SetTaskStatusTool::applyStatusUpdatesFromArguments()`, gates G0–G28) before it was split,
 * run today through {@see TaskPlan::applyStatusUpdates()} ({@see SetTaskStatusTestTick::apply()}). A failing assertion after a refactor commit is a BEHAVIOUR CHANGE to be named, never a test to
 * "fix" silently. Quirks of today's code are pinned, not repaired (each one is named in a `QUIRK` comment below).
 *
 * The table: every gate row × the requested status (open / done / blocked / not_applicable) × three environments:
 * - `on`  — the gate's trigger or evidence is present (for a criterion or channel gate: the record answers a value),
 * - `off` — it is absent (the record answers null),
 * - `replay` — the supervisor's replay call (`SupervisorCheckpointService::replayTaskPlanUpToIndex()`): only the
 *   arguments, the plan, the turn initiator index and the messages of the `on` environment — no shop, no
 *   entitlement list, no criterion (an empty registry), no channel reader, no phone line.
 * Each run asserts the gate it reached (the label table in {@see provideGateTableRows()}; a label of another gate in a
 * row is the "cannot be reached in this combination" assertion) and the exact observed outcome against the golden
 * `Fixtures/SetTaskStatusGateTableCharacterizationTest.json`: the feedback lines (bytes, in order), the six reported
 * counters, the two plan stamps, every changed persisted field of every item (before → after), and the criterion keys
 * the purpose's criteria were asked and the channels the channel reader was asked, each in order.
 *
 * The purpose's criteria registry replaced the three resolver callables with the purpose's criteria registry and its inputs:
 * ONE registry answers every gate that reads a criterion. A case's `criterionVerdict` / `doneCriterion` is the answer of
 * that registry (the record verdict when it names one, else the done criterion's), `doneCriterion` present = the
 * purpose's criteria decide every tick ({@see TaskPlanEvidence::$criteriaDecideTicks}), `channelState` present = the
 * channel reader exists. The golden was re-recorded on the former method with exactly that pairing — the record verdict
 * resolver and the done-criterion resolver answering alike, as they do in every conversation (a purpose that enforces
 * its criteria always has the record verdict resolver too); seven cells had passed the done-criterion resolver ALONE, a
 * pairing no conversation has, and changed with the re-recording. The variant cases ({@see provideVariantCases()}) pin the alias chains,
 * the call-level lines and the secondary branches of single gates.
 *
 * The strict key addressing re-pinned the ADDRESS rows only: since then an address that is
 * not a key of the plan rejects the whole call before any gate ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck}),
 * labelled ADDRESS. The rows G0–G2 and the address variants keep their names as the record of what they reached before
 * (G0 not an object, G1 no address, G2 not found; a unique text and a copied title resolved, G28).
 */
class SetTaskStatusGateTableCharacterizationTest extends TaskPlanGateTableTestCase
{
    protected const string GOLDEN_FILE = __DIR__ . '/Fixtures/SetTaskStatusGateTableCharacterizationTest.json';

    /** @var array<string, mixed>|null the golden outcomes keyed by case name */
    protected static ?array $goldenOutcomesByCaseName = null;

    #[DataProvider('provideGateTableCases')]
    public function testGateTable(string $rowName, string $requestedStatus, string $environmentName): void
    {
        $row = self::provideGateTableRows()[$rowName];
        $case = self::buildGateTableCase($row, $requestedStatus, $environmentName);
        $expectedLabel = explode(' ', $row['labels'][$requestedStatus])[array_search($environmentName, ['on', 'off', 'replay'], true)];

        $observedOutcome = $this->runCase($case);

        self::assertSame($expectedLabel, $observedOutcome['reachedGate'], 'the gate reached');
        self::assertSame($this->findGoldenOutcome($rowName . ' | ' . $requestedStatus . ' | ' . $environmentName), $observedOutcome);
    }

    #[DataProvider('provideVariantCases')]
    public function testVariant(array $case, string $expectedLabel): void
    {
        $observedOutcome = $this->runCase($case);

        self::assertSame($expectedLabel, $observedOutcome['reachedGate'], 'the gate reached');
        self::assertSame($this->findGoldenOutcome($this->dataName()), $observedOutcome);
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

    // ---------------------------------------------------------------------------------------------------------------
    // Running one case
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Run one case through the plan's application and return everything observable: the reached gate, the feedback
     * lines, the counters, the plan stamps, the changed item fields (before → after) and the record / channel reads. Public so the golden
     * can be regenerated from a script (never from the test run).
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    public function runCase(array $case): array
    {
        $taskPlan = $this->buildPlan((string)$case['plan']);
        $this->applyItemOverrides($taskPlan, $case['itemOverrides'] ?? []);
        $conversationMessages = isset($case['messages']) ? $this->buildMessages((string)$case['messages']) : null;
        $currentTurnInitiatorMessageIndex = (int)($case['initiator'] ?? self::CURRENT_TURN_INITIATOR_INDEX);
        $itemFieldsBefore = $this->buildItemFieldsByNumberPath($taskPlan->items);

        $this->resolverAnswers = array_intersect_key($case, array_flip(['criterionVerdict', 'doneCriterion', 'channelState']));
        $this->resolverCalls = [];

        if ($case['replay'] ?? false) {
            // the supervisor's replay call (SupervisorCheckpointService::replayTaskPlanUpToIndex()): no criterion, no channel reader
            $result = SetTaskStatusTestTick::apply(
                $case['arguments'],
                $taskPlan,
                $currentTurnInitiatorMessageIndex,
                $conversationMessages
            );
        } else {
            $recordAnswers = $this->buildRecordAnswers();
            $channelConnectionStates = array_key_exists('channelState', $case)
                ? new TaskPlanChannelConnectionStatesFixture([TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE => $case['channelState'], TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL => $case['channelState']])
                : null;
            $result = SetTaskStatusTestTick::apply(
                $case['arguments'],
                $taskPlan,
                $currentTurnInitiatorMessageIndex,
                $conversationMessages,
                array_key_exists('shopId', $case) ? $case['shopId'] : self::SHOP_ID,
                $case['entitlementLocked'] ?? [],
                $recordAnswers,
                array_key_exists('doneCriterion', $case),
                $channelConnectionStates,
                (bool)($case['phone'] ?? false),
            );
            if ($recordAnswers !== null && $recordAnswers->askedDoneCriterionKeys !== []) {
                $this->resolverCalls['doneCriteria'] = $recordAnswers->askedDoneCriterionKeys;
            }
            if ($channelConnectionStates !== null && $channelConnectionStates->askedChannels !== []) {
                $this->resolverCalls['channelState'] = $channelConnectionStates->askedChannels;
            }
        }

        return [
            'reachedGate' => self::classifyReachedGate($result),
            'feedback' => $result['feedback'],
            'counters' => [
                'updatedCount' => $result['updatedCount'],
                'notedCount' => $result['notedCount'],
                'notedObstacleCount' => $result['notedObstacleCount'],
                'allAlreadyInStatus' => $result['allAlreadyInStatus'],
                'onlyResolvedByPlatformInThisStep' => $result['onlyResolvedByPlatformInThisStep'],
            ],
            'resultKeys' => array_keys($result),
            'planStamps' => [
                'updatedAtTurnInitiatorMessageIndex' => $taskPlan->updatedAtTurnInitiatorMessageIndex,
                'resolvedAtTurnInitiatorMessageIndex' => $taskPlan->resolvedAtTurnInitiatorMessageIndex,
            ],
            'itemFieldChanges' => self::buildItemFieldChanges($itemFieldsBefore, $this->buildItemFieldsByNumberPath($taskPlan->items)),
            'resolverCalls' => $this->resolverCalls,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The golden
    // ---------------------------------------------------------------------------------------------------------------

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
