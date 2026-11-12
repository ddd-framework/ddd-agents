<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswers;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentProbeDecision;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The answers of a probe request are a set read by the question's name: every answer names its question, a question
 * the model did not answer has none, and a second reading replaces the earlier answer of the same question.
 */
class ArgusAgentProbeDecisionTest extends UnitTestCase
{
    public function testEveryAnsweredQuestionHasOneAnswerFoundByTheQuestionName(): void
    {
        $probeDecision = $this->probeDecisionWithQuestions();
        self::assertNull($probeDecision->probeAnswers, 'no answer was read yet');

        $answers = (object)[
            'is_urgent' => (object)['noul' => 0.83],
            'next_step' => (object)['choice' => 'Ask', 'confidence' => 0.7],
        ];
        self::assertTrue(self::invokeMethod($probeDecision, 'applyAnswers', [$answers]));

        self::assertInstanceOf(AgentProbeDecisionAnswers::class, $probeDecision->probeAnswers);
        self::assertSame(2, $probeDecision->probeAnswers->count());
        $urgencyAnswer = $probeDecision->probeAnswers->getAnswerOfQuestion('is_urgent');
        self::assertInstanceOf(AgentProbeDecisionAnswer::class, $urgencyAnswer);
        self::assertSame('is_urgent', $urgencyAnswer->questionName);
        self::assertTrue($urgencyAnswer->isYesProbability());
        self::assertSame(0.83, $urgencyAnswer->yesProbability);
        $nextStepAnswer = $probeDecision->probeAnswers->getAnswerOfQuestion('next_step');
        self::assertSame('next_step', $nextStepAnswer->questionName);
        self::assertSame('ask', $nextStepAnswer->choice);
        self::assertSame(0.7, $nextStepAnswer->confidence);
        self::assertNull($probeDecision->probeAnswers->getAnswerOfQuestion('is_spam'), 'the model gave no answer to this question');
    }

    public function testWithoutAReadableAnswerTheSetIsEmptyAndTheDecisionReportsNoAnswer(): void
    {
        $probeDecision = $this->probeDecisionWithQuestions();

        self::assertFalse(self::invokeMethod($probeDecision, 'applyAnswers', [(object)['is_urgent' => (object)['noul' => 'often']]]));
        self::assertTrue($probeDecision->probeAnswers->isEmpty());
    }

    public function testASecondReadingReplacesTheEarlierAnswerOfTheSameQuestion(): void
    {
        $probeDecision = $this->probeDecisionWithQuestions();
        self::invokeMethod($probeDecision, 'applyAnswers', [(object)['is_urgent' => (object)['noul' => 0.2]]]);
        self::invokeMethod($probeDecision, 'applyAnswers', [(object)['is_urgent' => (object)['noul' => 0.9]]]);

        self::assertSame(1, $probeDecision->probeAnswers->count());
        self::assertSame(0.9, $probeDecision->probeAnswers->getAnswerOfQuestion('is_urgent')->yesProbability);
    }

    protected function probeDecisionWithQuestions(): ArgusAgentProbeDecision
    {
        /** @var ArgusAgentProbeDecision $probeDecision */
        $probeDecision = (new ReflectionClass(ArgusAgentProbeDecision::class))->newInstanceWithoutConstructor();
        $probeDecision->probeQuestions = [
            'is_urgent' => ['type' => 'noul'],
            'is_spam' => ['type' => 'noul'],
            'next_step' => ['type' => 'choice', 'criteria' => ['ask' => 'Ask the owner.', 'act' => 'Act now.']],
        ];
        return $probeDecision;
    }
}
