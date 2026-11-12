<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationStateReason;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionProperty;

/**
 * The reasons the platform stamps when it stops an automation name the agent space in the package. An application
 * that has stored other words for its scope below the account keeps them in a same-path reason class; the stamped
 * reason and the allowed values of the two stored columns are read off that class.
 */
class AgentAutomationStateReasonOfTheApplicationTest extends UnitTestCase
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

    public function testWithoutAnApplicationClassThePackagesOwnValuesAreStamped(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentAutomationStateReason::class] = AgentAutomationStateReason::class;

        self::assertSame(AgentAutomationStateReason::class, AgentAutomationStateReason::getReasonClassOfTheApplication());
        self::assertSame(AgentAutomationStateReason::ALL, AgentAutomationStateReason::getAllOfTheApplication());
        self::assertSame('AGENT_SPACE_SUSPENDED', $this->buildEligibilityDecidedByTheAgentSpace(AgentAutomationEligibility::STATE_SUSPENDED)->getAutomationStateReason());
        self::assertSame('AGENT_SPACE_ENDED', $this->buildEligibilityDecidedByTheAgentSpace(AgentAutomationEligibility::STATE_ENDED)->getAutomationStateReason());
    }

    public function testAnApplicationsReasonClassKeepsTheWordsItHasStored(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentAutomationStateReason::class] = AgentAutomationStateReasonOfAnApplicationWithWorkspaces::class;

        self::assertSame('WORKSPACE_SUSPENDED', $this->buildEligibilityDecidedByTheAgentSpace(AgentAutomationEligibility::STATE_SUSPENDED)->getAutomationStateReason());
        self::assertSame('WORKSPACE_ENDED', $this->buildEligibilityDecidedByTheAgentSpace(AgentAutomationEligibility::STATE_ENDED)->getAutomationStateReason());
        self::assertSame('WORKSPACE_ENDED', $this->buildEligibilityDecidedByTheAgentSpace(AgentAutomationEligibility::STATE_ERASED)->getAutomationStateReason());
        self::assertTrue(AgentAutomationStateReasonOfAnApplicationWithWorkspaces::isResumable('WORKSPACE_SUSPENDED'));
        self::assertFalse(AgentAutomationStateReasonOfAnApplicationWithWorkspaces::isResumable('AGENT_SPACE_SUSPENDED'));
    }

    public function testTheAllowedValuesOfTheTwoStoredColumnsAreTheApplicationsList(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentAutomationStateReason::class] = AgentAutomationStateReasonOfAnApplicationWithWorkspaces::class;

        foreach ([[AIConversationSchedule::class, 'stateReason'], [AIConversation::class, 'suppressionReason']] as [$className, $propertyName]) {
            $choiceAttributes = (new ReflectionProperty($className, $propertyName))->getAttributes(Choice::class);
            self::assertCount(1, $choiceAttributes, $className);
            self::assertSame(
                ['WORKSPACE_SUSPENDED', 'WORKSPACE_ENDED', 'ACCOUNT_SUSPENDED', 'ACCOUNT_ENDED', 'ACCOUNT_ERASED'],
                Choice::getChoicesResolvedFromConstraint($choiceAttributes[0]->newInstance()),
                $className
            );
        }
    }

    protected function buildEligibilityDecidedByTheAgentSpace(string $state): AgentAutomationEligibility
    {
        $eligibility = new AgentAutomationEligibility();
        $eligibility->state = $state;
        $eligibility->signalSource = AgentAutomationEligibility::SIGNAL_SOURCE_AGENT_SPACE;
        $eligibility->reason = 'A_REASON_OF_AN_APPLICATION';
        return $eligibility;
    }
}

/** An application whose scope below the account is a workspace, with rows that say so. */
class AgentAutomationStateReasonOfAnApplicationWithWorkspaces extends AgentAutomationStateReason
{
    public const string AGENT_SPACE_SUSPENDED = 'WORKSPACE_SUSPENDED';

    public const string AGENT_SPACE_ENDED = 'WORKSPACE_ENDED';

    public const array ALL = [
        self::AGENT_SPACE_SUSPENDED,
        self::AGENT_SPACE_ENDED,
        self::ACCOUNT_SUSPENDED,
        self::ACCOUNT_ENDED,
        self::ACCOUNT_ERASED,
    ];

    public const array RESUMABLE = [self::AGENT_SPACE_SUSPENDED, self::ACCOUNT_SUSPENDED];
}
