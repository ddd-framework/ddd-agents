<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Exceptions\Texts\Embeddings\TextEmbeddingNotGeneratedException;
use DDD\Domain\AI\Services\Conversations\Agent\AgentBackgroundOperationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentDecisionFactsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentOwnerReportEmailsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolResultFactsService;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Domain\AI\Services\Conversations\Environments\AgentEnvironmentNamesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Situations\AgentSituationalContextService;
use DDD\Domain\AI\Services\Texts\Embeddings\AITextEmbeddingsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The "no component" defaults of the core's seams: an application without an override runs the core unchanged —
 * eligible, ungated, no channel, no memory, nothing embedded, one production environment.
 */
class AgentSeamDefaultsTest extends TestCase
{
    public function testEverySpaceAndAccountIsEligibleAndNoAutomationIsRefused(): void
    {
        $agentAutomationEligibilityService = new AgentAutomationEligibilityService();
        $agentSpace = new AgentSpace();
        $agentSpace->id = 12;
        $agentSpaceEligibility = $agentAutomationEligibilityService->findEligibilityForAgentSpace($agentSpace);
        $accountEligibility = $agentAutomationEligibilityService->findEligibilityForAccountId(34);
        self::assertTrue($agentSpaceEligibility->isEligible());
        self::assertTrue($accountEligibility->isEligible());
        self::assertSame(34, $accountEligibility->accountId);
        self::assertFalse($agentAutomationEligibilityService->refusesAutomation($agentSpaceEligibility, 'toolCall', new AgentEligibilityLogContext()));
        self::assertFalse($agentAutomationEligibilityService->refusesAutomationForAgentSpace($agentSpace, 'scheduleFire', new AgentEligibilityLogContext()));
        self::assertFalse($agentAutomationEligibilityService->refusesAutomationForAccountId(34, 'scheduleFire', new AgentEligibilityLogContext()));
        self::assertNull($agentAutomationEligibilityService->activateSupportOverride('note'));
        self::assertFalse($agentAutomationEligibilityService->isSupportOverrideActive());
        self::assertNull($agentAutomationEligibilityService->getActiveSupportOverrideNote());
    }

    public function testTheProcessRunsInTheProductionEnvironment(): void
    {
        self::assertSame('prod', (new AgentEnvironmentNamesService())->getCurrentEnvironmentName());
        self::assertSame('prod', AgentEnvironmentNamesService::ENVIRONMENT_PRODUCTION);
    }

    public function testNothingIsEmbeddedWithoutAProvider(): void
    {
        try {
            (new AITextEmbeddingsService())->generateEmbeddingForText('a text of 22 characters');
            self::fail('the default must throw');
        } catch (TextEmbeddingNotGeneratedException $notGenerated) {
            self::assertSame(23, $notGenerated->inputBytes);
            self::assertFalse($notGenerated->answeredWithError, 'no provider answered: a retry at a shorter cut is pointless');
        }
    }

    public function testTheOptionalComponentsAnswerNothing(): void
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $backgroundOutcome = (new AgentBackgroundOperationsService())->runMechanicalOperation($conversation, 'detectCms');
        self::assertTrue($backgroundOutcome->operationFailed);
        self::assertStringContainsString('"detectCms" is not supported', $backgroundOutcome->verdictText);
        self::assertSame([], (new AgentBackgroundOperationsService())->getSupportedOperationNames());
        self::assertNull((new AgentBackgroundOperationsService())->getOperationLabel('detectCms'));
        self::assertFalse((new AgentOwnerReportEmailsService())->sendReportEmail($conversation));
        self::assertSame([], (new AgentDecisionFactsService())->collectSkillPreloadFacts($conversation)->valuesByFactName);
        self::assertSame([], (new AgentToolResultFactsService())->extractPreservedFacts(['rating' => 4.5]));
        self::assertSame([], (new AgentEvalAccountsService())->getEvalAccountIds());
        $agentVoiceChannelService = new AgentVoiceChannelService();
        self::assertNull($agentVoiceChannelService->findLiveAnsweredCallChannelType(5));
        self::assertFalse($agentVoiceChannelService->findCallTaskTextsOfConversation($conversation)->hasCalls);
        $agentMemoriesService = new AgentMemoriesService();
        $agentSpace = new AgentSpace();
        $agentSpace->id = 2;
        self::assertNull($agentMemoriesService->findStandingDirectivesForAgentSpace(1, null));
        self::assertNull($agentMemoriesService->findStandingDirectivesForAgentSpace(1, $agentSpace));
        self::assertSame(0, $agentMemoriesService->findSimilarMemories('query', 1, $agentSpace, 5)->count());
        self::assertSame(0, $agentMemoriesService->findSimilarMemories('query', 1, null, 5)->count());
        self::assertSame(0, $agentMemoriesService->getEmbeddingRepairTargets()->count());
        $agentSituationalContextService = new AgentSituationalContextService();
        self::assertNull($agentSituationalContextService->composeDayContextTextForNewConversation($conversation));
        self::assertNull($agentSituationalContextService->composeLocalSituationText($conversation));
    }
}
