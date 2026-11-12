<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Slice7Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentSeededPlanPurposeFacts;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The two seeded-plan refusals after their move, against the dispatch's former name tests kept verbatim
 * as the reference (`AgentToolCallDispatchService` before the move):
 * - `delegate`: `$allowThreads && conversationPurposeSeedsItsTaskPlan && !isNextPlanItemInThePurposesClosingAct()`;
 * - `update_task_plan`: `conversationPurposeSeedsItsTaskPlan && getTaskPlan() !== null`, plus the outcome `refused`.
 * Every combination of the four facts is decided by both. The conditions differ on purpose (the delegate refusal needs
 * no plan, the rewrite refusal ignores the closing act and the delegation right) and stay different.
 */
class AgentSeededPlanRefusalsDifferentialTest extends UnitTestCase
{
    protected const array META_TOOL_NAMES = ['load_skills', 'load_tool', 'delegate', 'check_delegations', 'update_task_plan', 'set_task_status', 'get_task_plans', 'send_owner_report', 'askOwner'];

    /** @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool}> seeds, has a plan, next item in the closing act, may delegate */
    public static function factCombinations(): array
    {
        $combinations = [];
        foreach ([false, true] as $purposeSeedsItsTaskPlan) {
            foreach ([false, true] as $hasTaskPlan) {
                foreach ([false, true] as $nextPlanItemIsInClosingAct) {
                    foreach ([false, true] as $allowThreads) {
                        $combinations[sprintf('seeds=%d plan=%d closingAct=%d allowThreads=%d', $purposeSeedsItsTaskPlan, $hasTaskPlan, $nextPlanItemIsInClosingAct, $allowThreads)]
                            = [$purposeSeedsItsTaskPlan, $hasTaskPlan, $nextPlanItemIsInClosingAct, $allowThreads];
                    }
                }
            }
        }
        return $combinations;
    }

    /** The facts as the dispatch gathers them: the plan facts are read only under a seeding purpose. */
    protected static function facts(bool $purposeSeedsItsTaskPlan, bool $hasTaskPlan, bool $nextPlanItemIsInClosingAct, bool $allowThreads): AgentSeededPlanPurposeFacts
    {
        return $purposeSeedsItsTaskPlan
            ? AgentSeededPlanPurposeFacts::withSeededPlan($hasTaskPlan, $nextPlanItemIsInClosingAct, $allowThreads)
            : AgentSeededPlanPurposeFacts::withoutSeededPlan($allowThreads);
    }

    #[DataProvider('factCombinations')]
    public function testBothRefusalsDecideAsTheFormerNameTests(bool $purposeSeedsItsTaskPlan, bool $hasTaskPlan, bool $nextPlanItemIsInClosingAct, bool $allowThreads): void
    {
        $agentSeededPlanPurposeFacts = self::facts($purposeSeedsItsTaskPlan, $hasTaskPlan, $nextPlanItemIsInClosingAct, $allowThreads);

        // the reference: the former dispatch conditions, verbatim in their boolean form
        $referenceRefusesDelegate = $allowThreads && $purposeSeedsItsTaskPlan && !$nextPlanItemIsInClosingAct;
        $referenceRefusesPlanRewrite = $purposeSeedsItsTaskPlan && $hasTaskPlan;

        $delegateRefusal = DelegateTool::findSeededPlanRefusal($agentSeededPlanPurposeFacts);
        self::assertSame($referenceRefusesDelegate, $delegateRefusal !== null, 'delegate');
        if ($delegateRefusal !== null) {
            self::assertEquals(DelegateTool::answerSeededPurposeRefusal(), $delegateRefusal);
            self::assertFalse($delegateRefusal->failed);
            self::assertFalse($delegateRefusal->forcesToolLessAnswerStep);
            self::assertNull($delegateRefusal->taskPlanCallOutcome);
        }

        $planRewriteRefusal = UpdateTaskPlanTool::findSeededPlanRefusal($agentSeededPlanPurposeFacts);
        self::assertSame($referenceRefusesPlanRewrite, $planRewriteRefusal !== null, 'update_task_plan');
        if ($planRewriteRefusal !== null) {
            self::assertSame(UpdateTaskPlanTool::SEEDED_PLAN_REWRITE_REFUSAL_TEXT, $planRewriteRefusal->text);
            self::assertFalse($planRewriteRefusal->failed);
            self::assertFalse($planRewriteRefusal->forcesToolLessAnswerStep);
            self::assertSame(ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED, $planRewriteRefusal->taskPlanCallOutcome);
        }
    }

    /** The two conditions differ: a delegate refusal without a plan, a rewrite refusal inside the closing act. */
    public function testTheTwoConditionsStayDifferent(): void
    {
        $withoutPlan = AgentSeededPlanPurposeFacts::withSeededPlan(false, false, true);
        self::assertNotNull(DelegateTool::findSeededPlanRefusal($withoutPlan));
        self::assertNull(UpdateTaskPlanTool::findSeededPlanRefusal($withoutPlan));

        $closingActWithPlan = AgentSeededPlanPurposeFacts::withSeededPlan(true, true, true);
        self::assertNull(DelegateTool::findSeededPlanRefusal($closingActWithPlan));
        self::assertNotNull(UpdateTaskPlanTool::findSeededPlanRefusal($closingActWithPlan));
    }

    /** The meta tools the dispatch asks for a seeded-plan refusal — today's two names, nothing else. */
    public function testOnlyDelegateAndUpdateTaskPlanRefuseUnderASeededPlan(): void
    {
        $refusingToolNames = [];
        foreach ([...self::META_TOOL_NAMES, 'getKeywords', 'addKeywords', '', 'noSuchTool'] as $toolName) {
            $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
            $toolCall->name = $toolName;
            if (AgentMetaTools::findSeededPlanRefusingToolClass($toolCall) !== null) {
                $refusingToolNames[] = $toolName;
            }
        }
        self::assertSame(['delegate', 'update_task_plan'], $refusingToolNames);
    }
}
