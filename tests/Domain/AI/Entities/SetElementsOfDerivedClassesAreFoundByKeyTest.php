<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswers;
use DDD\Domain\AI\Entities\Conversations\Purposes\Runs\AIConversationPurposeCriterionReview;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRun;
use DDD\Domain\AI\Entities\Conversations\Recovery\AIConversationStuckRuns;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResult;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResults;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A set that finds its element by a name or an id finds it too when the element is of a class an application derived
 * from the package's class: the key such a set looks up never contains the derived class's name.
 */
class SetElementsOfDerivedClassesAreFoundByKeyTest extends UnitTestCase
{
    public function testAProbeAnswerOfADerivedClassIsFoundByItsQuestionName(): void
    {
        $probeDecisionAnswer = AgentProbeDecisionAnswerDerivedByAnApplication::forYesProbability('is_urgent', 0.4);
        $probeDecisionAnswers = new AgentProbeDecisionAnswers();
        $probeDecisionAnswers->add($probeDecisionAnswer);

        self::assertSame($probeDecisionAnswer, $probeDecisionAnswers->getAnswerOfQuestion('is_urgent'));
        self::assertSame(AgentProbeDecisionAnswer::uniqueKeyOfQuestion('is_urgent'), $probeDecisionAnswer->uniqueKey());
    }

    public function testAQuestionNamedZeroHasItsOwnKey(): void
    {
        self::assertNotSame(AgentProbeDecisionAnswer::uniqueKeyOfQuestion(''), AgentProbeDecisionAnswer::uniqueKeyOfQuestion('0'));
    }

    public function testAToolCallResultOfADerivedClassIsFoundByItsToolCallId(): void
    {
        $composedViewToolCallResult = new ComposedViewToolCallResultDerivedByAnApplication();
        $composedViewToolCallResult->toolCallId = 'call-7';
        $composedViewToolCallResults = new ComposedViewToolCallResults();
        $composedViewToolCallResults->addToolCallResult($composedViewToolCallResult);

        self::assertTrue($composedViewToolCallResults->knowsToolCallId('call-7'));
        self::assertSame($composedViewToolCallResult, $composedViewToolCallResults->findToolCallResult('call-7'));
    }

    public function testAStuckRunOfADerivedClassIsFoundByItsConversationId(): void
    {
        $stuckRun = AIConversationStuckRunDerivedByAnApplication::forConversation(912951);
        $stuckRuns = new AIConversationStuckRuns();
        $stuckRuns->add($stuckRun);

        self::assertSame($stuckRun, $stuckRuns->findOrAddStuckRun(912951));
        self::assertSame(1, $stuckRuns->count());
    }

    public function testACriterionReviewOfADerivedClassHasTheKeyOfThePackageClass(): void
    {
        $criterionReview = AIConversationPurposeCriterionReviewDerivedByAnApplication::forKey('profile_complete', true, 'Complete the profile');

        self::assertSame(AIConversationPurposeCriterionReview::uniqueKeyOfDoneCriterionKey('profile_complete'), $criterionReview->uniqueKey());
    }

    public function testAResolvedSkillOfADerivedClassIsFoundByItsName(): void
    {
        $aiSkill = new AISkillDerivedByAnApplication();
        $aiSkill->name = 'Presence.Profile';
        $requestedAISkills = new RequestedAISkills();
        $requestedAISkills->addResolvedSkill($aiSkill);

        self::assertSame($aiSkill, $requestedAISkills->getSkill('Presence.Profile'));
    }
}

class AgentProbeDecisionAnswerDerivedByAnApplication extends AgentProbeDecisionAnswer
{
}

class ComposedViewToolCallResultDerivedByAnApplication extends ComposedViewToolCallResult
{
}

class AIConversationStuckRunDerivedByAnApplication extends AIConversationStuckRun
{
}

class AIConversationPurposeCriterionReviewDerivedByAnApplication extends AIConversationPurposeCriterionReview
{
}

class AISkillDerivedByAnApplication extends AISkill
{
}
