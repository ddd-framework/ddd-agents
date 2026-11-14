<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallExecutionService;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The child process of the fork-join ({@see \DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService}):
 * executes exactly ONE persisted tool call — the row the turn worker created PENDING — and writes the outcome into
 * that row's `toolCall` column under the lease the worker handed it. Started by the turn worker, never by a person;
 * the exit codes are the class constants ({@see EXIT_LEASE_NOT_CLAIMED} and its siblings; 0 = outcome written, a
 * failed tool is an outcome, 1 = the row or the conversation could not be loaded).
 *
 * The execution is the inline path's: the same {@see AgentToolCallExecutionService::executeToolCall()}, the same
 * SIGALRM ceiling, the same failure texts the model reads. The tool runs as the conversation's account, never as the
 * console's default account, so it sees exactly the rights the inline call sees — and the same budget lift: the call
 * runs in its own usage envelope carrying the conversation's budget-bypass grant
 * ({@see executeInsideToolCallEnvelope()}).
 */
#[AsCommand(
    name: 'app:ai-agent:execute-tool-call',
    description: 'Fork-join child: execute ONE persisted tool call and write its outcome into the call row (started by the turn worker).',
    hidden: true,
)]
class AgentExecuteToolCallCommand extends AgentConsoleCommand
{
    /** @var int The lease could not be claimed (another delivery owns the row, or the turn closed it); nothing executed */
    public const int EXIT_LEASE_NOT_CLAIMED = 3;

    /** @var int The outcome write was refused (the lease no longer holds); the worker reads the row's terminal state */
    public const int EXIT_OUTCOME_WRITE_REFUSED = 4;

    /** @var int The worker's claim on the conversation is lost; no outcome was written */
    public const int EXIT_RUN_CLAIM_LOST = 5;

    /** @var string|null The tool the SIGALRM ceiling of the current execution guards; null outside an execution */
    protected ?string $toolNameUnderTimeout = null;

    /** @var int The ceiling in seconds of the current execution */
    protected int $timeoutSecondsOfExecution = 0;

    /** @var AIConversationMessage|null The tool call row of the run, set by {@see loadToolCallMessageAndConversation()} */
    protected ?AIConversationMessage $toolCallMessage = null;

    /** @var AIConversation|null The conversation of the row, set by {@see loadToolCallMessageAndConversation()} */
    protected ?AIConversation $conversationOfToolCall = null;

    protected function configure(): void
    {
        $this
            ->addArgument('messageId', InputArgument::REQUIRED, 'The TOOL_CALL message row to execute')
            ->addOption('lease', null, InputOption::VALUE_REQUIRED, 'The ownership token the worker assigned to this child')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The tool\'s own execution ceiling in seconds', '60')
            ->addOption('blocked-tools', null, InputOption::VALUE_REQUIRED, 'Comma-separated tool names the worker process blocks (an evaluation run)')
            ->addOption('every-write-blocked', null, InputOption::VALUE_NONE, 'The write block of the worker process (an evaluation run)')
            ->addOption('run-token', null, InputOption::VALUE_REQUIRED, 'The run token of the turn worker that started this child — its writes into the conversation are fenced on that claim');
    }

    /**
     * Loads the row, claims its lease, executes the call and writes the outcome.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        $messageId = (int)$input->getArgument('messageId');
        $lease = (string)$input->getOption('lease');
        $timeoutSeconds = max(1, (int)$input->getOption('timeout'));
        if ($messageId <= 0 || $lease === '') {
            $output->writeln('<error>messageId and --lease are required.</error>');
            return Command::FAILURE;
        }
        // the evaluation guards of the worker process travel explicitly: a fresh process has none
        MCPToolExecutorService::$blockedToolNames = array_values(array_filter(array_map('trim', explode(',', (string)$input->getOption('blocked-tools')))));
        MCPToolExecutorService::$everyWriteToolBlocked = (bool)$input->getOption('every-write-blocked');

        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        if (!$this->loadToolCallMessageAndConversation($messageId, (string)$input->getOption('run-token'), $output)) {
            return Command::FAILURE;
        }
        /** @var AIConversationMessage $toolCallMessage */
        $toolCallMessage = $this->toolCallMessage;
        /** @var AIConversation $conversation */
        $conversation = $this->conversationOfToolCall;

        if (!$aIConversationMessagesService->claimToolCallExecutionLease($messageId, $lease)) {
            $output->writeln("lease not claimed for message $messageId");
            return self::EXIT_LEASE_NOT_CLAIMED;
        }
        $agentActivityLinesService = $this->getAgentActivityLinesService();
        // the forked call is RUNNING now: a line for a reader of the root conversation (no reader → nothing)
        $agentActivityLinesService->recordStepForReaders($conversation);

        /** @var ToolCall $toolCall */
        $toolCall = $toolCallMessage->toolCall;
        $toolCall->setParent($toolCallMessage);
        $toolCall->status = AIConversationMessage::STATUS_RUNNING;
        $toolCall->executionLease = $lease;
        $toolName = (string)($toolCall->name ?? '');

        $toolPlatformNoteForModel = null;
        try {
            $toolResult = $this->executeInsideToolCallEnvelope($toolCall, $conversation, $timeoutSeconds);
            $resultContent = $toolResult->content;
            $toolPlatformNoteForModel = $toolResult->platformNoteForModel;
        } catch (AgentRunClaimLostException) {
            // the worker lost its claim: this child writes nothing more into the conversation, not even a FAILED
            // outcome (the tool may well have succeeded); the turn of the new owner of the claim decides
            $output->writeln("run claim lost for message $messageId — no outcome written");
            return self::EXIT_RUN_CLAIM_LOST;
        } catch (AiBudgetExceededException) {
            $resultContent = AiBudgetExceededException::ERROR_CODE
                . ': the account\'s monthly AI budget is used up — this tool cannot run. Do NOT retry this or any '
                . 'other AI-consuming tool. Tell the user plainly that the monthly AI budget is reached and that it '
                . 'resets at the start of next month.';
            $toolCall->markFailed();
        } catch (Throwable $executionError) {
            $resultContent = 'Tool error: ' . $executionError->getMessage();
            $toolCall->markFailed();
        }
        $toolCall->result = $resultContent;
        $toolCall->platformNoteForModel = $toolPlatformNoteForModel;
        // the lease is the worker's read key: it stays on the written outcome
        $toolCall->executionLease = $lease;

        if (!$aIConversationMessagesService->writeToolCallOutcomeIfLeaseHeld($toolCallMessage, $lease)) {
            $output->writeln("outcome write refused for message $messageId (lease no longer held)");
            return self::EXIT_OUTCOME_WRITE_REFUSED;
        }
        // the terminal state of the forked call is a second line for a reader of the root conversation
        $agentActivityLinesService->recordStepForReaders($conversation);
        $output->writeln(json_encode([
            'messageId' => $messageId,
            'tool' => $toolName,
            'status' => $toolCall->status,
            'durationSeconds' => $toolCall->durationSeconds,
        ], JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }

    /**
     * Loads the tool call row and its conversation with the rights lifted (the row belongs to whichever account the
     * conversation belongs to), adopts the worker's run token, loads the bounded context tail, attaches the space
     * relations and authenticates the conversation's account; the rights are restored before the tool runs, so the
     * tool sees the inline path's context. Sets {@see $toolCallMessage} and {@see $conversationOfToolCall}.
     *
     * @param int $messageId
     * @param string $parentRunToken '' = the worker passed none
     * @param OutputInterface $output
     * @return bool false when the row or the conversation could not be loaded
     */
    protected function loadToolCallMessageAndConversation(int $messageId, string $parentRunToken, OutputInterface $output): bool
    {
        $aIConversationMessagesService = $this->getAIConversationMessagesService();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $toolCallMessage = $aIConversationMessagesService->find($messageId, useEntityRegistrCache: false);
            if (!$toolCallMessage instanceof AIConversationMessage || !isset($toolCallMessage->toolCall) || !isset($toolCallMessage->aiConversationId)) {
                $output->writeln("<error>No TOOL_CALL message $messageId.</error>");
                return false;
            }
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = AIConversation::getService();
            $conversation = $aIConversationsService->find((int)$toolCallMessage->aiConversationId, useEntityRegistrCache: false);
            if (!$conversation instanceof AIConversation) {
                $output->writeln("<error>No conversation for message $messageId.</error>");
                return false;
            }
            if ($parentRunToken !== '') {
                // a lost claim of the worker refuses this child's writes into the conversation
                /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
                $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
                $aIConversationRunClaimsService->adoptRunTokenOfParentProcess($conversation, $parentRunToken);
            }
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
            $agentSpacesService = $this->getAgentSpacesService();
            $agentSpacesService->attachAgentSpaceRelations($conversation, false);
            $this->actAsAccount($this->findAccountOfConversation($conversation));
            $this->toolCallMessage = $toolCallMessage;
            $this->conversationOfToolCall = $conversation;
            return true;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * The tool runs inside a usage envelope of its own — the one the executor would otherwise open as its fallback
     * ({@see MCPToolExecutorService::callTool()}: the MCP tool-call envelope of the account; this process starts
     * without one) — and the conversation's {@see AIConversation::$budgetBypass} is granted on it, exactly the lift the
     * same call gets inline inside its turn. The executor sees an open envelope and opens none. An envelope that is
     * already open is not doubled: the grant goes on it for the call and is restored after.
     *
     * @param ToolCall $toolCall
     * @param AIConversation $conversation
     * @param int $timeoutSeconds
     * @return ToolResult
     */
    protected function executeInsideToolCallEnvelope(ToolCall $toolCall, AIConversation $conversation, int $timeoutSeconds): ToolResult
    {
        $conversationBudgetBypassGrant = $conversation->budgetBypass
            ? AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS)
            : null;
        $aIUsageEnvelopesService = $this->getUsageEnvelopesService();
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            $budgetBypassGrantRestore = $conversationBudgetBypassGrant !== null
                ? $aIUsageEnvelopesService->grantBudgetBypassUntilRestored($conversationBudgetBypassGrant)
                : null;
            try {
                return $this->executeUnderTimeout($toolCall, $conversation, $timeoutSeconds);
            } finally {
                $aIUsageEnvelopesService->restoreBudgetBypassGrant($budgetBypassGrantRestore);
            }
        }
        $authenticatedAccount = AuthService::instance()->getAccount();
        $usageEnvelopeContext = $this->openUsageEnvelope(
            $conversation->accountId ?? (isset($authenticatedAccount->id) ? (int)$authenticatedAccount->id : null),
            AgentAIUsageScope::ENVELOPE_MCP_TOOL_CALL,
            AIUsageInitiator::ACCOUNT
        );
        try {
            if ($conversationBudgetBypassGrant !== null) {
                $aIUsageEnvelopesService->grantBudgetBypassForCurrentEnvelope($conversationBudgetBypassGrant);
            }
            return $this->executeUnderTimeout($toolCall, $conversation, $timeoutSeconds);
        } finally {
            $this->closeUsageEnvelope($usageEnvelopeContext);
        }
    }

    /**
     * The inline path's ceiling: a SIGALRM aborts the call after the tool's own timeout, and the thrown timeout becomes
     * the FAILED outcome the model reads. Without pcntl the call runs unbounded and the worker's process timeout is the
     * backstop.
     *
     * @param ToolCall $toolCall
     * @param AIConversation $conversation
     * @param int $timeoutSeconds
     * @return ToolResult
     */
    protected function executeUnderTimeout(ToolCall $toolCall, AIConversation $conversation, int $timeoutSeconds): ToolResult
    {
        $agentToolCallExecutionService = $this->getAgentToolCallExecutionService();
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_alarm')) {
            return $agentToolCallExecutionService->executeToolCall($toolCall, $conversation);
        }
        $this->toolNameUnderTimeout = $toolCall->name ?? '?';
        $this->timeoutSecondsOfExecution = $timeoutSeconds;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, [$this, 'abortExecutionOnAlarm']);
        pcntl_alarm($timeoutSeconds);
        try {
            return $agentToolCallExecutionService->executeToolCall($toolCall, $conversation);
        } finally {
            pcntl_alarm(0);
            $this->toolNameUnderTimeout = null;
        }
    }

    /**
     * The SIGALRM handler of {@see executeUnderTimeout()}: ends the running tool call with the timeout the model reads.
     *
     * @return void
     * @throws AgentToolExecutionTimeoutException always, while an execution is guarded
     */
    public function abortExecutionOnAlarm(): void
    {
        if ($this->toolNameUnderTimeout === null) {
            return;
        }
        throw new AgentToolExecutionTimeoutException($this->toolNameUnderTimeout, $this->timeoutSecondsOfExecution);
    }

    /**
     * The account the tool runs as: the conversation's account, read through the accounts service (the application's
     * class, when it replaces it).
     *
     * @param AIConversation $conversation
     * @return Account|null null when the conversation names no account or it cannot be loaded
     */
    protected function findAccountOfConversation(AIConversation $conversation): ?Account
    {
        $accountId = $conversation->accountId ?? null;
        if ($accountId === null) {
            return null;
        }
        /** @var AccountsService $accountsService */
        $accountsService = DDDService::instance()->getService(AccountsService::class);
        $account = $accountsService->find($accountId);
        return $account instanceof Account ? $account : null;
    }

    /** @return AIConversationMessagesService */
    protected function getAIConversationMessagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** @return AgentActivityLinesService */
    protected function getAgentActivityLinesService(): AgentActivityLinesService
    {
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
        return $agentActivityLinesService;
    }

    /** @return AgentToolCallExecutionService */
    protected function getAgentToolCallExecutionService(): AgentToolCallExecutionService
    {
        /** @var AgentToolCallExecutionService $agentToolCallExecutionService */
        $agentToolCallExecutionService = DDDService::instance()->getService(AgentToolCallExecutionService::class);
        return $agentToolCallExecutionService;
    }
}
