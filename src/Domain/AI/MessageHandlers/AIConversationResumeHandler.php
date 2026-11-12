<?php

declare(strict_types=1);

namespace DDD\Domain\AI\MessageHandlers;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Worker that drives one background turn of an `AIConversation`. Thin per the message-handler
 * convention: restore auth → workspace guard → load the conversation → call the ONE service seam with `async: false`
 * → log. All work (the run-claim, attach the space, apply `budgetBypass`, drive the turn, fan-in branch) lives in
 * {@see AIConversationAgentService::resumeConversationIfReady()} (the ONE code path the handler, the
 * reaper, and recover-on-interaction all share). The AI-usage envelope is opened around this handler by
 * `AIEnvelopeMiddleware` (the message's `#[WithAIEnvelope]`).
 */
#[AsMessageHandler(fromTransport: AgentMessengerTransports::TURN)]
class AIConversationResumeHandler extends AIDomainMessageHandler
{
    use AITraceLogTrait;

    public function __invoke(AIConversationResumeMessage $message): void
    {
        set_time_limit(1800); // a single agentic turn can be a long multi-step LLM run (tool calls, fan-out spawn)
        ini_set('memory_limit', '1024M');

        // the origin of the turn-start stamps: what happens between the owner's message and
        // the first model call is measured from HERE — before auth, envelope and the conversation load
        $agentWorkerJobReceipt = AgentWorkerJobReceipt::receivedNow($message->dispatchedAtWallUs, $message->ownerRowSavedWallUs);
        // The receipt also stays the process's LAST one — a resume this
        // handler did not start (a sync transport job, an eval inline drive) still reads it
        AIConversationAgentService::$lastWorkerJobReceipt = $agentWorkerJobReceipt;
        // a NEW job = a new turn: the signal service outlives the job in this worker, so its per-turn A2A memo must
        // not leak into the publishes that run before the loop (pre-drive status, injected signals)
        /** @var AIConversationRealtimeSignalService $realtimeSignalService */
        $realtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        $realtimeSignalService->resetPushConfigMemo();
        $this->aiTrace('turn.resume.received', ['conversationId' => $message->aiConversationId]);

        $this->setAuthAccountFromMessage($message);

        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            $this->loadAndDriveConversation($message, $agentWorkerJobReceipt);
            return;
        }
        // A caller that invokes this handler directly (`app:process-cli-message`) runs past the envelope middleware of
        // the bus: without an envelope every model call of the turn throws MissingAIEnvelopeException. A message of
        // another workspace never arrives here: the framework refuses it before the handler runs.
        $turnEnvelope = $aIUsageEnvelopesService->openEnvelope(
            $message->accountId ?? null,
            AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN,
            AIUsageInitiator::MESSENGER
        );
        try {
            $this->loadAndDriveConversation($message, $agentWorkerJobReceipt);
        } finally {
            $aIUsageEnvelopesService->closeEnvelope($turnEnvelope);
        }
    }

    protected function loadAndDriveConversation(AIConversationResumeMessage $message, AgentWorkerJobReceipt $agentWorkerJobReceipt): void
    {
        $this->getLogger()->info("AIConversationResumeHandler: driving conversation {$message->aiConversationId}");
        // LOAD phase: a failure HERE means the turn never started — rethrow so Messenger RETRIES (then routes to the
        // failure transport, visibly). Swallowing it drops the kickoff permanently: the conversation stays IDLE with
        // an unanswered user message and no sweep owns that state (a replica-lag "not yet visible" find on a
        // JUST-created conversation, or a schema that lags the code, would silently kill the task's only resume).
        try {
            $conversationsService = AIConversation::getService();
            // throwErrors: a not-(yet)-visible conversation (replica lag on a just-created one) throws instead of
            // returning null — the null would TypeError into resumeConversationIfReady and be mis-filed as a turn error.
            // The service is the container's shared instance, so the flag holds for this one find() only: left set, every
            // later find() of the worker (the turn itself, the next job) would throw on a miss instead of returning null.
            $throwErrorsBeforeFind = $conversationsService->enableThrowErrors();
            try {
                // Fresh load (no registry cache): a long-lived worker would otherwise reuse a prior-turn clone missing
                // what sibling workers persisted since (summary, compaction, runState).
                $conversation = $conversationsService->find($message->aiConversationId, useEntityRegistrCache: false);
            } finally {
                $conversationsService->restoreThrowErrors($throwErrorsBeforeFind);
            }
        } catch (Throwable $loadThrowable) {
            $this->logIssue($loadThrowable, $message, "AIConversationResumeHandler: conversation load failed for {$message->aiConversationId} — rethrowing for retry");
            throw $loadThrowable;
        }
        // DRIVE phase: keep the swallow — resumeConversationIfReady handles its own turn errors (FAILED terminal +
        // realtime signal + resume-attempt cap); a rethrow here would re-drive a half-executed turn.
        try {
            /** @var AIConversationAgentService $agentService */
            $agentService = DDDService::instance()->getService(AIConversationAgentService::class);
            $agentService->resumeConversationIfReady($conversation, async: false, agentWorkerJobReceipt: $agentWorkerJobReceipt);
        } catch (Throwable $t) {
            $this->logIssue($t, $message, "AIConversationResumeHandler: turn failed for conversation {$message->aiConversationId}");
        }
    }
}
