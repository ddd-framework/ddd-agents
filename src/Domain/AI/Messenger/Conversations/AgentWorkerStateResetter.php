<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Messenger\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Evals\OutboundRecipients\EvalRunOutboundScope;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Symfony\Messenger\WorkerStateResetterInterface;

/**
 * Puts the agent's process state back on its defaults before every job a worker consumes: the process statics and the
 * service state one job of the agent leaves behind (tool blocks, the decision-model settings of the process, the
 * truncated-rows switch, the recorded-run hooks, the eval run's outbound hold, the run claims and the claim-fenced
 * write state). Without it a long-lived worker would judge, bill and run job N+1 by what job N set. The usage
 * envelopes, their budget-bypass grants and the cost recordings are reset by
 * {@see \DDD\Domain\AI\Services\AIUsageEnvelopesService} itself, which is a worker state resetter of its own.
 *
 * Collected through {@see WorkerStateResetterInterface} (interface autoconfiguration) and run by the framework's
 * fresh-worker-state middleware after the handler class's own reset; a handler that keeps warm state across messages
 * (`RESET_WORKER_STATE_BEFORE_JOB = false`) skips it. A synchronous dispatch inside a request runs no resetter.
 * Container plumbing, never fetched by name: it sits outside the module's public `Services` namespaces, so the
 * container keeps it private and reaches it only through the tag.
 */
class AgentWorkerStateResetter implements WorkerStateResetterInterface
{
    /**
     * @return void
     */
    public function resetWorkerStateForNewJob(): void
    {
        AgentDecisionModelSettings::useForCurrentProcess(null);
        DBAIConversationMessage::$includeTruncated = false;
        MCPToolExecutorService::resetToolBlocksForNewJob();
        // the recorded-run comparison's hooks belong to one tool call of a console harness, never to a worker job
        AgentDecisionModelCircuitBreakerService::$harnessMode = false;
        MCPToolExecutorService::$toolResultRecording = null;
        AgentToolCallForkJoinService::$forkingDisabled = false;
        // an eval run's tool-call hold left open by the previous job never puts this job in eval context
        EvalRunOutboundScope::forgetScopesOpenInThisProcess();

        // a claim of the previous job is never this job's: its fenced writes would act in a stale token's name. Not
        // while a fenced write is open — that is a job a sync:// transport runs INSIDE the current one (it carries a
        // ReceivedStamp too), and wiping the open write's lock, depth and undos would break it mid-write.
        if (!AIConversationMessagesService::isClaimFencedWriteOpen()) {
            AIConversationRunClaimsService::forgetRunTokensClaimedInThisProcess();
            AIConversationMessagesService::forgetClaimFencedWriteStateForNewJob();
        }
    }
}
