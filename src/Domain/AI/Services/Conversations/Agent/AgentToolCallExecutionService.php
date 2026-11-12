<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Runs ONE tool call against the conversation's loaded tools (formerly `ToolCall::execute()`,
 * a value object that resolved the executor itself). The call's own pure steps decide what needs no executor (a skill
 * called as a tool, no skill loaded) and the server-supplied arguments; this service builds the provenance, runs the
 * executor with the Argus request journal and the recording of the AI costs the tool books around it
 * ({@see ToolCall::$executionCosts}), and hands the outcome back to the call. The three callers keep their own ceilings:
 * the inline dispatch and the forked child bound the call, the mechanical background child runs it under its turn's
 * outer alarm.
 */
class AgentToolCallExecutionService extends Service
{
    public function executeToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        $loadedPackages = $conversation->findLoadedPackages();
        $refusalBeforeExecution = $toolCall->buildRefusalBeforeExecution($loadedPackages);
        if ($refusalBeforeExecution !== null) {
            return $refusalBeforeExecution;
        }
        $parentMessage = $toolCall->getParent();
        $parentMessageModelName = ($parentMessage instanceof AIConversationMessage && isset($parentMessage->aiModelName))
            ? $parentMessage->aiModelName
            : null;
        // ONE read of the space's zone per tool call — a zone a call earlier in this batch changed is
        // what THIS call parses and stamps with (no-op for a schedule-bound run).
        $conversation->refreshAgentSpaceTimezone();
        // Provenance of THIS tool call — the user message it traces back to + the assistant message that emitted it,
        // injected server-side into any tool declaring an AgentToolCallProvenance param (the model never supplies it).
        // It carries the conversation's space as an object (loaded here when it is not yet); none = the call works at
        // account level.
        $provenance = new AgentToolCallProvenance(
            $conversation->getNewestUserMessage(),
            $parentMessage instanceof AIConversationMessage ? $parentMessage : null,
            $conversation->id ?? null,
            $conversation->agentSpace ?? null,
            $conversation->rootConversationId ?? $conversation->id ?? null,
            $conversation->messages ?? null,
            $conversation->scheduleId ?? null,
            $toolCall->name ?? null,
            $conversation,
        );
        $provenance->toolCallId = isset($toolCall->toolCallId) && $toolCall->toolCallId !== '' ? $toolCall->toolCallId : null;
        $startedAt = microtime(true);
        // The Argus request journal runs ONLY for a conversation that stores request snapshots (debug/eval), and only
        // for the duration of this call — never globally.
        $journalArgusRequests = (isset($conversation->contextRetentionSettings) ? $conversation->contextRetentionSettings : null)?->storeRequestSnapshot ?? false;
        $argusCallJournalLengthBefore = ArgusApiOperations::getCallJournal()->count();
        if ($journalArgusRequests) {
            ArgusApiOperations::$journalRequests = true;
        }
        $toolCall->executionStartedAt = (new DateTime())->format('Y-m-d H:i:s') . sprintf('.%03d', (int)(fmod($startedAt, 1) * 1000));
        // The AI costs the tool books while it runs (a search fee, a page reading) — recorded for THIS call only
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $executionBookedCosts = $aIUsageEnvelopesService->startRecordingBookedCosts();
        // An eval run's tool call runs in eval context: every outbound send it makes (mail, SMS, call, push) reaches
        // only the test sinks, whatever space it runs for ({@see EvalOutboundRecipientsService}).
        $evalRunOutboundScope = $this->findEvalOutboundRecipientsService()->openEvalRunScopeForConversation($conversation);
        try {
            $toolResult = $this->findToolExecutor()->execute($toolCall->name ?? '', $toolCall->buildExecutionArguments($conversation), $loadedPackages, [
                'conversationId' => $conversation->id ?? null,
                'isDelegation' => $conversation->isDelegation(),
                'model' => $parentMessageModelName,
            ], $provenance);
        } finally {
            $evalRunOutboundScope->close();
            // a throw or the tool ceiling's timeout must not leave the journal on for the rest of the process
            $toolCall->durationSeconds = round(microtime(true) - $startedAt, 3);
            $aIUsageEnvelopesService->stopRecordingBookedCosts($executionBookedCosts);
            $toolCall->applyExecutionCosts($executionBookedCosts);
            if ($journalArgusRequests) {
                ArgusApiOperations::$journalRequests = false;
                $toolCall->applyArgusCallJournalSlice(
                    ArgusApiOperations::getCallJournal()->getElementsSlice($argusCallJournalLengthBefore, PHP_INT_MAX)
                );
                ArgusApiOperations::clearCallJournal();
            }
        }
        $toolCall->applyExecutionOutcome($toolResult);
        return $toolResult;
    }

    protected function findEvalOutboundRecipientsService(): EvalOutboundRecipientsService
    {
        /** @var EvalOutboundRecipientsService $evalOutboundRecipientsService */
        $evalOutboundRecipientsService = DDDService::instance()->getService(EvalOutboundRecipientsService::class);
        return $evalOutboundRecipientsService;
    }

    protected function findToolExecutor(): MCPToolExecutorService
    {
        /** @var MCPToolExecutorService $toolExecutor */
        $toolExecutor = DDDService::instance()->getService(MCPToolExecutorService::class);
        return $toolExecutor;
    }
}
