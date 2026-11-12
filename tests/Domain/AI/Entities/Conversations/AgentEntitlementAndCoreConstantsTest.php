<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\AIConversationEntitlement;
use DDD\Domain\AI\Entities\Conversations\AIConversationOrigin;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationToolCallHistory;
use PHPUnit\Framework\TestCase;

/**
 * The entitlement over the core's flag and rights contracts (no application class needed), its AI-budget answer, and
 * the constants the core owns for the application's texts (social profile line, live-call channel types).
 */
class AgentEntitlementAndCoreConstantsTest extends TestCase
{
    public function testAnEntitlementWithoutABudgetAnswerNeverHidesTheExactBudget(): void
    {
        $entitlement = AIConversationEntitlement::forAxes(new AgentEntitlementTestFeatureFlags(['A']), null);

        self::assertFalse($entitlement->hidesExactAIBudget(), 'flags alone do not decide the budget policy any more');
        self::assertFalse((new AIConversationEntitlement())->hidesExactAIBudget());
        self::assertFalse(AIConversationEntitlement::forAxes(null, null, true)->hidesExactAIBudget());
    }

    public function testANotReportableBudgetHidesTheExactBudgetAndSurvivesTheFlagsOnlyCopy(): void
    {
        $entitlement = AIConversationEntitlement::forAxes(new AgentEntitlementTestFeatureFlags(['A']), new AgentEntitlementTestAccountRights([]), false);

        self::assertTrue($entitlement->hidesExactAIBudget());
        $flagsOnly = $entitlement->withFeatureFlagsOnly();
        self::assertTrue($flagsOnly->hidesExactAIBudget());
        self::assertNull($flagsOnly->accountRights);
        self::assertSame($entitlement->featureFlags, $flagsOnly->featureFlags);
    }

    public function testTheAxesAcceptAnyImplementationOfTheCoreContracts(): void
    {
        $entitlement = AIConversationEntitlement::forAxes(new AgentEntitlementTestFeatureFlags(['A']), new AgentEntitlementTestAccountRights(['manageReviews']));

        self::assertFalse($entitlement->isUngatedByFlagsAndRights());
        self::assertTrue($entitlement->featureFlags->hasAllAvailableFeatureFlags(['A']));
        self::assertFalse($entitlement->featureFlags->hasAnyAvailableFeatureFlag(['B']));
        self::assertSame(['billing'], $entitlement->accountRights->getMissingRightNames(['manageReviews', 'billing']));
    }

    public function testTheLiveCallChannelTypesKeepTheStoredValues(): void
    {
        self::assertSame('PHONE', AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE);
        self::assertSame('APP', AIConversationOrigin::LIVE_CALL_CHANNEL_APP);
        self::assertNull(AIConversationOrigin::buildPresenceForLiveCallChannelType(null));
        self::assertSame(OwnerPresence::PRESENT_IN_APP_VOICE_CALL, AIConversationOrigin::buildPresenceForLiveCallChannelType(AIConversationOrigin::LIVE_CALL_CHANNEL_APP));
        self::assertSame(OwnerPresence::PRESENT_ON_THE_PHONE, AIConversationOrigin::buildPresenceForLiveCallChannelType(AIConversationOrigin::LIVE_CALL_CHANNEL_PHONE));
    }

    public function testTheSocialProfileLinePrefixKeepsItsText(): void
    {
        self::assertSame('Platform-verified social profile URLs on this page: ', AIConversationToolCallHistory::SOCIAL_PROFILE_LINE_PREFIX);
    }
}
