<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentStallRecoveryDecision;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentStallRecoveryDecision;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/** The stall-recovery question follows the stall-recovery rules and is built over the OFFERED moves only. */
class ArgusAgentStallRecoveryDecisionTest extends UnitTestCase
{
    public function testTheQuestionIsBuiltOverTheOfferedMovesOnlyAndBackticksEveryStatePath(): void
    {
        $offered = [AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, AgentStallRecoveryDecision::MOVE_NONE];
        $questions = ArgusAgentStallRecoveryDecision::buildQuestions($offered);
        self::assertSame([ArgusAgentStallRecoveryDecision::QUESTION_NEXT_MOVE], array_keys($questions));
        $question = $questions[ArgusAgentStallRecoveryDecision::QUESTION_NEXT_MOVE];
        self::assertSame('choice', $question['type']);
        self::assertSame($offered, array_keys($question['criteria']), 'a removed move is not a choice');
        foreach (['stallShape', 'lastAssistantText', 'ownerMessage', 'nextPlanItem', 'residentToolNames', 'loadableCapabilityNames'] as $statePath) {
            self::assertStringContainsString('`' . $statePath . '`', $question['instructions'], $statePath);
        }
        // rule 5: the blind spot is named
        self::assertStringContainsString('do not assume them', $question['instructions']);
        $all = ArgusAgentStallRecoveryDecision::buildQuestions(AgentStallRecoveryDecision::MOVES)[ArgusAgentStallRecoveryDecision::QUESTION_NEXT_MOVE]['criteria'];
        self::assertStringContainsString('`residentToolNames`', $all[AgentStallRecoveryDecision::MOVE_CALL_A_WORK_TOOL]);
        self::assertStringContainsString('`loadableCapabilityNames`', $all[AgentStallRecoveryDecision::MOVE_LOAD_REQUIRED_CAPABILITY]);
        self::assertStringContainsString('consent', $all[AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT], 'the text move covers the ask-the-owner case');
    }

    public function testTheStateIsCappedAndAnAnswerOutsideTheOfferedSetDegradesToNone(): void
    {
        $decision = (new ReflectionClass(ArgusAgentStallRecoveryDecision::class))->newInstanceWithoutConstructor();
        $decision->stallShape = AgentStallRecoveryDecision::SHAPE_EMPTY;
        $decision->lastAssistantText = str_repeat('x', 500);
        $decision->ownerMessage = str_repeat('o', 900);
        $decision->residentToolNames = array_map(static fn (int $i): string => 'tool' . $i, range(1, 40));
        $decision->offeredMoves = [AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, AgentStallRecoveryDecision::MOVE_NONE];
        $state = $decision->buildState();
        self::assertSame(300, mb_strlen($state['lastAssistantText']));
        self::assertSame(600, mb_strlen($state['ownerMessage']));
        self::assertCount(30, $state['residentToolNames']);

        $applyAnswers = new ReflectionMethod(ArgusAgentStallRecoveryDecision::class, 'applyAnswers');
        $applyAnswers->setAccessible(true);
        self::assertTrue($applyAnswers->invoke($decision, (object)['next_move' => (object)['choice' => 'call_a_work_tool', 'confidence' => 0.9]]));
        self::assertSame(AgentStallRecoveryDecision::MOVE_NONE, $decision->move, 'a move that was not offered is never returned');
        self::assertTrue($applyAnswers->invoke($decision, (object)['next_move' => (object)['choice' => 'End_The_Turn_With_Text', 'confidence' => 0.8]]));
        self::assertSame(AgentStallRecoveryDecision::MOVE_END_THE_TURN_WITH_TEXT, $decision->move);
        self::assertSame(0.8, $decision->moveConfidence);
        self::assertFalse($applyAnswers->invoke($decision, (object)[]), 'a missing answer is unusable');
    }
}
