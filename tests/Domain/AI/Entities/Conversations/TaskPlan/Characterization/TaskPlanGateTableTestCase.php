<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusAddressCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogue;
use DDD\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNames;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerQuestionCatalogueOfAnApplicationWithItsOwnQuestions;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Syntax\WidgetBlockFieldNamesOfAnApplicationWithItsOwnNames;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriteriaWithFixedVerdicts;

/**
 * The GATE TABLE of the task plan's status gates as data:
 * every gate row × the requested status × the three environments ({@see provideGateTableRows()}), the variant cases
 * ({@see provideVariantCases()}), the fixture plans and conversations they run on, the record and channel answers of a
 * case, and the reached-gate classifier. {@see SetTaskStatusGateTableCharacterizationTest} runs every cell through
 * `set_task_status`; {@see UpdateTaskPlanResendGateTableCharacterizationTest} runs the same cells through an
 * `update_task_plan` resend, against the same expectations (one expectation set for both tools).
 */
abstract class TaskPlanGateTableTestCase extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    /**
     * The tables were recorded with the block names (`shopId`), the owner questions and the plan-item fields of an
     * application; they are read with them.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[WidgetBlockFieldNames::class] = WidgetBlockFieldNamesOfAnApplicationWithItsOwnNames::class;
        StaticRegistry::$frameworkToAppClassNames[AskOwnerQuestionCatalogue::class] = AskOwnerQuestionCatalogueOfAnApplicationWithItsOwnQuestions::class;
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItemOfAnApplicationWithAGuidedSetup::class;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    /** The turn initiator of every fixture conversation: the owner's text at message index 10. */
    protected const int CURRENT_TURN_INITIATOR_INDEX = 10;

    protected const int SHOP_ID = 4711;

    protected const array REQUESTED_STATUSES = [
        TaskPlanItem::STATUS_OPEN,
        TaskPlanItem::STATUS_DONE,
        TaskPlanItem::STATUS_BLOCKED,
        TaskPlanItem::STATUS_NOT_APPLICABLE,
    ];


    /** The configured answers of the case's record and channel reader; a missing key = that reader is not passed. */
    protected array $resolverAnswers = [];

    /** @var array<string, string[]> the criterion keys the criteria were asked / the channels the reader was asked, in call order */
    protected array $resolverCalls = [];

    // ---------------------------------------------------------------------------------------------------------------
    // The table
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * One row per status gate. `labels` = per requested status the gate reached in the `on`, `off` and
     * `replay` environment. Labels: G0…G28 = that gate; RECORD = the record's verdict was applied in place of the
     * tick (G8, or the G28 override — the two write identical bytes); NONE = nothing applied and no gate line;
     * ADDRESS = the whole call rejected for an address that is not a key of the plan (before any gate).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function provideGateTableRows(): array
    {
        return [
            // address, status, echo, cascade
            'G0 not an object' => [
                'plan' => 'plainLeaf', 'updateShape' => 'notAnObject',
                'on' => ['messages' => 'base'], 'off' => [],
                // formerly: G0 in every cell
                'labels' => ['open' => 'ADDRESS ADDRESS ADDRESS', 'done' => 'ADDRESS ADDRESS ADDRESS', 'blocked' => 'ADDRESS ADDRESS ADDRESS', 'not_applicable' => 'ADDRESS ADDRESS ADDRESS'],
            ],
            'G1 no address' => [
                'plan' => 'plainLeaf', 'updateShape' => 'noAddress',
                'on' => ['messages' => 'base'], 'off' => [],
                // formerly: G1 in every cell
                'labels' => ['open' => 'ADDRESS ADDRESS ADDRESS', 'done' => 'ADDRESS ADDRESS ADDRESS', 'blocked' => 'ADDRESS ADDRESS ADDRESS', 'not_applicable' => 'ADDRESS ADDRESS ADDRESS'],
            ],
            'G2 address not found' => [
                'plan' => 'plainLeaf', 'updateShape' => 'unknownAddress',
                'on' => ['messages' => 'base'], 'off' => [],
                // formerly: G2 in every cell
                'labels' => ['open' => 'ADDRESS ADDRESS ADDRESS', 'done' => 'ADDRESS ADDRESS ADDRESS', 'blocked' => 'ADDRESS ADDRESS ADDRESS', 'not_applicable' => 'ADDRESS ADDRESS ADDRESS'],
            ],
            // on = the status in an unrecognized spelling, off = in an accepted alias spelling (normalized, applied)
            'G3 unknown status' => [
                'plan' => 'plainLeaf', 'updateShape' => 'statusSpelling',
                'on' => ['messages' => 'base', 'statusSpelling' => 'unrecognized'], 'off' => ['statusSpelling' => 'alias'],
                'labels' => ['open' => 'G3 G28 G3', 'done' => 'G3 G28 G3', 'blocked' => 'G3 G28 G3', 'not_applicable' => 'G3 G28 G3'],
            ],
            // on = no earlier tick of the item in this turn, off = an earlier set_task_status of this turn named it
            'G4 echo of a platform closing in this turn' => [
                'plan' => 'echoLeaf',
                'on' => ['messages' => 'base'], 'off' => ['messages' => 'earlierTickInTurn'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G4 G26 G4', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G25 G25 G25'],
            ],
            // on = five distinct items in one call (above the cap of 4), off = four (at the cap)
            'G5 blocked cascade' => [
                'plan' => 'fiveLeaves', 'updateShape' => 'oneUpdatePerItem',
                'on' => ['messages' => 'base', 'updateCount' => 5], 'off' => ['updateCount' => 4],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G5 G28 G5', 'not_applicable' => 'G6 G28 G6'],
            ],
            'G6 not_applicable cascade' => [
                'plan' => 'fiveLeaves', 'updateShape' => 'oneUpdatePerItem',
                'on' => ['messages' => 'base', 'updateCount' => 5], 'off' => ['updateCount' => 4],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G5 G28 G5', 'not_applicable' => 'G6 G28 G6'],
            ],
            // record / criterion gates
            // on = the record verdict resolver answers STILL OPEN, off = it answers null
            'G7 record verdict still open' => [
                'plan' => 'criterionLeaf',
                'on' => ['messages' => 'base', 'criterionVerdict' => ['stillOpen', 'the page read is still running']],
                'off' => ['criterionVerdict' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G7 G28 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G7 G28 G28'],
            ],
            // on = the record verdict resolver answers DONE with its note, off = it answers null
            'G8 record verdict resolves the leaf' => [
                'plan' => 'criterionLeaf',
                'on' => ['messages' => 'base', 'criterionVerdict' => ['done', 'found: 2 verified social profiles']],
                'off' => ['criterionVerdict' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'RECORD G28 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'RECORD G28 G28'],
            ],
            // on = the enforcing purpose's criteria answer SATISFIED, off = they answer null
            // (not_applicable | on): the record gate G8 lands the satisfied verdict — the model's not_applicable becomes done
            // formerly, with a separate resolver per gate (done | on): G28 — the done-criterion resolver alone skipped G8,
            // the G28 read confirmed it
            'G9 done on an unsatisfied enforced criterion' => [
                'plan' => 'enforcedCriterionLeaf',
                'on' => ['messages' => 'base', 'doneCriterion' => ['done', 'the build record shows a validated build']],
                'off' => ['doneCriterion' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'RECORD G9 G28', 'blocked' => 'G10 G28 G28', 'not_applicable' => 'RECORD G28 G28'],
            ],
            // formerly, with a separate resolver per gate (done | on): G28, as in the G9 row
            'G10 blocked while the machine record is satisfied' => [
                'plan' => 'enforcedCriterionLeaf',
                'on' => ['messages' => 'base', 'doneCriterion' => ['done', 'the build record shows a validated build']],
                'off' => ['doneCriterion' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'RECORD G9 G28', 'blocked' => 'G10 G28 G28', 'not_applicable' => 'RECORD G28 G28'],
            ],
            // every update carries requiresUserAction: true; on = the criteria answer STILL OPEN, off = null
            // formerly, with a separate resolver per gate (done | on): G9, (not_applicable | on): G28 — the done-criterion
            // resolver alone skipped
            // the record gate G7, which now notes both ticks
            'G11 blocked with requiresUserAction on a machine item' => [
                'plan' => 'enforcedCriterionLeaf', 'updateExtra' => ['requiresUserAction' => true],
                'on' => ['messages' => 'base', 'doneCriterion' => ['stillOpen', 'no build yet']],
                'off' => ['doneCriterion' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G7 G9 G28', 'blocked' => 'G11 G11 G28', 'not_applicable' => 'G7 G28 G28'],
            ],
            // evidence gates of done
            // on = the previous turn read the evidence and ended on an answer, off = no such previous turn
            'G12 delivery gate' => [
                'plan' => 'deliveryLeaf',
                'on' => ['messages' => 'previousTurnDelivery'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G12 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // on = a completed addGoal call stated by the owner, off = no call
            'G13 tool-call gate' => [
                'plan' => 'toolGateLeaf',
                'on' => ['messages' => 'addGoalByOwner'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G13 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // the owner is on the phone line in on AND off; on = the widget was rendered, off = no widget
            'G14 phone line and a widget' => [
                'plan' => 'widgetLeaf',
                'on' => ['messages' => 'handoverWidget', 'phone' => true], 'off' => ['messages' => 'base', 'phone' => true],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G14 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // on = the widget with its declared field filled, off = no widget
            'G15 widget gate' => [
                'plan' => 'filledWidgetLeaf',
                'on' => ['messages' => 'handoverWidgetFilled'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G15 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // the widget is rendered in on AND off; on = the owner answered after it, off = the widget still waits
            'G16 widget answered by the owner' => [
                'plan' => 'answeredWidgetLeaf',
                'on' => ['messages' => 'profileChoiceAnswered'], 'off' => ['messages' => 'profileChoiceWaiting'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G16 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // the connect widget is rendered in on AND off; on = the channel state resolver answers true, off = null
            'G17 connected channel' => [
                'plan' => 'connectLeaf',
                'on' => ['messages' => 'connectWidget', 'channelState' => true], 'off' => ['messages' => 'connectWidget', 'channelState' => null],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G17 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // evidence gates of not_applicable
            // on = a completed updateBusinessReach with serveModel WALK_IN, off = no call
            'G18 not_applicable requires a tool call' => [
                'plan' => 'notApplicableNeedsCallLeaf',
                'on' => ['messages' => 'walkInReach'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G28 G18 G28'],
            ],
            // on = the contradicting call completed, off = no call
            'G19 not_applicable forbidden after a tool call' => [
                'plan' => 'notApplicableForbiddenAfterCallLeaf',
                'on' => ['messages' => 'socialLinksSaved'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G28 G28 G28', 'not_applicable' => 'G19 G28 G19'],
            ],
            // evidence gates of blocked (every update carries requiresUserAction: true)
            // the owner is on the phone line in on AND off; on = the owner's decline is recorded, off = nothing recorded
            'G21 phone line and an offer widget' => [
                'plan' => 'offerLeaf', 'updateExtra' => ['requiresUserAction' => true],
                'on' => ['messages' => 'ownerDecline', 'phone' => true], 'off' => ['messages' => 'base', 'phone' => true],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G28 G21 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // on = the offer widget was rendered, off = no widget and no recorded words
            'G22 blocked requires the offer widget' => [
                'plan' => 'offerLeaf', 'updateExtra' => ['requiresUserAction' => true],
                'on' => ['messages' => 'connectWidget'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G28 G22 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // on = the owner's decline is recorded, off = nothing recorded
            'G23 prose offer requires the owner\'s recorded words' => [
                'plan' => 'proseOfferLeaf', 'updateExtra' => ['requiresUserAction' => true],
                'on' => ['messages' => 'ownerDecline'], 'off' => ['messages' => 'base'],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G28 G28 G28', 'blocked' => 'G28 G23 G28', 'not_applicable' => 'G28 G28 G28'],
            ],
            // on = the update says retryable: true (a transient obstacle), off = retryable: false
            'G24 transient obstacle on a platform-closed leaf' => [
                'plan' => 'recordClosedLeaf', 'updateExtra' => ['requiresUserAction' => false],
                'on' => ['messages' => 'base', 'updateExtra' => ['retryable' => true]], 'off' => ['updateExtra' => ['retryable' => false]],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G13 G13 G13', 'blocked' => 'G24 G28 G24', 'not_applicable' => 'G28 G28 G28'],
            ],
            // the transition
            // on = the item is done, off = the item is blocked
            'G25 a done item stays done' => [
                'plan' => 'plainLeaf',
                'on' => ['messages' => 'base', 'itemOverrides' => ['status' => 'done', 'statusNote' => 'written by the model']],
                'off' => ['itemOverrides' => ['status' => 'blocked', 'statusNote' => 'the old block']],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G26 G28 G26', 'blocked' => 'G28 G26 G28', 'not_applicable' => 'G25 G28 G25'],
            ],
            // on = the item already carries the requested status, off = the item is open
            'G26 same-status re-tick' => [
                'plan' => 'plainLeaf',
                'on' => ['messages' => 'base', 'itemOverrides' => ['status' => '@requested', 'statusNote' => 'the earlier note']],
                'off' => ['itemOverrides' => ['status' => 'open']],
                'labels' => ['open' => 'G28 G28 G28', 'done' => 'G26 G28 G26', 'blocked' => 'G26 G28 G26', 'not_applicable' => 'G26 G28 G26'],
            ],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function provideGateTableCases(): array
    {
        $cases = [];
        foreach (self::provideGateTableRows() as $rowName => $row) {
            foreach (self::REQUESTED_STATUSES as $requestedStatus) {
                foreach (['on', 'off', 'replay'] as $environmentName) {
                    $cases[$rowName . ' | ' . $requestedStatus . ' | ' . $environmentName] = [$rowName, $requestedStatus, $environmentName];
                }
            }
        }
        return $cases;
    }

    /**
     * The environment of one table cell: `on` and `off` start from the row's `on` (so `off` names only what it turns
     * off); `replay` takes the `on` messages, item overrides and update shape and drops every other input.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function buildGateTableCase(array $row, string $requestedStatus, string $environmentName): array
    {
        $onEnvironment = $row['on'];
        $environment = match ($environmentName) {
            'on' => $onEnvironment,
            'off' => array_replace($onEnvironment, $row['off']),
            'replay' => array_intersect_key($onEnvironment, array_flip(['messages', 'itemOverrides', 'updateCount', 'updateExtra', 'statusSpelling'])) + ['replay' => true],
        };
        $itemOverrides = $environment['itemOverrides'] ?? [];
        if (($itemOverrides['status'] ?? null) === '@requested') {
            $itemOverrides['status'] = $requestedStatus;
        }
        $case = $environment;
        $case['plan'] = $row['plan'];
        $case['itemOverrides'] = $itemOverrides;
        $case['arguments'] = ['updates' => self::buildUpdates(
            $row['updateShape'] ?? 'single',
            $requestedStatus,
            array_replace($row['updateExtra'] ?? [], $environment['updateExtra'] ?? []),
            (int)($environment['updateCount'] ?? 1),
            (string)($environment['statusSpelling'] ?? '')
        )];
        unset($case['updateExtra'], $case['updateCount'], $case['statusSpelling']);
        return $case;
    }

    /**
     * @param array<string, mixed> $updateExtra
     * @return array<int, mixed>
     */
    protected static function buildUpdates(string $updateShape, string $requestedStatus, array $updateExtra, int $updateCount, string $statusSpelling): array
    {
        $statusSpellings = [
            'unrecognized' => ['open' => 'opened', 'done' => 'did', 'blocked' => 'stuck', 'not_applicable' => 'none'],
            'alias' => ['open' => 'pending', 'done' => 'completed', 'blocked' => 'failed', 'not_applicable' => 'skipped'],
        ];
        $spelledStatus = $statusSpellings[$statusSpelling][$requestedStatus] ?? $requestedStatus;
        $update = ['task' => '1', 'status' => $spelledStatus, 'statusNote' => 'the model note'] + $updateExtra;
        return match ($updateShape) {
            'notAnObject' => ['task 1 ' . $requestedStatus],
            'noAddress' => [array_diff_key($update, ['task' => true])],
            'unknownAddress' => [array_replace($update, ['task' => '7'])],
            'oneUpdatePerItem' => array_map(
                static fn (int $itemNumber): array => array_replace($update, ['task' => (string)$itemNumber]),
                range(1, $updateCount)
            ),
            default => [$update],
        };
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The variants
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Secondary branches, alias chains and call-level lines the table cells do not reach. Each case names its plan,
     * environment and raw arguments in full, and the gate label it reaches.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function provideVariantCases(): array
    {
        $note = 'the model note';
        return [
            // call prelude
            'updates key missing → early return, no stamp' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => []], 'NONE'],
            'updates not an array → early return' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => 'done']], 'NONE'],
            'empty updates list → nothing, no stamp' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => []]], 'NONE'],
            // the address rows below reject the whole call (ADDRESS); the comment names the label from when an unresolved
            // address only skipped its own update
            // formerly: G2
            'plan without items → not found' => [['plan' => 'noItems', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'ADDRESS'],
            // addressing
            'address alias item + status alias state + note alias note' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['item' => '1', 'state' => 'Completed', 'note' => $note]]]], 'G28'],
            'address alias number + note alias blockReason' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['number' => '2', 'status' => 'blocked', 'blockReason' => 'the API refused']]]], 'G28'],
            // formerly: G28 (resolved by its text)
            'address by unique item text, case-insensitive' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => 'SECOND ITEM', 'status' => 'done', 'statusNote' => $note]]]], 'ADDRESS'],
            // formerly: G2
            'address by ambiguous item text → not found' => [['plan' => 'twoLeavesWithTheSameText', 'messages' => 'base', 'arguments' => ['updates' => [['task' => 'same text', 'status' => 'done', 'statusNote' => $note]]]], 'ADDRESS'],
            // formerly: G28 (the copied title cut off)
            'address number path with the copied title behind it' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '2 Second item', 'status' => 'done', 'statusNote' => $note]]]], 'ADDRESS'],
            'address of a subtask by its number path' => [['plan' => 'containerWithOpenSubtask', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1.2', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            // info lines — the raw status is lower-cased before the "normalized" comparison, so 'DONE' is not reported
            'status in upper case is not reported as normalized' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'DONE', 'statusNote' => $note]]]], 'G28'],
            'done without a statusNote → applied with the omission named' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done']]]], 'G28'],
            'blocked without a statusNote → applied with the omission named' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked']]]], 'G28'],
            'not_applicable without a statusNote → applied with the omission named' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'n/a']]]], 'G28'],
            'statusNote longer than the cap is cut to 200 characters' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => str_repeat('x', 230)]]]], 'G28'],
            // G4
            'G4 echo without messages (null) → still an echo' => [['plan' => 'echoLeaf', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G4'],
            'G4 echo with turn initiator -1 → not an echo, the ALREADY path' => [['plan' => 'echoLeaf', 'messages' => 'base', 'initiator' => -1, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G26'],
            'G4 platform closed before this turn → the ALREADY path' => [['plan' => 'echoLeaf', 'messages' => 'base', 'initiator' => 13, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G26'],
            // formerly: G2 (the echo applied, the unknown address skipped)
            'G4 echo + an unknown address → onlyResolvedByPlatformInThisStep false' => [['plan' => 'echoLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done'], ['task' => '9', 'status' => 'done']]]], 'ADDRESS'],
            // QUIRK: the unused $currentModelStepHeadMessageIndex changes nothing (the echo reads the turn initiator)
            'G4 echo with a model-step head index after the closing → unchanged' => [['plan' => 'echoLeaf', 'messages' => 'base', 'stepHead' => 99, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done']]]], 'G4'],
            // G5 / G6 and the call-level lines
            // the first applies, the four repeats meet the item blocked: ALREADY (lecture once, then the short line)
            'G5 five blocked updates on ONE item → distinct count 1, no cascade' => [['plan' => 'fiveLeaves', 'messages' => 'base', 'arguments' => ['updates' => array_fill(0, 5, ['task' => '1', 'status' => 'blocked', 'statusNote' => $note])]], 'G26'],
            'G5 five blocked updates, one unresolvable → distinct count 4, no cascade' => [['plan' => 'fiveLeaves', 'messages' => 'base', 'arguments' => ['updates' => [
                ['task' => '1', 'status' => 'blocked', 'statusNote' => $note], ['task' => '2', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '3', 'status' => 'blocked', 'statusNote' => $note], ['task' => '4', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '9', 'status' => 'blocked', 'statusNote' => $note],
            ]]], 'ADDRESS'], // formerly: G2 (four applied, the unknown address skipped)
            'G5 + G6 in one call → the n/a line before the blocked line, the done update applies' => [['plan' => 'tenLeaves', 'messages' => 'base', 'arguments' => ['updates' => [
                ['task' => '1', 'status' => 'blocked', 'statusNote' => $note], ['task' => '2', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '3', 'status' => 'blocked', 'statusNote' => $note], ['task' => '4', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '5', 'status' => 'blocked', 'statusNote' => $note], ['task' => '6', 'status' => 'n/a', 'statusNote' => $note],
                ['task' => '7', 'status' => 'n/a', 'statusNote' => $note], ['task' => '8', 'status' => 'n/a', 'statusNote' => $note],
                ['task' => '9', 'status' => 'n/a', 'statusNote' => $note], ['task' => '10', 'status' => 'n/a', 'statusNote' => $note],
                ['task' => '1', 'status' => 'done', 'statusNote' => $note],
            ]]], 'G6'],
            // QUIRK: a cascaded blocked update that is ALSO the echo of a platform closing is an echo (G4 runs before the cascade note)
            'G5 cascade where one item was closed by the platform this turn → that one is an echo' => [['plan' => 'fiveLeavesFirstClosedByThePlatform', 'messages' => 'base', 'arguments' => ['updates' => [
                ['task' => '1', 'status' => 'blocked', 'statusNote' => $note], ['task' => '2', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '3', 'status' => 'blocked', 'statusNote' => $note], ['task' => '4', 'status' => 'blocked', 'statusNote' => $note],
                ['task' => '5', 'status' => 'blocked', 'statusNote' => $note],
            ]]], 'G4'],
            // G7 / G8 secondary branches
            'G7 blocked on a criterion leaf never asks the record verdict resolver' => [['plan' => 'criterionLeaf', 'messages' => 'base', 'criterionVerdict' => ['stillOpen', 'still fetching'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note]]]], 'G28'],
            'G7 done without a note' => [['plan' => 'criterionLeaf', 'messages' => 'base', 'criterionVerdict' => ['stillOpen', 'still fetching'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done']]]], 'G7'],
            'G8 a not_applicable verdict on a done tick → the record decides' => [['plan' => 'criterionLeaf', 'messages' => 'base', 'criterionVerdict' => ['not_applicable', 'no social profile exists'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'RECORD'],
            'G8 a verdict with an empty note does not resolve → G28 applies the tick' => [['plan' => 'criterionLeaf', 'messages' => 'base', 'criterionVerdict' => ['done', '  '], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G8 a blocked verdict parks the leaf → the record decides' => [['plan' => 'criterionLeaf', 'messages' => 'base', 'criterionVerdict' => ['blocked', 'the owner declined'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'RECORD'],
            // formerly, with a separate resolver per gate: G28 — the two resolvers answered differently (record verdict null, done criterion
            // satisfied), which no conversation does; one registry now answers both gates and G8 lands the satisfied verdict
            'G9 + G7 both resolvers, verdict null → the done-criterion gate decides' => [['plan' => 'enforcedCriterionLeaf', 'messages' => 'base', 'criterionVerdict' => null, 'doneCriterion' => ['done', 'validated build'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'RECORD'],
            'G11 requires_user_action alias' => [['plan' => 'enforcedCriterionLeaf', 'messages' => 'base', 'doneCriterion' => null, 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requires_user_action' => true]]]], 'G11'],
            // G12
            'G12 an answer after the initiator in this turn → applied, no evidence index' => [['plan' => 'deliveryLeaf', 'messages' => 'sameTurnAnswer', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G12 noted without a note' => [['plan' => 'deliveryLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done']]]], 'G12'],
            'G12 the item\'s evidence call raises the answer floor → the earlier answer does not count' => [['plan' => 'deliveryLeafWithToolGate', 'messages' => 'answerBeforeEvidenceCall', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G12'],
            'G12 without messages (null) → every message gate is skipped' => [['plan' => 'deliveryLeaf', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            // G13
            'G13 a proposal (statedByOwner false) does not count, the filters are named' => [['plan' => 'toolGateLeafWithRequiredArgument', 'messages' => 'addGoalProposal', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G13'],
            'G13 alternatives: the second tool name counts' => [['plan' => 'toolGateLeafWithAlternatives', 'messages' => 'updateCompetitorsDone', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G13 waived for an entitlement-locked tool' => [['plan' => 'toolGateLeaf', 'messages' => 'base', 'entitlementLocked' => ['addGoal'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G13 a failed call does not count' => [['plan' => 'toolGateLeaf', 'messages' => 'addGoalFailed', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G13'],
            // G14 / G15 / G16
            'G15 the widget rendered with its declared field unfilled → fill it' => [['plan' => 'filledWidgetLeaf', 'messages' => 'handoverWidget', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G15 waived tool gate → only the block is required, the fields are not enforced' => [['plan' => 'handoverLeafWithToolGate', 'messages' => 'handoverWidget', 'entitlementLocked' => ['scheduleOneTimeTask'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G15 waived tool gate without a widget → the fence carries no field skeleton' => [['plan' => 'handoverLeafWithToolGate', 'messages' => 'base', 'entitlementLocked' => ['scheduleOneTimeTask'], 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G15 a connect leaf' => [['plan' => 'connectLeaf', 'messages' => 'base', 'channelState' => false, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G15 a widget the owner answers' => [['plan' => 'answeredWidgetLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G15 an offer widget' => [['plan' => 'offerWidgetIsTheDoneWidget', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G15 without a shop id → the fence carries no shopId' => [['plan' => 'widgetLeaf', 'messages' => 'base', 'shopId' => null, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G15'],
            'G16 on the phone line → the phone sentence is appended' => [['plan' => 'answeredWidgetLeaf', 'messages' => 'profileChoiceWaiting', 'phone' => true, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G16'],
            'G16 a declined widget → not answered' => [['plan' => 'answeredWidgetLeaf', 'messages' => 'profileChoiceDeclined', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G16'],
            // G17
            'G17 a social channel not connected' => [['plan' => 'socialConnectLeaf', 'messages' => 'socialConnectWidget', 'channelState' => false, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G17'],
            'G17 google not connected' => [['plan' => 'connectLeaf', 'messages' => 'connectWidget', 'channelState' => false, 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G17'],
            'G17 without the resolver callable → applied' => [['plan' => 'connectLeaf', 'messages' => 'connectWidget', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            // G18 / G21 / G22 / G23 secondary
            'G18 the disqualifying argument is named' => [['plan' => 'notApplicableNeedsCallLeafWithAbsentFilter', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'not_applicable', 'statusNote' => $note]]]], 'G18'],
            'G21 the phone line with a rendered offer → applied' => [['plan' => 'offerLeaf', 'messages' => 'connectWidget', 'phone' => true, 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requiresUserAction' => true]]]], 'G28'],
            'G22 a technical block (requiresUserAction false) on an offer leaf is gated too' => [['plan' => 'offerLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requiresUserAction' => false]]]], 'G22'],
            'G22 an offer leaf without a statement gate → no hint sentence' => [['plan' => 'offerLeafWithoutStatementGate', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note]]]], 'G22'],
            'G23 a technical block passes the prose-offer gate' => [['plan' => 'proseOfferLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requiresUserAction' => false, 'retryable' => true]]]], 'G28'],
            'G23 the owner\'s words with the wrong kind do not count' => [['plan' => 'proseOfferLeaf', 'messages' => 'ownerStatementOfAnotherKind', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requiresUserAction' => true]]]], 'G23'],
            // G24
            'G24 a leaf the platform resolved earlier is blocked for real' => [['plan' => 'recordClosedLeafResolvedByThePlatform', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'retryable' => true]]]], 'G28'],
            'G24 an offer leaf whose only gate is the owner\'s deferral is blocked for real' => [['plan' => 'proseOfferLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'retryable' => true]]]], 'G28'],
            'G24 a model-blocked leaf is re-opened with the obstacle as its note' => [['plan' => 'recordClosedLeafBlockedByTheModel', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => 'rate limit', 'retryable' => true]]]], 'G24'],
            // G26
            'G26 a container whose subtask is open → work the subtasks, not an ALREADY no-op' => [['plan' => 'containerWithOpenSubtask', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G26'],
            'G26 the lecture once per call, the short line after it' => [['plan' => 'twoDoneLeaves', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done'], ['task' => '2', 'status' => 'done', 'statusNote' => 'a refreshed note']]]], 'G26'],
            'G26 a platform-resolved note survives a re-tick note' => [['plan' => 'doneLeafResolvedAtSeed', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => 'the model\'s note']]]], 'G26'],
            'G26 ALREADY + an applied update → allAlreadyInStatus false' => [['plan' => 'twoDoneLeavesAndAnOpenOne', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done'], ['task' => '3', 'status' => 'done', 'statusNote' => $note]]]], 'G26'],
            // no judgement park — the done of a leaf without a record gate applies, seeded or not
            'G28 a seeded leaf without a record gate → applied' => [['plan' => 'seededPlainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G28 a model-created leaf ticked before any work call of the turn → applied' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            'G28 a model-created leaf after a returned work call → applied' => [['plan' => 'plainLeaf', 'messages' => 'updateCompetitorsDone', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
            // G28
            'G28 blocked writes requiresUserAction and retryable from the update' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'blocked', 'statusNote' => $note, 'requiresUserAction' => true, 'retryable' => true]]]], 'G28'],
            // QUIRK (not testable): the done branch's "keep the note on a done → done re-tick without a note" (`:865`) is dead —
            // a done → done tick always meets G26 first
            'G28 blocked → done drops the block note and the blocked flags' => [['plan' => 'blockedLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done']]]], 'G28'],
            'G28 done → open re-opens, clears the note, no resolution stamp' => [['plan' => 'doneLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'open']]]], 'G28'],
            // QUIRK: an open → open re-tick changes nothing but counts as an update and stamps updatedAt (G26 excludes open)
            'G28 open → open counts as an update' => [['plan' => 'plainLeaf', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'open', 'statusNote' => $note]]]], 'G28'],
            'G28 the platform provenance is cleared on a status change' => [['plan' => 'notApplicableLeafResolvedAtSeed', 'messages' => 'base', 'arguments' => ['updates' => [['task' => '1', 'status' => 'done', 'statusNote' => $note]]]], 'G28'],
        ];
    }

    /**
     * The gate a call reached, read from its feedback (in the method's gate order) and its counters. One label per
     * call: the first gate line found; a call with no gate line is G28 when it applied an update, NONE otherwise.
     *
     * @param array<string, mixed> $result
     */
    public static function classifyReachedGate(array $result): string
    {
        $feedbackText = implode("\n", $result['feedback']);
        $gateSignatures = [
            ['ADDRESS', SetTaskStatusAddressCheck::REJECTION_HEADER],
            ['G0', 'rejected: not an object.'],
            ['G1', "rejected: no 'task' address."],
            ['G2', 'not found (or ambiguous)'],
            ['G3', 'not recognized — use open|done|blocked|not_applicable'],
            ['G4', 'was closed by the platform in this turn'],
            ['G6', 'not_applicable update(s) ('],
            ['G5', 'blocked update(s) ('],
            ['G7', 'the record decides this item (criterion'],
            ['RECORD', 'the record decides this item —'],
            ['RECORD', 'confirmed by the record:'],
            ['G9', 'this item is decided by the purpose\'s record'],
            ['G10', 'the purpose\'s record shows this stage SATISFIED'],
            ['G11', 'decided by the purpose\'s record and never by the'],
            ['G12', 'the platform closes this item itself the moment your ANSWER TEXT'],
            ['G13', 'this item closes from the record of a completed'],
            ['G16', 'the owner answers ON the'],
            ['G14', 'done not applied — this step needs a widget in the app'],
            ['G15', 'The block, on its own lines in your answer text, is:'],
            ['G17', 'is NOT connected'],
            ['G17', 'could not be read right now'],
            ['G18', "this verdict needs the owner's answer persisted first"],
            ['G19', 'which contradicts "does not apply"'],
            ['G21', 'blocked not applied — this step needs a widget in the app'],
            ['G22', 'blocked here means the owner DEFERRED'],
            ['G23', 'blocked with requiresUserAction means the owner deferred'],
            ['G24', 'a transient obstacle never closes this item'],
            ['G25', 'a done item stays done'],
            ['G26', 'is ALREADY'],
        ];
        foreach ($gateSignatures as [$gateLabel, $gateSignature]) {
            if (str_contains($feedbackText, $gateSignature)) {
                return $gateLabel;
            }
        }
        return $result['updatedCount'] > 0 ? 'G28' : 'NONE';
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The purpose's criteria and the channel reader of a case
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * The purpose's criteria of a case that names a record answer (`criterionVerdict` and / or `doneCriterion`): every key
     * answers the record verdict when the case names one, else the done criterion's; null = the case names neither (no
     * criterion answers).
     */
    protected function buildRecordAnswers(): ?TaskPlanDoneCriteriaWithFixedVerdicts
    {
        if (!array_key_exists('criterionVerdict', $this->resolverAnswers) && !array_key_exists('doneCriterion', $this->resolverAnswers)) {
            return null;
        }
        return TaskPlanDoneCriteriaWithFixedVerdicts::answeringEveryKeyWith(
            self::buildPreResolution(($this->resolverAnswers['criterionVerdict'] ?? null) ?? ($this->resolverAnswers['doneCriterion'] ?? null))
        );
    }

    /** @param array{string, string}|null $preResolutionSpecification [status or 'stillOpen', note] */
    protected static function buildPreResolution(?array $preResolutionSpecification): ?TaskPlanDoneCriterionVerdict
    {
        if ($preResolutionSpecification === null) {
            return null;
        }
        [$preResolutionStatus, $preResolutionNote] = $preResolutionSpecification;
        return match ($preResolutionStatus) {
            'stillOpen' => TaskPlanDoneCriterionVerdict::stillOpen($preResolutionNote),
            TaskPlanItem::STATUS_DONE => TaskPlanDoneCriterionVerdict::done($preResolutionNote),
            TaskPlanItem::STATUS_NOT_APPLICABLE => TaskPlanDoneCriterionVerdict::notApplicable($preResolutionNote),
            TaskPlanItem::STATUS_BLOCKED => TaskPlanDoneCriterionVerdict::blockedByOwnerStatement($preResolutionNote),
        };
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Plans
    // ---------------------------------------------------------------------------------------------------------------

    protected function buildPlan(string $planName): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->buildItemSet(match ($planName) {
            'noItems' => [],
            'plainLeaf' => [$this->buildItem('Plain item'), $this->buildItem('Second item')],
            'seededPlainLeaf' => [$this->buildItem('Plain item', ['seededByPurpose' => true]), $this->buildItem('Second item', ['seededByPurpose' => true])],
            'blockedLeaf' => [$this->buildItem('Plain item', ['status' => TaskPlanItem::STATUS_BLOCKED, 'statusNote' => 'the old block', 'requiresUserAction' => true, 'retryable' => true])],
            'doneLeaf' => [$this->buildItem('Plain item', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'done earlier'])],
            'twoDoneLeaves' => [
                $this->buildItem('First', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'first done']),
                $this->buildItem('Second', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'second done']),
            ],
            'twoDoneLeavesAndAnOpenOne' => [
                $this->buildItem('First', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'first done']),
                $this->buildItem('Second', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'second done']),
                $this->buildItem('Third'),
            ],
            'doneLeafResolvedAtSeed' => [$this->buildItem('Keywords', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'already satisfied: 38/5 keywords', 'preResolvedAtSeed' => true, 'platformResolvedAtMessageIndex' => 0])],
            'notApplicableLeafResolvedAtSeed' => [$this->buildItem('Opening hours', ['status' => TaskPlanItem::STATUS_NOT_APPLICABLE, 'statusNote' => 'a pure online shop', 'preResolvedAtSeed' => true, 'platformResolvedAtMessageIndex' => 3])],
            'twoLeavesWithTheSameText' => [$this->buildItem('Same text'), $this->buildItem('same TEXT')],
            'containerWithOpenSubtask' => [$this->buildItem('Container', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'container note'], [
                $this->buildItem('Sub one', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'sub done']),
                $this->buildItem('Sub two'),
            ])],
            'fiveLeaves' => array_map(fn (int $itemNumber): TaskPlanItem => $this->buildItem('Leaf ' . $itemNumber), range(1, 5)),
            'tenLeaves' => array_map(fn (int $itemNumber): TaskPlanItem => $this->buildItem('Leaf ' . $itemNumber), range(1, 10)),
            'fiveLeavesFirstClosedByThePlatform' => array_merge(
                [$this->buildItem('Leaf 1', ['status' => TaskPlanItem::STATUS_BLOCKED, 'statusNote' => 'parked by the platform', 'platformResolvedAtMessageIndex' => 12])],
                array_map(fn (int $itemNumber): TaskPlanItem => $this->buildItem('Leaf ' . $itemNumber), range(2, 5))
            ),
            'echoLeaf' => [$this->buildItem('Handover', ['status' => TaskPlanItem::STATUS_DONE, 'statusNote' => 'closed by the platform: SetupHandover widget rendered', 'platformResolvedAtMessageIndex' => 12])],
            'criterionLeaf' => [$this->buildItem('Social links', ['doneCriterion' => 'profile.socialLinks'])],
            'enforcedCriterionLeaf' => [$this->buildItem('Build the site', ['doneCriterion' => 'site.built'])],
            'deliveryLeaf' => [$this->buildItem('Deliver the rankings', ['doneRequiresOwnerVisibleAnswerInSameTurn' => true, 'previousTurnDeliveryEvidenceToolNames' => ['getRankings']])],
            'deliveryLeafWithToolGate' => [$this->buildItem('Deliver the rankings', ['doneRequiresOwnerVisibleAnswerInSameTurn' => true, 'doneRequiresToolCall' => 'getRankings'])],
            'toolGateLeaf' => [$this->buildItem('Goal stated by the owner', ['doneRequiresToolCall' => 'addGoal', 'doneRequiresToolCallArgumentsAbsent' => ['statedByOwner' => [false]]])],
            'toolGateLeafWithRequiredArgument' => [$this->buildItem('Goal stated by the owner', ['doneRequiresToolCall' => 'addGoal', 'doneRequiresToolCallArgumentsAbsent' => ['statedByOwner' => [false]], 'doneRequiresToolCallArgumentsPresent' => ['kind' => ['VISIBILITY', 'CALLS']]])],
            'toolGateLeafWithAlternatives' => [$this->buildItem('Competitors', ['doneRequiresToolCall' => 'addCompetitors|updateCompetitors'])],
            'widgetLeaf' => [$this->buildItem('Handover widget', ['doneRequiresWidget' => 'SetupHandover'])],
            'filledWidgetLeaf' => [$this->buildItem('Handover widget', ['doneRequiresWidget' => 'SetupHandover', 'doneRequiresWidgetFilledFields' => ['promise' => '<what happens next>']])],
            'handoverLeafWithToolGate' => [$this->buildItem('Handover', ['doneRequiresToolCall' => 'scheduleOneTimeTask', 'doneRequiresWidget' => 'SetupHandover', 'doneRequiresWidgetFilledFields' => ['promise' => '<what happens next>']])],
            'answeredWidgetLeaf' => [$this->buildItem('Pick the profile', ['doneRequiresWidget' => 'ProfileChoice', 'doneRequiresWidgetAnswered' => true])],
            'offerWidgetIsTheDoneWidget' => [$this->buildItem('Directory offer', ['doneRequiresWidget' => 'ChannelConnections', 'blockedRequiresWidget' => 'ChannelConnections'])],
            'connectLeaf' => [$this->buildItem('Google connect offer', ['doneRequiresWidget' => 'ChannelConnections', 'doneRequiresConnectedChannel' => TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_GOOGLE])],
            'socialConnectLeaf' => [$this->buildItem('Social connect offer', ['doneRequiresWidget' => 'SocialConnections', 'doneRequiresConnectedChannel' => TaskPlanItemOfAnApplicationWithAGuidedSetup::CONNECTED_CHANNEL_SOCIAL])],
            'notApplicableNeedsCallLeaf' => [$this->buildItem('Service areas', ['notApplicableRequiresToolCall' => 'updateBusinessReach', 'notApplicableRequiresToolCallArgumentsPresent' => ['serveModel' => ['WALK_IN']]])],
            'notApplicableNeedsCallLeafWithAbsentFilter' => [$this->buildItem('Service areas', ['notApplicableRequiresToolCall' => 'addOwnerStatement', 'notApplicableRequiresToolCallArgumentsAbsent' => ['topics' => ['website']]])],
            'notApplicableForbiddenAfterCallLeaf' => [$this->buildItem('Social connect', ['notApplicableForbiddenAfterToolCall' => 'updateProfileSocialMediaLinks'])],
            'offerLeaf' => [$this->buildItem('Connect offer', ['blockedRequiresWidget' => 'ChannelConnections', 'blockedRequiresToolCall' => 'addOwnerStatement', 'blockedRequiresToolCallArgumentsPresent' => ['kind' => ['DEFERRAL', 'DECLINE']]])],
            'offerLeafWithoutStatementGate' => [$this->buildItem('Connect offer', ['blockedRequiresWidget' => 'ChannelConnections'])],
            'proseOfferLeaf' => [$this->buildItem('Website offer', ['blockedRequiresToolCall' => 'addOwnerStatement', 'blockedRequiresToolCallArgumentsPresent' => ['kind' => ['DEFERRAL', 'DECLINE']]])],
            'recordClosedLeaf' => [$this->buildItem('Audience stage', ['doneRequiresToolCall' => 'writeAudienceStage'])],
            'recordClosedLeafResolvedByThePlatform' => [$this->buildItem('Audience stage', ['doneRequiresToolCall' => 'writeAudienceStage', 'platformResolvedAtMessageIndex' => 4])],
            'recordClosedLeafBlockedByTheModel' => [$this->buildItem('Audience stage', ['doneRequiresToolCall' => 'writeAudienceStage', 'status' => TaskPlanItem::STATUS_BLOCKED, 'statusNote' => 'the old block', 'requiresUserAction' => true])],
            'setupStepLeaf' => [$this->buildItem('Basics confirmed', ['completesSetupStepName' => 'basics'])],
        });
        return $taskPlan;
    }

    /**
     * @param array<string, mixed> $fieldValues
     * @param TaskPlanItem[]|null $subtaskItems
     */
    protected function buildItem(string $text, array $fieldValues = [], ?array $subtaskItems = null): TaskPlanItem
    {
        $taskPlanItem = TaskPlanItem::newInstance();
        $taskPlanItem->text = $text;
        $taskPlanItem->status = TaskPlanItem::STATUS_OPEN;
        foreach ($fieldValues as $fieldName => $fieldValue) {
            $taskPlanItem->{$fieldName} = $fieldValue;
        }
        if ($subtaskItems !== null) {
            $taskPlanItem->subtasks = $this->buildItemSet($subtaskItems);
        }
        return $taskPlanItem;
    }

    /** @param TaskPlanItem[] $taskPlanItemList */
    protected function buildItemSet(array $taskPlanItemList): TaskPlanItems
    {
        $taskPlanItems = new TaskPlanItems();
        foreach ($taskPlanItemList as $taskPlanItem) {
            $taskPlanItems->add($taskPlanItem);
        }
        return $taskPlanItems;
    }

    /** @param array<string, mixed> $itemOverrides applied to item 1 */
    protected function applyItemOverrides(TaskPlan $taskPlan, array $itemOverrides): void
    {
        $firstItem = $taskPlan->items?->getItemByNumberPath('1');
        foreach ($itemOverrides as $fieldName => $fieldValue) {
            $firstItem->{$fieldName} = $fieldValue;
        }
    }

    /** @return array<string, array<string, mixed>> number path → the persisted fields without `subtasks` */
    protected function buildItemFieldsByNumberPath(?TaskPlanItems $taskPlanItems, string $numberPathPrefix = ''): array
    {
        $itemFieldsByNumberPath = [];
        foreach (array_values($taskPlanItems?->getElements() ?? []) as $position => $taskPlanItem) {
            $numberPath = ($numberPathPrefix === '' ? '' : $numberPathPrefix . '.') . ($position + 1);
            $itemFieldsByNumberPath[$numberPath] = array_diff_key($taskPlanItem->toRepositoryFields(), ['subtasks' => true]);
            $itemFieldsByNumberPath += $this->buildItemFieldsByNumberPath($taskPlanItem->subtasks, $numberPath);
        }
        return $itemFieldsByNumberPath;
    }

    /**
     * @param array<string, array<string, mixed>> $itemFieldsBefore
     * @param array<string, array<string, mixed>> $itemFieldsAfter
     * @return array<string, array<string, array{mixed, mixed}>> number path → field → [before, after], changed fields only
     */
    protected static function buildItemFieldChanges(array $itemFieldsBefore, array $itemFieldsAfter): array
    {
        $itemFieldChanges = [];
        foreach ($itemFieldsAfter as $numberPath => $fieldsAfter) {
            foreach ($fieldsAfter as $fieldName => $fieldValueAfter) {
                $fieldValueBefore = $itemFieldsBefore[$numberPath][$fieldName] ?? null;
                if ($fieldValueBefore !== $fieldValueAfter) {
                    $itemFieldChanges[$numberPath][$fieldName] = [$fieldValueBefore, $fieldValueAfter];
                }
            }
        }
        return $itemFieldChanges;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Conversations — the owner's text at index 10 starts the current turn
    // ---------------------------------------------------------------------------------------------------------------

    protected function buildMessages(string $messagesName): AIConversationMessages
    {
        $baseMessages = [
            $this->buildOwnerText(1, 'Hello.'),
            $this->buildAssistantText(2, 'Hi, let us start.'),
            $this->buildOwnerText(10, 'Go on.'),
        ];
        $connectWidgetText = "Connect here:\n```widget\n{\"type\": \"ChannelConnections\", \"shopId\": 4711}\n```";
        return $this->buildMessageSet(match ($messagesName) {
            'base' => $baseMessages,
            'earlierTickInTurn' => array_merge($baseMessages, [$this->buildToolCallRow(11, SetTaskStatusTool::NAME, ['updates' => [['task' => '1', 'status' => 'done']]])]),
            'previousTurnDelivery' => [
                $this->buildOwnerText(1, 'How are my rankings?'),
                $this->buildToolCallRow(3, 'getRankings'),
                $this->buildAssistantText(4, 'You rank 3rd for "bakery berlin".'),
                $this->buildOwnerText(10, 'Thanks.'),
            ],
            'sameTurnAnswer' => array_merge($baseMessages, [$this->buildAssistantText(11, 'Here is the result.')]),
            'answerBeforeEvidenceCall' => array_merge($baseMessages, [
                $this->buildAssistantText(11, 'Here is the result.'),
                $this->buildToolCallRow(12, 'getRankings'),
            ]),
            'addGoalByOwner' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'addGoal', ['statedByOwner' => true])]),
            'addGoalProposal' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'addGoal', ['statedByOwner' => false, 'kind' => 'VISIBILITY'])]),
            'addGoalFailed' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'addGoal', ['statedByOwner' => true], AIConversationMessage::STATUS_FAILED)]),
            'updateCompetitorsDone' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'updateCompetitors')]),
            'handoverWidget' => array_merge($baseMessages, [$this->buildAssistantText(11, "Your handover:\n```widget\n{\"type\": \"SetupHandover\", \"shopId\": 4711}\n```")]),
            'handoverWidgetFilled' => array_merge($baseMessages, [$this->buildAssistantText(11, "Your handover:\n```widget\n{\"type\": \"SetupHandover\", \"shopId\": 4711, \"promise\": \"We call you on Monday\"}\n```")]),
            'profileChoiceAnswered' => [
                $this->buildOwnerText(1, 'Hello.'),
                $this->buildAssistantText(5, "Which one is yours?\n```widget\n{\"type\": \"ProfileChoice\", \"shopId\": 4711}\n```"),
                $this->buildOwnerText(10, 'The first one.'),
            ],
            'profileChoiceWaiting' => array_merge($baseMessages, [$this->buildAssistantText(11, "Which one is yours?\n```widget\n{\"type\": \"ProfileChoice\", \"shopId\": 4711}\n```")]),
            'profileChoiceDeclined' => array_merge($baseMessages, [
                $this->buildAssistantText(11, "Which one is yours?\n```widget\n{\"type\": \"ProfileChoice\", \"shopId\": 4711}\n```"),
                $this->buildWidgetClickSignal(12, 'ProfileChoice declined'),
            ]),
            'connectWidget' => array_merge($baseMessages, [$this->buildAssistantText(11, $connectWidgetText)]),
            'socialConnectWidget' => array_merge($baseMessages, [$this->buildAssistantText(11, "Connect here:\n```widget\n{\"type\": \"SocialConnections\", \"shopId\": 4711}\n```")]),
            'walkInReach' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'updateBusinessReach', ['serveModel' => 'WALK_IN'])]),
            'socialLinksSaved' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'updateProfileSocialMediaLinks')]),
            'ownerDecline' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'addOwnerStatement', ['kind' => 'DECLINE', 'topics' => ['social']])]),
            'ownerStatementOfAnotherKind' => array_merge($baseMessages, [$this->buildToolCallRow(11, 'addOwnerStatement', ['kind' => 'PREFERENCE', 'topics' => ['social']])]),
        });
    }

    protected function buildOwnerText(int $index, string $text): AIConversationMessage
    {
        return $this->buildTextRow($index, $text, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER);
    }

    protected function buildAssistantText(int $index, string $text): AIConversationMessage
    {
        return $this->buildTextRow($index, $text, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT);
    }

    protected function buildTextRow(int $index, string $text, string $role, string $authorType): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->role = $role;
        $message->authorType = $authorType;
        return $message;
    }

    protected function buildWidgetClickSignal(int $index, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->role = AIConversationMessage::ROLE_SYSTEM;
        $message->signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        $message->message = $text;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function buildToolCallRow(int $index, string $toolName, array $arguments = [], string $status = AIConversationMessage::STATUS_COMPLETED): AIConversationMessage
    {
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = $status;
        $toolCall->result = '{"content":[]}';
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param AIConversationMessage[] $messageList */
    protected function buildMessageSet(array $messageList): AIConversationMessages
    {
        $conversationMessages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $conversationMessages->add($message);
        }
        return $conversationMessages;
    }
}
