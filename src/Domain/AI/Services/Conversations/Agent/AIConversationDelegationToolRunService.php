<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentBackgroundOperationOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\BackgroundMechanicalOperationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The TOOL-RUN child's turn (moved out of the purpose background work: running a
 * delegation child is delegation work): a delegation child whose purpose marks it a tool run
 * ({@see AIConversation::isToolRunDelegationChild()}) executes the ONE operation its seed names WITHOUT any model call
 * and ends with that operation's result as its single assistant answer. Until the seed changes
 * the seed is the `Background.MechanicalOperation` one ({@see BackgroundMechanicalOperationPurpose::parseOperationSeedText()}):
 * `executeTool` runs one MCP tool against the seed's loaded skills; an operation the application names is carried out
 * by its {@see AgentBackgroundOperationsService}. Rows are written under the run the loop passes in.
 */
class AIConversationDelegationToolRunService extends Service
{
    use AITraceLogTrait;

    /**
     * Wall-clock ceiling (seconds) for ONE tool run ({@see runToolRunChildTurn()}). Deliberately far above
     * {@see AgentToolCallDispatchService::TOOL_EXECUTION_TIMEOUT_SECONDS}: moving the slow calls (e.g. a crawl of
     * 50–150s, a cold first generation of ~60–90s) off the interactive turn INTO these children is the whole
     * point — but a stuck external call must still not pin the worker slot indefinitely.
     */
    public const int TOOL_RUN_TIMEOUT_SECONDS = 300;

    /**
     * Run a tool-run child's single turn: parse the operation payload from the seed message, execute it WITHOUT any
     * model call and persist the result as the child's single assistant answer (that text becomes the verdict in the
     * parent's delegation-result slice). Never throws: every failure (unparsable payload, tool error, timeout, any
     * Throwable) becomes a truthful FAILED verdict, so the caller's settle always runs. Returns the terminal run state
     * (COMPLETED/FAILED) for {@see AIConversationAgentService::finishDelegationChild()}. Bounded by a SIGALRM at
     * {@see TOOL_RUN_TIMEOUT_SECONDS} where pcntl is available (the async-worker path).
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function runToolRunChildTurn(AgentRun $agentRun, AIConversation $conversation): string
    {
        $backgroundMechanicalOperation = BackgroundMechanicalOperationPurpose::parseOperationSeedText($conversation->getDelegationTaskText());
        $operationFailed = false;
        $verdictText = '';
        $pcntlAvailable = function_exists('pcntl_async_signals') && function_exists('pcntl_alarm');
        $previousAsyncSignals = null;
        $previousAlarmHandler = null;
        if ($pcntlAvailable) {
            $previousAsyncSignals = pcntl_async_signals(true);
            $previousAlarmHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, static function (): void {
                throw new \RuntimeException(sprintf(
                    'the background operation exceeded the %ds execution timeout and was aborted.',
                    self::TOOL_RUN_TIMEOUT_SECONDS
                ));
            });
            pcntl_alarm(self::TOOL_RUN_TIMEOUT_SECONDS);
        }
        // A tool-run child skips the model-drive path and with it the loop's budget-bypass block — honour the
        // inherited conversation bypass here, or an AI-op tool dies with AiBudgetExceededException on budget-spent test
        // accounts while the parent ran fine. A grant on the child's turn envelope (the resume worker's — this runs
        // only on the async worker path), restored in the `finally`.
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $operationBudgetBypassGrantRestore = $conversation->budgetBypass
            ? $aIUsageEnvelopesService->grantBudgetBypassUntilRestored(
                AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS)
            )
            : null;
        try {
            /** @var AgentBackgroundOperationsService $agentBackgroundOperationsService */
            $agentBackgroundOperationsService = DDDService::instance()->getService(AgentBackgroundOperationsService::class);
            if ($backgroundMechanicalOperation === null) {
                $operationFailed = true;
                $verdictText = 'Background job failed: the seed message carries no parsable operation payload.';
            } elseif (in_array($backgroundMechanicalOperation->operation, $agentBackgroundOperationsService->getSupportedOperationNames(), true)) {
                // an operation the application names: its background operations service carries it out
                /** @var AgentBackgroundOperationOutcome $agentBackgroundOperationOutcome */
                $agentBackgroundOperationOutcome = $agentBackgroundOperationsService->runMechanicalOperation($conversation, $backgroundMechanicalOperation->operation);
                $operationFailed = $agentBackgroundOperationOutcome->operationFailed;
                $verdictText = $agentBackgroundOperationOutcome->verdictText;
            } elseif ($backgroundMechanicalOperation->isToolExecution()) {
                $backgroundToolCall = new ToolCall();
                $backgroundToolCall->toolCallId = 'call_bg_' . RecordedRunClockAndIdSources::mintHexToken(8);
                $backgroundToolCall->name = $backgroundMechanicalOperation->toolName ?? '';
                $backgroundToolCall->arguments = $backgroundMechanicalOperation->toolArguments;
                $backgroundToolCall->stampEffect(MCPToolEffectCatalog::getEffectForToolName($backgroundToolCall->name));
                $backgroundToolResult = $this->executeToolRunToolCall($backgroundToolCall, $conversation);
                $verdictText = $backgroundToolResult->content;
                $operationFailed = $backgroundToolResult->isError;
            } else {
                $operationFailed = true;
                $verdictText = "Background job failed: unknown operation '{$backgroundMechanicalOperation->operation}'.";
            }
        } catch (Throwable $mechanicalOperationError) {
            $operationFailed = true;
            $verdictText = 'Background job failed: ' . mb_substr($mechanicalOperationError->getMessage(), 0, 500);
        } finally {
            $aIUsageEnvelopesService->restoreBudgetBypassGrant($operationBudgetBypassGrantRestore);
            if ($pcntlAvailable) {
                pcntl_alarm(0); // clear the pending alarm
                pcntl_signal(SIGALRM, $previousAlarmHandler);
                pcntl_async_signals((bool)$previousAsyncSignals);
            }
        }
        $this->aiTrace('turn.mechanical.executed', [
            'conversationId' => $conversation->id ?? null,
            'operation' => $backgroundMechanicalOperation?->operation,
            'toolName' => $backgroundMechanicalOperation?->toolName,
            'failed' => $operationFailed,
        ]);
        // The answer message IS the child's verdict (buildDelegationResultSlice reads the last assistant text).
        // The transient-id counter starts PAST the existing messages: on an un-persisted conversation a fresh
        // counter would mint the seed's id again and the set-add would swallow the verdict as a duplicate key.
        $transientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getNextIndex());
        try {
            $verdictMessage = $this->messagesService()->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_TEXT,
                $verdictText,
                role: AIConversationMessage::ROLE_ASSISTANT,
                authorType: AIConversationMessage::AUTHOR_ASSISTANT
            );
            $this->messagesService()->persistMessageUnderRun($agentRun, $verdictMessage, null);
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $verdictPersistError) {
            // The settle must still run — a lost verdict text degrades to "(sub-agent produced no verdict)".
            $this->aiTrace('turn.mechanical.verdictPersistFailed', ['conversationId' => $conversation->id ?? null, 'error' => mb_substr($verdictPersistError->getMessage(), 0, 300)]);
            $operationFailed = true;
        }
        return $operationFailed ? AIConversationRunState::FAILED : AIConversationRunState::COMPLETED;
    }

    /**
     * The tool run's execution — a one-line indirection to {@see AgentToolCallExecutionService::executeToolCall()} so
     * unit-test doubles can return canned results without a live executor. Deliberately NOT
     * {@see AgentToolCallDispatchService::executeToolCall()}: that wrapper arms its own 60s SIGALRM whose clear would
     * also clear the tool run's outer alarm, and 60s is exactly the ceiling the slow background reads exist to escape.
     */
    protected function executeToolRunToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        /** @var AgentToolCallExecutionService $agentToolCallExecutionService */
        $agentToolCallExecutionService = DDDService::instance()->getService(AgentToolCallExecutionService::class);
        return $agentToolCallExecutionService->executeToolCall($toolCall, $conversation);
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }
}
