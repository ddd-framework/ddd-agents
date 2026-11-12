<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Presentation\Api\Agents\QueryOptions\ApplicationConversationFixture;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The conversation reads hide properties class-wide: the model-generated summary (reading it would start a model
 * call per row), and for a customer the run token, the budget bypass and the raw task plan. The framework checks a
 * class-wide hide against the class of the serialized object only. An application that derives the conversation
 * serializes objects of ITS class, so the hide has to be registered there too — or those fields go out to customers.
 */
class ClassWideHidesReachTheApplicationsClassTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    /** @var array<string, array<string, bool>> */
    protected array $propertiesToHideOnSerializationBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        $this->propertiesToHideOnSerializationBefore = StaticRegistry::$propertiesToHideOnSerialization;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        StaticRegistry::$propertiesToHideOnSerialization = $this->propertiesToHideOnSerializationBefore;
        parent::tearDown();
    }

    public function testAHideRegisteredOnTheModulesClassAloneLeavesTheApplicationsObjectVisible(): void
    {
        AIConversation::addStaticPropertiesToHide(true, 'runToken');

        self::assertFalse((new AIConversation())->isPropertyVisible('runToken'));
        self::assertTrue(
            (new ApplicationConversationFixture())->isPropertyVisible('runToken'),
            'the framework behaviour the controller traits have to work around'
        );
    }

    public function testTheControllerHidesThePropertyOnTheModulesObjectAndOnTheApplicationsObject(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = ApplicationConversationFixture::class;

        (new AgentConversationTraitsFixtureController())->callHidePropertiesOnEveryObjectOfClass(AIConversation::class, 'summary');

        self::assertFalse((new AIConversation())->isPropertyVisible('summary'));
        self::assertFalse((new ApplicationConversationFixture())->isPropertyVisible('summary'));
        self::assertTrue((new ApplicationConversationFixture())->isPropertyVisible('title'));
    }

    public function testWithoutAClassOfTheApplicationTheHideIsRegisteredOnTheModulesClass(): void
    {
        (new AgentConversationTraitsFixtureController())->callHidePropertiesOnEveryObjectOfClass(AIConversation::class, 'summary');

        self::assertFalse((new AIConversation())->isPropertyVisible('summary'));
    }

    public function testACustomerNeverGetsTheRunTokenTheBudgetBypassOrTheTaskPlanOfTheApplicationsConversation(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = ApplicationConversationFixture::class;

        (new AgentConversationTraitsFixtureController())->callHideAgentForensicsUnlessImpersonating();

        $applicationConversation = new ApplicationConversationFixture();
        foreach (['budgetBypass', 'runToken', 'agentWorkingState'] as $hiddenPropertyName) {
            self::assertFalse($applicationConversation->isPropertyVisible($hiddenPropertyName), $hiddenPropertyName);
        }
    }
}
