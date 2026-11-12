<?php

declare(strict_types=1);

/**
 * The console command that executes ONE persisted tool call in a process of its own — read as
 * `Config::get('AI.agentToolCallChildProcess')` by
 * {@see \DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService}. The module ships no console command:
 * establishing the account a tool runs under and accounting its usage is the application's. An application that
 * wants the parallel-safe tool calls of one model response to run concurrently replaces this file whole with its own
 * `config/app/AI/agentToolCallChildProcess.php` (keys are never merged). The package default declares none, and every
 * call runs inline.
 *
 * `consoleScript`: the application's console script, relative to the kernel project directory (e.g. `bin/console`).
 * `commandName`: the name of the application's command.
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
    'commandName' => null,
];
