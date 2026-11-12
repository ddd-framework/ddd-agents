<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method — or a whole MCPPackage — as safe to run CONCURRENTLY with the other calls of the same
 * model response, each in its own short-lived console process (the fork-join of
 * {@see \DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService}).
 * When a model response carries two or more calls to tools that declare this, the loop runs them at once instead
 * of one after another — run in sequence, several look-ups of one response cost the sum of their durations.
 *
 * Declare it only when ALL of this holds for the tool: it writes no conversation row (no widget append, no signal),
 * warms no per-space state a sibling could observe half-written, and attaches no transient images to its result
 * (a child process cannot hand image bytes to the turn). A READ must be idempotent. A WRITE may declare it ONLY when
 * every row it writes is its OWN — own-table rows set-level, and on a row it shares with other tools
 * strictly its own columns through a partial write — never a whole-row upsert mapped from memory, which writes a
 * sibling's freshly written columns back to their old values. A shared JSON column
 * is written under the host's per-space lock.
 * The `reason` names the rows and columns audited. A read by name is NOT enough: a tool named `get…` or `offer…`
 * may append a widget or warm state.
 *
 * The loop keeps two rules around the admission: two calls of the SAME tool never fork (the declaration makes the
 * tool's rows disjoint from other tools', not from its own), and the children start only after the response's
 * inline calls completed when one of those may write (a non-admitted writer may write a whole row).
 *
 *   #[MCPTool, MCPToolParallelSafe('one external look-up, reads only')]
 *   public function searchFreeSlots(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolParallelSafe
{
    public function __construct(public string $reason = '')
    {
    }
}
