<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolResultImagesOfToolCalls;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSiteInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSubSkillQuestions;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The sets of the agent loop that are read by a key: the images sent with a tool result by tool call id and the
 * sub-skill questions of a turn start by loaded skill name.
 */
class AgentSetsReadByKeyTest extends UnitTestCase
{
    public function testTheImagesOfAToolCallAreFoundByItsIdInTheOrderTheCallsWereAdded(): void
    {
        $firstImage = $this->image();
        $secondImage = $this->image();
        $toolResultImagesOfToolCalls = new AgentToolResultImagesOfToolCalls();
        $toolResultImagesOfToolCalls->addImagesOfToolCall('call-new', [$firstImage, $secondImage]);
        $toolResultImagesOfToolCalls->addImagesOfToolCall('12', [$firstImage]);

        self::assertSame(['call-new', '12'], $toolResultImagesOfToolCalls->getToolCallIds(), 'a numeric id stays a string');
        self::assertSame([$firstImage, $secondImage], $toolResultImagesOfToolCalls->getImagesOfToolCall('call-new'));
        self::assertSame([$firstImage], $toolResultImagesOfToolCalls->getImagesOfToolCall('12'));
        self::assertSame([], $toolResultImagesOfToolCalls->getImagesOfToolCall('call-old'));
    }

    public function testTheSubSkillQuestionOfALoadedSkillIsFoundByTheSkillName(): void
    {
        $siteInputs = new AgentTurnStartSiteInputs();
        self::assertTrue($siteInputs->loadedSkillSubSkillQuestions->isEmpty());

        $profileQuestion = new AgentSkillPreloadDecisionQuestion();
        $reviewsQuestion = new AgentSkillPreloadDecisionQuestion();
        $subSkillQuestions = new AgentTurnStartSubSkillQuestions();
        $subSkillQuestions->addQuestionOfLoadedSkill('Presence.Profile', $profileQuestion);
        $subSkillQuestions->addQuestionOfLoadedSkill('Reputation.Reviews', $reviewsQuestion);
        $siteInputs->loadedSkillSubSkillQuestions = $subSkillQuestions;

        self::assertFalse($siteInputs->asksNothing());
        self::assertSame(['Presence.Profile', 'Reputation.Reviews'], $subSkillQuestions->getLoadedSkillNames());
        self::assertSame($reviewsQuestion, $subSkillQuestions->getQuestionOfLoadedSkill('Reputation.Reviews'));
        self::assertNull($subSkillQuestions->getQuestionOfLoadedSkill('Seo.Keywords'));
    }

    protected function image(): ToolResultImage
    {
        /** @var ToolResultImage $toolResultImage */
        $toolResultImage = (new ReflectionClass(ToolResultImage::class))->newInstanceWithoutConstructor();
        $toolResultImage->base64Data = 'QUJD';
        $toolResultImage->mimeType = 'image/png';
        return $toolResultImage;
    }
}
