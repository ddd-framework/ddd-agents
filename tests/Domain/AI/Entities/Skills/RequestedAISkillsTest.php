<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Skills;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A resolved `load_skills` request holds the skills its names resolved to as a set read by the skill's name: a name
 * that resolved to no skill is unknown, and a later admission pass over fewer names reads the same resolved skills.
 */
class RequestedAISkillsTest extends UnitTestCase
{
    public function testAResolvedSkillIsFoundByItsNameAndAnUnresolvedNameIsUnknown(): void
    {
        $requestedAISkills = new RequestedAISkills();
        self::assertNull($requestedAISkills->getSkill('Presence.Profile'));
        self::assertSame([], $requestedAISkills->getResolvedSkills());

        $profileSkill = $this->skillNamed('Presence.Profile');
        $reviewsSkill = $this->skillNamed('Reputation.Reviews');
        $requestedAISkills->addResolvedSkill($profileSkill);
        $requestedAISkills->addResolvedSkill($reviewsSkill);

        self::assertSame($profileSkill, $requestedAISkills->getSkill('Presence.Profile'));
        self::assertSame($reviewsSkill, $requestedAISkills->getSkill('Reputation.Reviews'));
        self::assertNull($requestedAISkills->getSkill('Seo.Rankings'));
        self::assertSame([$profileSkill, $reviewsSkill], $requestedAISkills->getResolvedSkills(), 'in the order they were recorded');
    }

    public function testASkillRecordedTwiceUnderTheSameNameIsHeldOnce(): void
    {
        $requestedAISkills = new RequestedAISkills();
        $profileSkill = $this->skillNamed('Presence.Profile');
        $requestedAISkills->addResolvedSkill($profileSkill);
        $requestedAISkills->addResolvedSkill($this->skillNamed('Presence.Profile'));

        self::assertSame([$profileSkill], $requestedAISkills->getResolvedSkills());
    }

    public function testALaterAdmissionPassOverFewerNamesReadsTheSameResolvedSkills(): void
    {
        $requestedAISkills = new RequestedAISkills();
        $requestedAISkills->requestedSkillNames = ['Presence.Profile', 'Reputation.Reviews'];
        $profileSkill = $this->skillNamed('Presence.Profile');
        $requestedAISkills->addResolvedSkill($profileSkill);
        $requestedAISkills->addResolvedSkill($this->skillNamed('Reputation.Reviews'));

        $laterAdmissionPass = $requestedAISkills->withRequestedSkillNames(['Presence.Profile']);

        self::assertSame(['Presence.Profile'], $laterAdmissionPass->requestedSkillNames);
        self::assertSame(['Presence.Profile', 'Reputation.Reviews'], $requestedAISkills->requestedSkillNames);
        self::assertSame($profileSkill, $laterAdmissionPass->getSkill('Presence.Profile'));
        self::assertCount(2, $laterAdmissionPass->getResolvedSkills());
    }

    protected function skillNamed(string $skillName): AISkill
    {
        $aiSkill = new AISkill();
        $aiSkill->name = $skillName;
        return $aiSkill;
    }
}
