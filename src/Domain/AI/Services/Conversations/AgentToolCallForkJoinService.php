<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationToolCallBatch;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use DDD\DDDBundle;
use LogicException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the parallel-safe tool calls of ONE model response concurrently, each in its own short-lived console process,
 * while the turn worker keeps its claim and runs the rest of the response inline (the fork-join design). The module
 * ships no command for the child process: the application declares its console script and the command that executes one persisted
 * tool call in `config/app/AI/agentToolCallChildProcess.php` (the arguments and exit codes are listed there). An
 * application that declares none forks nothing and every call runs inline. The child claims its call row's
 * `toolCall` column with a lease, executes the call exactly as the inline path does, writes the outcome into the
 * column and exits; the turn adopts the outcome from the row when the loop reaches that call. No queue, no resume,
 * no new run state: the turn's wall clock for a batch becomes the slowest call plus one process boot (~0.1 s
 * measured with the worker cache) instead of the sum.
 *
 * Bounded like the inline path: every child runs under its tool's own timeout (the SIGALRM guard inside the child)
 * and the parent kills a child that outlives it by a grace period; a child that dies or never reports leaves a
 * FAILED outcome the model reads like any other tool failure.
 *
 * What one step knows about its children — their processes, leases, message rows, timeouts and the inspector row —
 * lives on the step's {@see AgentToolCallFork}, never on this shared service: it ends with
 * the step.
 */
class AgentToolCallForkJoinService extends Service
{
    use AITraceLogTrait;

    /** The parent's kill margin above the tool's own timeout: the child's SIGALRM should fire first and write FAILED. */
    public const int PARENT_TIMEOUT_GRACE_SECONDS = 10;

    /** Fewer parallel-safe calls than this run inline: one child buys no concurrency. */
    public const int MIN_FORKABLE_CALLS = 2;

    /** The config name of the application's console command a child runs (`config/app/AI/agentToolCallChildProcess.php`). */
    public const string CHILD_PROCESS_CONFIG_NAME = 'AI.agentToolCallChildProcess';

    /** Inline writers that touch conversation state only (memory rows), never a space row: they do not defer the children. */
    public const array INLINE_WRITES_OF_CONVERSATION_STATE_ONLY = ['remember', 'updateMemory', 'forget'];

    /**
     * The recorded-run comparison runs every tool call in its own process: its Argus transport is recorded and
     * played there, a forked child would reach the real network. Never set in a normal run.
     */
    public static bool $forkingDisabled = false;

    /**
     * The calls of one model response the fork may run: every call whose tool declares `#[MCPToolParallelSafe]`
     * among the conversation's loaded packages; empty below {@see MIN_FORKABLE_CALLS}. The caller has already
     * excluded the calls the loop's guards intercept (repeats, deduplicated reads, unloaded skills) — those never
     * execute at all, so they never fork.
     *
     * @param ToolCall[] $candidateToolCalls
     * @return ToolCall[]
     */
    public function selectForkableToolCalls(array $candidateToolCalls, AIConversation $conversation): array
    {
        if (self::$forkingDisabled || !$this->isChildProcessCommandDeclared()) {
            return [];
        }
        $toolDefinitions = $conversation->buildLoadedToolDefinitions();
        $callCountsByToolName = [];
        foreach ($candidateToolCalls as $toolCall) {
            $callCountsByToolName[(string)($toolCall->name ?? '')] = ($callCountsByToolName[(string)($toolCall->name ?? '')] ?? 0) + 1;
        }
        $forkable = [];
        foreach ($candidateToolCalls as $toolCall) {
            $toolName = (string)($toolCall->name ?? '');
            if ($toolName === '' || ($toolCall->toolCallId ?? '') === '' || in_array($toolName, AIConversationMessage::META_TOOL_NAMES, true)) {
                continue;
            }
            $toolDefinition = $toolDefinitions->getByName($toolName);
            if ($toolDefinition === null || !$toolDefinition->isParallelSafe) {
                continue;
            }
            // Two calls of the SAME tool never fork: the admission declares the tool's rows disjoint from OTHER
            // tools' rows, not from its own — two partial writes of one facet (two description fields, phone and
            // e-mail of the contact row) each write the whole facet back from their own fresh load, and the second
            // reverts the first. Run one after the other they merge, as they always did.
            if (($callCountsByToolName[$toolName] ?? 0) > 1) {
                continue;
            }
            $forkable[] = $toolCall;
        }
        return count($forkable) >= self::MIN_FORKABLE_CALLS ? $forkable : [];
    }

    /**
     * Whether the response carries, BESIDE the forked calls, an inline call that may WRITE — any call that is not a
     * meta tool, not forked, and not a read by the tool naming convention. The children must then start only after
     * the inline calls completed: a non-admitted writer may write a WHOLE row from
     * its own load, and run concurrently it would map a child's freshly written columns back to their old values.
     * Inline READS overlap the children as before.
     *
     * @param ToolCall[] $candidateToolCalls
     * @param ToolCall[] $forkableToolCalls
     */
    public function responseHasInlineWriteBesideTheFork(array $candidateToolCalls, array $forkableToolCalls): bool
    {
        $forkedToolCallIds = array_map(static fn (ToolCall $toolCall): string => (string)($toolCall->toolCallId ?? ''), $forkableToolCalls);
        foreach ($candidateToolCalls as $toolCall) {
            $toolName = (string)($toolCall->name ?? '');
            if ($toolName === '' || in_array((string)($toolCall->toolCallId ?? ''), $forkedToolCallIds, true)) {
                continue;
            }
            if (in_array($toolName, AIConversationMessage::META_TOOL_NAMES, true) || $toolCall->isReadOnly()) {
                continue;
            }
            if (in_array($toolName, self::INLINE_WRITES_OF_CONVERSATION_STATE_ONLY, true)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Start one child per call of the fork (its calls, their rows' message ids, the tool's own execution ceiling per
     * call). The rows are persisted PENDING by the caller before this; from here on the `toolCall` column of each row
     * belongs to its child until {@see waitForOutcome()} returned for it.
     *
     * @throws LogicException when the application declared no child process command
     */
    public function startChildren(AgentToolCallFork $agentToolCallFork, AIConversation $conversation): void
    {
        $consoleScript = $this->getChildProcessConsoleScript();
        $commandName = $this->getChildProcessCommandName();
        if ($consoleScript === null || $commandName === null) {
            throw new LogicException('No console command for tool call child processes is declared (config/app/AI/agentToolCallChildProcess.php).');
        }
        $projectDir = (string)DDDBundle::getContainer()->getParameter('kernel.project_dir');
        foreach ($agentToolCallFork->toolCalls as $toolCall) {
            $toolCallId = (string)$toolCall->toolCallId;
            $messageId = $agentToolCallFork->callMessageIdsByToolCallId[$toolCallId] ?? null;
            if ($messageId === null) {
                continue;
            }
            $lease = bin2hex(random_bytes(16));
            $timeoutSeconds = max(1, $agentToolCallFork->declaredTimeoutSecondsByToolCallId[$toolCallId] ?? 60);
            $arguments = [
                PHP_BINARY,
                '-d', 'memory_limit=1024M',
                $projectDir . '/' . $consoleScript,
                '--no-debug',
                $commandName,
                (string)$messageId,
                '--lease=' . $lease,
                '--timeout=' . $timeoutSeconds,
            ];
            // the child writes into the conversation under THIS worker's claim, never unfenced
            /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
            $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
            $parentRunToken = $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation);
            if ($parentRunToken !== null) {
                $arguments[] = '--run-token=' . $parentRunToken;
            }
            // The eval harness's process-static guards travel to the child explicitly (a fresh process has none).
            if (MCPToolExecutorService::$blockedToolNames !== []) {
                $arguments[] = '--blocked-tools=' . implode(',', MCPToolExecutorService::$blockedToolNames);
            }
            if (MCPToolExecutorService::$everyWriteToolBlocked) {
                $arguments[] = '--every-write-blocked';
            }
            $process = new Process($arguments, $projectDir);
            $process->setTimeout((float)($timeoutSeconds + self::PARENT_TIMEOUT_GRACE_SECONDS));
            $process->start();
            $agentToolCallFork->processesByToolCallId[$toolCallId] = $process;
            $agentToolCallFork->leasesByToolCallId[$toolCallId] = $lease;
            $agentToolCallFork->messageIdsByToolCallId[$toolCallId] = (int)$messageId;
            $agentToolCallFork->timeoutSecondsByToolCallId[$toolCallId] = $timeoutSeconds;
            $this->aiTrace('turn.toolCall.forked', [
                'conversationId' => $conversation->id ?? null,
                'name' => $toolCall->name ?? null,
                'messageId' => $messageId,
                'pid' => $process->getPid(),
                'timeoutSeconds' => $timeoutSeconds,
            ]);
        }
    }

    /**
     * Whether the application declared both its console script and the command a child runs. Without them no call
     * forks ({@see selectForkableToolCalls()} returns none).
     */
    public function isChildProcessCommandDeclared(): bool
    {
        return $this->getChildProcessConsoleScript() !== null && $this->getChildProcessCommandName() !== null;
    }

    /** The application's console script, relative to the kernel project directory; null when none is declared. */
    public function getChildProcessConsoleScript(): ?string
    {
        $consoleScript = Config::get(self::CHILD_PROCESS_CONFIG_NAME . '.consoleScript');
        return is_string($consoleScript) && trim($consoleScript, '/ ') !== '' ? trim($consoleScript, '/ ') : null;
    }

    /** The name of the application's console command that executes one persisted tool call; null when none is declared. */
    public function getChildProcessCommandName(): ?string
    {
        $commandName = Config::get(self::CHILD_PROCESS_CONFIG_NAME . '.commandName');
        return is_string($commandName) && trim($commandName) !== '' ? trim($commandName) : null;
    }

    /**
     * Block until the child of this call exited (or outlived its timeout and was killed), then read the outcome the
     * child wrote into its row. Returns the loaded `ToolCall` VO on success; a string is the failure text the turn
     * feeds the model when no owned outcome exists (a killed, crashed or lease-less child).
     */
    public function waitForOutcome(AgentToolCallFork $agentToolCallFork, string $toolCallId, AIConversation $conversation): ToolCall|string
    {
        $process = $agentToolCallFork->processesByToolCallId[$toolCallId] ?? null;
        $messageId = $agentToolCallFork->messageIdsByToolCallId[$toolCallId] ?? null;
        $lease = $agentToolCallFork->leasesByToolCallId[$toolCallId] ?? '';
        unset($agentToolCallFork->processesByToolCallId[$toolCallId]);
        if ($process === null || $messageId === null) {
            return 'Tool error: the parallel tool worker was never started for this call.';
        }
        $failureText = $agentToolCallFork->waitFailureTextByToolCallId[$toolCallId]
            ?? $this->waitForChildExit($agentToolCallFork, $toolCallId, $process, $conversation);
        $exitCode = $process->getExitCode();
        $this->aiTrace('turn.toolCall.forkJoined', [
            'conversationId' => $conversation->id ?? null,
            'messageId' => $messageId,
            'exitCode' => $exitCode,
            'stderrTail' => mb_substr(trim($process->getErrorOutput()), -300),
        ]);

        // The outcome lives in the row — the child wrote the whole column under its lease. Read it fresh: the
        // parent's copy still holds PENDING. A child that exited 0 HAS written (it exits 0 only after the guarded
        // write matched), so a miss right after its exit is a visibility lag of this connection, not a lost
        // outcome: re-read briefly before giving up.
        $observedColumn = null;
        $readAttempts = $exitCode === 0 ? 10 : 1;
        for ($attempt = 0; $attempt < $readAttempts; $attempt++) {
            $observedColumn = $this->readToolCallColumnFresh($messageId);
            if (is_array($observedColumn)
                && (string)($observedColumn['executionLease'] ?? '') === $lease
                && in_array($observedColumn['status'] ?? null, [AIConversationMessage::STATUS_COMPLETED, AIConversationMessage::STATUS_FAILED], true)
            ) {
                $reloadedToolCall = new ToolCall();
                $reloadedToolCall->mapFromRepository(json_decode((string)json_encode($observedColumn), false));
                return $reloadedToolCall;
            }
            if ($attempt + 1 < $readAttempts) {
                usleep(100000);
            }
        }
        $this->logMissingOutcome($conversation, $messageId, $exitCode, $process, $observedColumn, $lease);
        if ($failureText !== null) {
            return $failureText;
        }
        return sprintf(
            'Tool error: the parallel tool worker did not report an outcome (exit code %s). Retry the call once; if it fails again, continue without it.',
            $exitCode === null ? 'none' : (string)$exitCode
        );
    }

    /**
     * Block until every started child of the fork exited (or outlived its timeout and was killed) — before the loop
     * adopts and persists the first forked outcome: a parent persist re-encodes the capability column
     * of the step's later rows, which a child still writing its own row made fail with 1020. The failure text of a
     * timed-out or failed wait is kept for that call's adoption ({@see waitForOutcome()}). Costs no wall time: the step
     * waited for its slowest child anyway.
     */
    public function waitForAllChildrenOfFork(AgentToolCallFork $agentToolCallFork, AIConversation $conversation): void
    {
        if ($agentToolCallFork->allChildrenExited) {
            return;
        }
        foreach ($agentToolCallFork->processesByToolCallId as $toolCallId => $process) {
            $failureText = $this->waitForChildExit($agentToolCallFork, (string)$toolCallId, $process, $conversation);
            if ($failureText !== null) {
                $agentToolCallFork->waitFailureTextByToolCallId[(string)$toolCallId] = $failureText;
            }
        }
        $agentToolCallFork->allChildrenExited = true;
    }

    /** Wait for one child to exit; the failure text of a timed-out or failed wait, null when it exited on its own. */
    protected function waitForChildExit(AgentToolCallFork $agentToolCallFork, string $toolCallId, Process $process, AIConversation $conversation): ?string
    {
        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            return sprintf(
                "Tool error: tool '%s' exceeded the %ds execution timeout and was aborted — the underlying call (likely a stuck external API) did not return.",
                $this->toolNameForTrace($conversation, (int)($agentToolCallFork->messageIdsByToolCallId[$toolCallId] ?? 0)),
                $agentToolCallFork->timeoutSecondsByToolCallId[$toolCallId] ?? 0
            );
        } catch (Throwable $waitError) {
            return 'Tool error: the parallel tool worker could not be awaited (' . $waitError->getMessage() . ').';
        }
        return null;
    }

    /**
     * The `toolCall` column of one row as the database holds it NOW: one statement on the connection, no entity
     * hydration, no identity map, no registry. Inside an open transaction a plain SELECT would answer from the
     * transaction's snapshot (REPEATABLE READ on MariaDB) and never see the child's commit — a locking read (the
     * dialect's `forShare()`) reads the latest committed version instead; the child is done, so nothing contends for
     * the lock.
     *
     * @return array<string, mixed>|null the decoded column, null when the row or the column is absent
     */
    protected function readToolCallColumnFresh(int $messageId): ?array
    {
        try {
            $connection = EntityManagerFactory::getInstance()->getConnection();
            $statement = 'SELECT `toolCall` FROM `' . DBAIConversationMessageModel::getTableName() . '` WHERE `id` = :id'
                . ($connection->isTransactionActive() ? ' ' . EntityManagerFactory::getDialect()->forShare() : '');
            $columnJson = $connection->fetchOne($statement, ['id' => $messageId]);
        } catch (Throwable) {
            return null;
        }
        if (!is_string($columnJson) || $columnJson === '') {
            return null;
        }
        $decoded = json_decode($columnJson, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** What the parent saw when a child left no owned outcome — the error log carries it, the trace too. */
    protected function logMissingOutcome(AIConversation $conversation, int $messageId, ?int $exitCode, Process $process, ?array $observedColumn, string $lease): void
    {
        $context = [
            'conversationId' => $conversation->id ?? null,
            'messageId' => $messageId,
            'tool' => $this->toolNameForTrace($conversation, $messageId),
            'exitCode' => $exitCode,
            'observedStatus' => $observedColumn['status'] ?? null,
            'observedLeaseMatches' => is_array($observedColumn) && (string)($observedColumn['executionLease'] ?? '') === $lease,
            'stdoutTail' => mb_substr(trim($process->getOutput()), -300),
            'stderrTail' => mb_substr(trim($process->getErrorOutput()), -300),
        ];
        try {
            $context['transactionActive'] = EntityManagerFactory::getInstance()->getConnection()->isTransactionActive();
        } catch (Throwable) {
            $context['transactionActive'] = null;
        }
        try {
            DDDService::instance()->getLogger()->error('[agent] fork-join child left no owned outcome ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            // logging never fails the turn
        }
        $this->aiTrace('turn.toolCall.forkJoinMissingOutcome', $context);
    }

    /**
     * The batch row for the admin inspector: one per fork, with the count of its calls and the deadline the parent
     * enforces (the longest declared ceiling plus the grace). Written with rights lifted (the worker's auth is the
     * conversation's account, but a delegation child of another account's conversation may drive this turn); a failed
     * write never fails the turn.
     */
    public function recordBatchStarted(AgentToolCallFork $agentToolCallFork, AIConversation $conversation): void
    {
        $emittedBatchId = $agentToolCallFork->emittedBatchId;
        $expectedCount = count($agentToolCallFork->toolCalls);
        $maxTimeoutSeconds = max($agentToolCallFork->declaredTimeoutSecondsByToolCallId);
        if (!isset($conversation->id) || $emittedBatchId === '') {
            return;
        }
        try {
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $batch = AIConversationToolCallBatch::newInstance();
                $batch->conversationId = (int)$conversation->id;
                $batch->emittedBatchId = $emittedBatchId;
                $batch->expectedCount = $expectedCount;
                $batch->deadlineAt = DateTime::fromTimestamp(time() + $maxTimeoutSeconds + self::PARENT_TIMEOUT_GRACE_SECONDS);
                $batch->sourceUserMessageId = $conversation->getNewestUserMessage()?->id !== null ? (int)$conversation->getNewestUserMessage()->id : null;
                $batch->runToken = isset($conversation->runToken) ? mb_substr((string)$conversation->runToken, 0, 64) : null;
                $batch->update(0);
                $agentToolCallFork->toolCallBatchId = (int)$batch->id;
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        } catch (Throwable $recordError) {
            $this->aiTrace('turn.toolCall.forkBatch.recordFailed', ['conversationId' => $conversation->id ?? null, 'error' => $recordError->getMessage()]);
        }
    }

    /** Every forked call of the fork was joined and fed to the model: its batch row is settled and consumed at once. */
    public function recordBatchFinished(AgentToolCallFork $agentToolCallFork, AIConversation $conversation): void
    {
        $batchId = $agentToolCallFork->toolCallBatchId;
        if ($batchId === null) {
            return;
        }
        $agentToolCallFork->toolCallBatchId = null;
        try {
            $batch = AIConversationToolCallBatch::newInstance();
            $batch->id = $batchId;
            $now = new DateTime();
            $batch->settledAt = $now;
            $batch->consumedAt = $now;
            $batch->updatePartialIgnoringRights('settledAt', 'consumedAt');
        } catch (Throwable $recordError) {
            $this->aiTrace('turn.toolCall.forkBatch.finishFailed', ['conversationId' => $conversation->id ?? null, 'error' => $recordError->getMessage()]);
        }
    }

    /** Kill every child of the fork still running — the turn is aborting (claim lost, throw) and nobody will read their rows. */
    public function stopAllChildren(AgentToolCallFork $agentToolCallFork): void
    {
        foreach ($agentToolCallFork->processesByToolCallId as $toolCallId => $process) {
            try {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            } catch (Throwable) {
                // best effort
            }
            unset($agentToolCallFork->processesByToolCallId[$toolCallId]);
        }
    }

    /**
     * Start the children of the step's fork and write its inspector row (moved from the agent loop). A child must not inherit a live curl handle: the in-flight realtime signal is settled before the fork.
     */
    public function startChildrenOfFork(AgentToolCallFork $agentToolCallFork, AIConversation $conversation): void
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        $aIConversationRealtimeSignalService->settleInFlightSignal();
        $this->startChildren($agentToolCallFork, $conversation);
        $this->recordBatchStarted($agentToolCallFork, $conversation);
    }

    /**
     * Join one forked call (moved from the agent loop): wait for its child, take the outcome
     * the child wrote into the row onto the in-memory ToolCall (the same VO the loop's post-processing and the wire
     * read), and run the result post-processing the inline path runs after
     * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::executeToolCall()} — the
     * delete-retry disambiguation and the capability usage stamps. A child without an owned outcome (killed, crashed,
     * lease lost) leaves the FAILED text the model reads like any other tool failure. Images: a parallel-safe tool
     * attaches none (the admission rule), so there is no transient stash to reproduce.
     */
    public function adoptForkedToolCallOutcome(
        AgentToolCallFork $agentToolCallFork,
        AIConversation $conversation,
        ToolCall $toolCall,
        AIConversationMessage $callMessage
    ): void {
        $toolName = (string)($toolCall->name ?? '');
        $outcome = $this->waitForOutcome($agentToolCallFork, (string)$toolCall->toolCallId, $conversation);
        if ($outcome instanceof ToolCall) {
            $toolCall->result = $outcome->result;
            $toolCall->resultAppendix = $outcome->resultAppendix;
            $toolCall->platformNoteForModel = $outcome->platformNoteForModel;
            $toolCall->bindableResult = $outcome->bindableResult;
            $toolCall->dictatedWidgetType = $outcome->dictatedWidgetType;
            $toolCall->status = $outcome->status;
            $toolCall->durationSeconds = $outcome->durationSeconds;
            $toolCall->executionStartedAt = $outcome->executionStartedAt;
            $toolCall->imageReferences = $outcome->imageReferences;
            $toolCall->wasRefusedByGate = $outcome->wasRefusedByGate;
            $toolCall->executionLease = $outcome->executionLease;
            // what the child's execution booked (the search fee, the page readings) and the endpoints it called: the
            // loop's next persist rewrites the whole toolCall column from this VO, so a field not copied is erased
            $toolCall->executionCosts = $outcome->executionCosts;
            $toolCall->argusEndpointTimings = $outcome->argusEndpointTimings;
        } else {
            $toolCall->markFailed();
            $toolCall->result = $outcome;
            /** @var AgentToolCallDispatchService $agentToolCallDispatchService */
            $agentToolCallDispatchService = DDDService::instance()->getService(AgentToolCallDispatchService::class);
            $agentToolCallDispatchService->logToolFailure($conversation, $toolName, $toolCall->arguments, $outcome, null, $callMessage->aiModelName ?? null);
        }
        // The inline path's post-execution steps (dispatchToolCall, real-execution branch), verbatim in effect.
        if ($toolCall->status === AIConversationMessage::STATUS_FAILED
            && !$toolCall->wasRefusedByGate
            && $toolCall->isDelete()
            && $conversation->messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($toolCall)
        ) {
            $toolCall->result = sprintf(
                'This `%s` already COMPLETED earlier in this conversation with exactly these arguments '
                . '— the target is gone because YOU deleted it. Treat this as SUCCESS; NEVER report '
                . 'missing access or a failed deletion. If a list read still shows the item, that list '
                . 'is stale — do not retry the deletion.',
                $toolName
            );
            $toolCall->markCompleted();
        }
        $callMessage->recordCapabilityUseOf($toolCall, $conversation);
        $this->aiTrace('turn.toolResult', ['conversationId' => $conversation->id ?? null, 'name' => $toolName, 'status' => $toolCall->status, 'forked' => true]);
    }

    protected function toolNameForTrace(AIConversation $conversation, int $messageId): string
    {
        foreach ($conversation->messages?->getElements() ?? [] as $message) {
            if ((int)($message->id ?? 0) === $messageId && isset($message->toolCall)) {
                return (string)($message->toolCall->name ?? '?');
            }
        }
        return '?';
    }
}
