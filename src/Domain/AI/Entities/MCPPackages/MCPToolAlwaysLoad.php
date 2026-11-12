<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method — or a whole MCPPackage (all its tools) — to EAGER-load on skill-load REGARDLESS of the
 * `ContextRetentionSettings::$loadToolsOnSkillLoad` mode.
 * For tools that are important but CHEAP — the model should never have to spend a `load_tool` hop to FIRST reach them.
 *
 * This affects the INITIAL provisioning ONLY: the tool is auto-loaded when its skill loads, even in lazy mode. It is
 * NOT a pin — once loaded, the eviction sweep treats it exactly like every other tool (a stale, unused always-load tool
 * IS evicted, and the model re-loads it via `load_tool` if it needs it again). Downstream it survives only as a
 * display marker ({@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability::$isAlwaysLoaded}).
 *
 * Decision rule: apply when the tool is small (low `residentTokens` — see the application's skill token map) AND
 * routinely needed when its skill is active, so the per-turn resident cost is negligible but the saved hop / avoided
 * stall is real. Apply at CLASS level when an entire package's tools are all cheap + central (e.g. the Memory tools).
 * Do NOT apply to heavy tools (big schemas) — those should stay lazy so they only cost context when actually used.
 *
 *   #[MCPTool, MCPToolAlwaysLoad('central read, cheap — needed whenever the bookings skill is active')]
 *   public function getBookings(...): ... {}
 *
 *   #[MCPToolAlwaysLoad('all memory tools are small and central to the chat')]
 *   class MemoryMCPPackage extends MCPPackage { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolAlwaysLoad
{
    public function __construct(public string $reason = '')
    {
    }
}
