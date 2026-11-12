<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Messenger\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalRunOutboundScope;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Messenger\Conversations\AgentWorkerStateResetter;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The agent's worker state resetter puts every process static and service state one agent job leaves behind back on
 * its default, and is collected by the framework's resetter tag (interface autoconfiguration). Runs inside an
 * application (DDD_AGENTS_APP_TEST_BOOTSTRAP): the envelope service is the container's.
 */
class AgentWorkerStateResetterTest extends TestCase
{
    public function testItIsAWorkerStateResetter(): void
    {
        self::assertInstanceOf(WorkerStateResetterInterface::class, new AgentWorkerStateResetter());
    }

    public function testEveryAgentStaticIsBackOnItsDefault(): void
    {
        AgentDecisionModelSettings::useForCurrentProcess(AgentDecisionModelSettings::fromConfiguration());
        DBAIConversationMessage::$includeTruncated = true;
        MCPToolExecutorService::$blockedToolNames = ['publishPost'];
        MCPToolExecutorService::$everyWriteToolBlocked = true;
        AgentDecisionModelCircuitBreakerService::$harnessMode = true;
        AgentToolCallForkJoinService::$forkingDisabled = true;
        EvalRunOutboundScope::openedForEvalRun();
        (new ReflectionClass(AIConversationRunClaimsService::class))->setStaticPropertyValue('runTokensClaimedInThisProcess', [7 => 'previous job token']);

        (new AgentWorkerStateResetter())->resetWorkerStateForNewJob();

        self::assertNull((new ReflectionClass(AgentDecisionModelSettings::class))->getStaticPropertyValue('forCurrentProcess'));
        self::assertFalse(DBAIConversationMessage::$includeTruncated);
        self::assertSame([], MCPToolExecutorService::$blockedToolNames);
        self::assertFalse(MCPToolExecutorService::$everyWriteToolBlocked);
        self::assertNull(MCPToolExecutorService::$toolResultRecording);
        self::assertFalse(AgentDecisionModelCircuitBreakerService::$harnessMode);
        self::assertFalse(AgentToolCallForkJoinService::$forkingDisabled);
        self::assertFalse(EvalRunOutboundScope::isAnyScopeOpen());
        self::assertSame([], (new ReflectionClass(AIConversationRunClaimsService::class))->getStaticPropertyValue('runTokensClaimedInThisProcess'));
    }

    /**
     * The usage envelopes are not this resetter's: the container's envelope service is a worker state resetter itself
     * and empties its envelopes, grants and recordings before every job.
     */
    public function testTheEnvelopeServiceResetsItsOwnState(): void
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        self::assertInstanceOf(WorkerStateResetterInterface::class, $aIUsageEnvelopesService);
        $aIUsageEnvelopesService->grantBudgetBypassForTheProcess(AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONSOLE_OPTION));
        $bookedCostRecording = $aIUsageEnvelopesService->startRecordingBookedCosts();

        $aIUsageEnvelopesService->resetWorkerStateForNewJob();

        self::assertFalse($aIUsageEnvelopesService->isBudgetBypassGranted(), 'no budget lift survives into the next job');
        $aIUsageEnvelopesService->recordBookedCost('ENVELOPE.TEST.COST', 1.0);
        self::assertSame(0.0, $bookedCostRecording->totalCostInUSD, 'the recording of the previous job is closed');
    }

    public function testTheResetRunsOnAFreshProcess(): void
    {
        (new AgentWorkerStateResetter())->resetWorkerStateForNewJob();
        (new AgentWorkerStateResetter())->resetWorkerStateForNewJob();
        self::assertFalse(DBAIConversationMessage::$includeTruncated);
    }
}
