<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `wait_for_sub_agents` after every sub-agent ended: a request that declared its next step names it and
 * leaves the next step free for a tool — a research root had been forced into a tool-less answer and never closed
 * its research; without a declared step the turn answers, as before.
 */
class WaitForSubAgentsDeclaredNextStepTest extends UnitTestCase
{
    public function testADeclaredNextStepIsNamedAndNoToolLessAnswerIsForced(): void
    {
        $answer = WaitForSubAgentsTool::answerCall(true, false, new DelegationSubAgentRecords(), null, 'The research is back. Call finishLocalSignalResearch ONCE now.');
        self::assertStringContainsString('Call finishLocalSignalResearch ONCE now.', $answer->text);
        self::assertFalse($answer->forcesToolLessAnswerStep);
    }

    public function testWithoutADeclaredNextStepTheTurnAnswers(): void
    {
        $answer = WaitForSubAgentsTool::answerCall(true, false, new DelegationSubAgentRecords());
        self::assertStringContainsString('Answer the owner now.', $answer->text);
    }

    public function testTheNewestDeclaredNextStepIsFound(): void
    {
        $records = new DelegationSubAgentRecords();
        foreach ([null, 'first step', 'newest step'] as $position => $nextStep) {
            $record = new DelegationSubAgentRecord();
            $record->number = $position + 1;
            $record->nextStepWhenAllSubAgentsOfRequestEnded = $nextStep;
            $records->add($record);
        }
        self::assertSame('newest step', $records->findNewestDeclaredNextStep());
        self::assertNull((new DelegationSubAgentRecords())->findNewestDeclaredNextStep());
    }
}
