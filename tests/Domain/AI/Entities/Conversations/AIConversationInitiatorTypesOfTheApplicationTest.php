<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationOrigin;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * The module starts runs by a schedule and by an admin task. An application declares the initiator types of the runs
 * it starts itself in a conversation class at the same path and redeclares the two lists; the origin of a
 * conversation asks that class.
 */
class AIConversationInitiatorTypesOfTheApplicationTest extends UnitTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::tearDown();
    }

    public function testTheModuleKnowsFourInitiatorTypes(): void
    {
        $initiatorTypesOfTheModule = [];
        foreach ((new ReflectionClass(AIConversation::class))->getConstants() as $constantName => $constantValue) {
            if (str_starts_with($constantName, 'INITIATOR_TYPE_')) {
                $initiatorTypesOfTheModule[] = $constantValue;
            }
        }
        sort($initiatorTypesOfTheModule);

        self::assertSame(['ADMIN_TASK', 'EXTERNAL_AGENT', 'PHONE_CALL', 'SCHEDULE'], $initiatorTypesOfTheModule);
    }

    public function testWithoutAnApplicationClassAScheduleAndAnAdminTaskAreTheRunsWithoutTheOwner(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = AIConversation::class;

        self::assertSame(AIConversation::class, AIConversation::getConversationClassOfTheApplication());
        self::assertSame(['SCHEDULE', 'ADMIN_TASK'], AIConversation::getOwnerAbsentInitiatorTypes());
        self::assertSame([], AIConversation::getPlatformOwnRunInitiatorTypes());
        self::assertFalse($this->buildOrigin('NIGHTLY_DIGEST')->isPlatformOwnRun());
        self::assertNull($this->buildOrigin('NIGHTLY_DIGEST')->getReasonItIsNotTheOwnersConversation());
        self::assertSame(OwnerPresence::ABSENT_UNKNOWN_ORIGIN, $this->buildOrigin('NIGHTLY_DIGEST')->buildOwnerPresence(null, null));
        self::assertSame(
            AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN,
            $this->buildOrigin(AIConversation::INITIATOR_TYPE_SCHEDULE)->getReasonItIsNotTheOwnersConversation()
        );
        self::assertNull($this->buildOrigin(AIConversation::INITIATOR_TYPE_EXTERNAL_AGENT)->getReasonItIsNotTheOwnersConversation());
        self::assertNull($this->buildOrigin(null)->getReasonItIsNotTheOwnersConversation());
    }

    public function testTheRunsOfAnApplicationAnswerThroughTheModulesClassName(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = AIConversationOfAnApplicationWithANightlyDigestRun::class;

        self::assertSame(AIConversationOfAnApplicationWithANightlyDigestRun::class, AIConversation::getConversationClassOfTheApplication());
        self::assertSame(['SCHEDULE', 'ADMIN_TASK', 'NIGHTLY_DIGEST', 'REMINDER'], AIConversation::getOwnerAbsentInitiatorTypes());
        self::assertSame(AIConversation::getOwnerAbsentInitiatorTypes(), AIConversationOfAnApplicationWithANightlyDigestRun::getOwnerAbsentInitiatorTypes());
        self::assertSame(['NIGHTLY_DIGEST'], AIConversation::getPlatformOwnRunInitiatorTypes());
    }

    public function testARunTheApplicationRunsForItselfIsThePlatformsOwnRun(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = AIConversationOfAnApplicationWithANightlyDigestRun::class;
        $originOfTheNightlyDigest = $this->buildOrigin(AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_NIGHTLY_DIGEST);

        self::assertTrue($originOfTheNightlyDigest->isPlatformOwnRun());
        self::assertFalse($originOfTheNightlyDigest->readsTheLiveCallForOwnerPresence());
        self::assertSame(OwnerPresence::ABSENT_PLATFORM_RUN, $originOfTheNightlyDigest->buildOwnerPresence(AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE, null));
        self::assertSame(AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN, $originOfTheNightlyDigest->getReasonItIsNotTheOwnersConversation());
        self::assertFalse($originOfTheNightlyDigest->isUserPresentRun());
    }

    public function testARunWithoutTheOwnerThatIsNotThePlatformsOwnKeepsItsOwnPresence(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AIConversation::class] = AIConversationOfAnApplicationWithANightlyDigestRun::class;
        $originOfTheReminder = $this->buildOrigin(AIConversationOfAnApplicationWithANightlyDigestRun::INITIATOR_TYPE_REMINDER);

        self::assertFalse($originOfTheReminder->isPlatformOwnRun());
        self::assertTrue($originOfTheReminder->readsTheLiveCallForOwnerPresence());
        self::assertSame(AIConversationOrigin::NOT_THE_OWNER_USER_ABSENT_RUN, $originOfTheReminder->getReasonItIsNotTheOwnersConversation());
        self::assertSame(OwnerPresence::ABSENT_UNKNOWN_ORIGIN, $originOfTheReminder->buildOwnerPresence(null, null));
        self::assertFalse($this->buildOrigin(null)->isPlatformOwnRun());
    }

    public function testTheListsAreNotReadableAsConstantsOfTheModulesClass(): void
    {
        foreach (['OWNER_ABSENT_INITIATOR_TYPES', 'PLATFORM_OWN_RUN_INITIATOR_TYPES'] as $listConstantName) {
            self::assertTrue((new ReflectionClassConstant(AIConversation::class, $listConstantName))->isProtected(), $listConstantName);
        }
        self::assertFalse((new ReflectionClass(AIConversationOrigin::class))->hasConstant('OWNER_ABSENT_INITIATOR_TYPES'));
    }

    protected function buildOrigin(?string $initiatorType): AIConversationOrigin
    {
        $aIConversationOrigin = new AIConversationOrigin();
        $aIConversationOrigin->initiatorType = $initiatorType;
        return $aIConversationOrigin;
    }
}
