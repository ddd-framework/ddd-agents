<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The core eligibility vocabulary: states, the stamped reason, the inspection fields and the log context. */
class AgentAutomationEligibilityTest extends TestCase
{
    public function testTheEligiblePictureCarriesNoReasonAndItsIds(): void
    {
        $eligibility = AgentAutomationEligibility::eligible(7, 3);
        self::assertTrue($eligibility->isEligible());
        self::assertNull($eligibility->getAutomationStateReason());
        self::assertSame('', $eligibility->describeForModel());
        self::assertSame([7, 3], [$eligibility->accountId, $eligibility->tenantAccountId]);
        self::assertFalse($eligibility->isScopeGoneForGood());
    }

    /** @return array<string, array{0: string, 1: string|null, 2: string}> */
    public static function stampedReasons(): array
    {
        return [
            'suspended by the account' => [AgentAutomationEligibility::STATE_SUSPENDED, null, AgentAutomationStateReason::ACCOUNT_SUSPENDED],
            'suspended by the agent space' => [AgentAutomationEligibility::STATE_SUSPENDED, AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE, AgentAutomationStateReason::AGENT_SPACE_SUSPENDED],
            'ended by the account' => [AgentAutomationEligibility::STATE_ENDED, 'ACCOUNT', AgentAutomationStateReason::ACCOUNT_ENDED],
            'ended by the agent space' => [AgentAutomationEligibility::STATE_ENDED, AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE, AgentAutomationStateReason::AGENT_SPACE_ENDED],
            'erased by the account' => [AgentAutomationEligibility::STATE_ERASED, 'ACCOUNT', AgentAutomationStateReason::ACCOUNT_ERASED],
            'erased by the agent space' => [AgentAutomationEligibility::STATE_ERASED, AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE, AgentAutomationStateReason::AGENT_SPACE_ENDED],
        ];
    }

    #[DataProvider('stampedReasons')]
    public function testTheStampedReasonFollowsStateAndDecidingSource(string $state, ?string $signalSource, string $expectedReason): void
    {
        $eligibility = new AgentAutomationEligibility();
        $eligibility->state = $state;
        $eligibility->signalSource = $signalSource;
        $eligibility->reason = 'SOME_REASON';
        self::assertSame($expectedReason, $eligibility->getAutomationStateReason());
        self::assertNotSame('', $eligibility->describeForModel());
        self::assertSame($expectedReason, $eligibility->buildInspectionFields()['automationStateReason']);
    }

    public function testOnlyTheErasedStateIsGoneForGoodWithoutApplicationReasons(): void
    {
        $eligibility = new AgentAutomationEligibility();
        $eligibility->state = AgentAutomationEligibility::STATE_ENDED;
        $eligibility->reason = 'A_REASON_OF_AN_APPLICATION';
        self::assertFalse($eligibility->isScopeGoneForGood());
        $eligibility->state = AgentAutomationEligibility::STATE_ERASED;
        self::assertTrue($eligibility->isScopeGoneForGood());
    }

    public function testTheResumableReasonsAreTheTwoSuspensions(): void
    {
        self::assertTrue(AgentAutomationStateReason::isResumable(AgentAutomationStateReason::AGENT_SPACE_SUSPENDED));
        self::assertTrue(AgentAutomationStateReason::isResumable(AgentAutomationStateReason::ACCOUNT_SUSPENDED));
        self::assertFalse(AgentAutomationStateReason::isResumable(AgentAutomationStateReason::ACCOUNT_ERASED));
        self::assertFalse(AgentAutomationStateReason::isResumable(null));
        self::assertSame(
            ['AGENT_SPACE_SUSPENDED', 'AGENT_SPACE_ENDED', 'ACCOUNT_SUSPENDED', 'ACCOUNT_ENDED', 'ACCOUNT_ERASED'],
            AgentAutomationStateReason::ALL,
            'the values are stored data: they never change'
        );
    }

    public function testTheLogContextCarriesOnlyTheSetFields(): void
    {
        self::assertSame(['conversationId' => 5], AgentEligibilityLogContext::forConversation(5)->buildLogFields());
        self::assertSame(['scheduleId' => 9, 'scheduleAccountId' => 4], AgentEligibilityLogContext::forSchedule(9, 4)->buildLogFields());
        self::assertSame(['toolName' => 'getReviews', 'conversationId' => 2], AgentEligibilityLogContext::forToolCall('getReviews', 2)->buildLogFields());
        self::assertSame([], (new AgentEligibilityLogContext())->buildLogFields());
    }
}
