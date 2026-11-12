<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method — or a whole MCPPackage (all its tools) — as a PIN: once loaded it is NEVER evicted by the
 * per-turn sweep. It is the ORTHOGONAL companion
 * of {@see MCPToolAlwaysLoad}:
 *   - {@see MCPToolAlwaysLoad} controls INITIAL residency (in the set from turn 1 / on skill-load, no `load_tool` hop) —
 *     but the loaded tool stays EVICTABLE (a stale always-load tool IS swept).
 *   - this NoEviction marker controls EVICTION EXEMPTION (the sweep skips it in SOFT, CASCADE and HARD) — but says
 *     nothing about when it first loads.
 * A tool that must be permanently available (e.g. web search) carries BOTH. A tool can be always-load WITHOUT being
 * pinned (the default for the cheap central tools), and a SKILL can be pinned without being always-load (its skill-level
 * `nonEvictable: true` frontmatter flag, {@see \DDD\Domain\AI\Entities\Skills\AISkill::$nonEvictable}).
 *
 * The pinned set is FIXED always-resident overhead exempt from the HARD ceiling too — so keep it FEW and SMALL (a tool
 * schema is ~hundreds of tokens). A pinned floor that alone approaches `hardCapTokens` is a misconfiguration.
 *
 *   #[MCPTool, MCPToolNoEviction('web search must always be reachable — never swept')]
 *   public function searchTheWeb(...): ... {}
 *
 *   #[MCPToolAlwaysLoad('resident from turn 1'), MCPToolNoEviction('and never swept')]
 *   class WebSearchMCPPackage extends MCPPackage { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolNoEviction
{
    public function __construct(public string $reason = '')
    {
    }
}
