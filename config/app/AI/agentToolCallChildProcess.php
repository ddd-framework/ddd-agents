<?php

declare(strict_types=1);

use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;

/**
 * The console command that executes ONE persisted tool call in a process of its own — read as
 * `Config::get('AI.agentToolCallChildProcess')` by {@see AgentToolCallForkJoinService}. The module ships the command
 * ({@see \DDD\Symfony\Commands\Agents\AgentExecuteToolCallCommand}, `app:ai-agent:execute-tool-call`); what the
 * application declares is its console script. An application that wants the parallel-safe tool calls of one model
 * response to run concurrently replaces this file whole with its own `config/app/AI/agentToolCallChildProcess.php`
 * (keys are never merged) that names its `consoleScript`. Without a console script every call runs inline.
 *
 * `consoleScript`: the application's console script, relative to the kernel project directory (e.g. `bin/console`);
 *   null = no child processes.
 * `commandName`: the command a child runs; a file that omits it (or sets null) gets the module's command
 *   ({@see AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME}). Name another command only when the
 *   application replaces the module's command under a name of its own.
 *
 * The turn worker starts `php <project directory>/<consoleScript> --no-debug <commandName> <messageId> --lease=<token>
 * --timeout=<seconds>` and adds `--run-token=<token>` (the worker's claim on the conversation; the child adopts it
 * with `AIConversationRunClaimsService::adoptRunTokenOfParentProcess()`), `--blocked-tools=<names, comma-separated>`
 * and `--every-write-blocked` (the blocks of `MCPToolExecutorService` in the worker's process) when they apply.
 *
 * The command loads the tool call row `<messageId>` and its conversation, claims the row with
 * `AIConversationMessagesService::claimToolCallExecutionLease()`, executes the call with
 * `AgentToolCallExecutionService::executeToolCall()` under the given timeout, and writes the outcome with
 * `AIConversationMessagesService::writeToolCallOutcomeIfLeaseHeld()`. It exits with 0 when the outcome is written (a
 * failed tool is an outcome), 3 when the lease could not be claimed, 4 when the outcome write was refused, 5 when the
 * worker's claim on the conversation is lost, 1 when the row or the conversation could not be loaded.
 */
return [
    'consoleScript' => null,
    'commandName' => AgentToolCallForkJoinService::DEFAULT_CHILD_PROCESS_COMMAND_NAME,
];
