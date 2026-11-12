<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;

/**
 * The upward port of an inline delegation child: the delegation spawn prepares a sync (CLI / eval)
 * child and runs its turn through the loop as this port — never resolved from DI — so the child's loop is the loop's own
 * and a test double of the loop keeps driving it.
 */
interface AgentChildTurnRunnerInterface
{
    /**
     * Run the child's loop in this process, as its own run inside `$parentRun` (null: the parent runs outside a run);
     * the child creates no children of its own (one-level delegation).
     */
    public function runInlineChildTurn(AIConversation $childConversation, AgentTransientMessageIds $transientMessageIds, ?string $modelNameOverride, ?AgentConsoleStream $consoleStream, ?AgentRun $parentRun = null): void;
}
