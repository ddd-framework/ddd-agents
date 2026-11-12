<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method — or a whole MCPPackage — as a tool whose ENTIRE PURPOSE is to report CHANGED state, so
 * two byte-identical calls legitimately return different results. A browser `snapshot()` / `screenshot()`
 * is called with identical (often empty) arguments after every action; the generic repeat guard
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck}::MAX_REPEATED_TOOL_CALLS) would refuse the
 * third such call with the FACTUALLY WRONG feedback "and the result has not changed" and abort the turn on the fourth —
 * killing the very loop image support exists for.
 *
 * This is the DECLARATIVE exemption the guard reads instead of a hardcoded name set: a tool carrying this attribute is
 * never refused or aborted as a no-progress repeat. It does NOT exempt the per-turn step budget (the real backstop
 * against a runaway poll) — only the identical-arguments repeat guard.
 *
 *   #[MCPTool, MCPToolRefreshesState('reports the current viewport — identical args, changed pixels after each action')]
 *   public function takeScreenshot(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolRefreshesState
{
    public function __construct(public string $reason = '')
    {
    }
}
