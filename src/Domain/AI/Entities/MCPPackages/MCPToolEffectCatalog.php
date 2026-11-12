<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;

/**
 * The declared {@see MCPToolEffect} of every agent tool, by tool name — for the readers that hold only a STORED tool
 * call (its name), not its definition: the supervisor, the paused-turn record, the write-less owner-turn windows, the
 * compaction telemetry, the identical-write repeat refusal, the record-change check after a completed call, the
 * fork-join, the per-turn read reuse, the eval read-only gate.
 *
 * Built once per process from the `#[MCPToolEffect]` attributes of the tool methods {@see MCPToolAttributeCatalog} found
 * in the packages every skill carries (one reflection walk shared by both catalogs) — code metadata, the same in every
 * process — plus the effects of the loop's own meta tools, which are no MCP tools. A name the catalog does not know (a tool removed since the row was
 * written) has no effect: it is neither a read nor a write.
 */
class MCPToolEffectCatalog
{
    /** @var array<string, MCPToolEffect|null>|null tool name → declared effect, built on first use */
    protected static ?array $effectsByToolName = null;

    /** The declared effect of the tool with this name, or null when the tool declares none or is unknown. */
    public static function getEffectForToolName(string $toolName): ?MCPToolEffect
    {
        return self::getEffectsByToolName()[$toolName] ?? null;
    }

    /** True when the named tool changes nothing outside the conversation; false for a write and for an unknown name. */
    public static function isReadOnlyToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->isReadOnly() === true;
    }

    /**
     * True when an identical repeat of the named READ within the owner turn gets the earlier result back
     * ({@see MCPToolEffect::isReusedWithinTurn()}) — the per-turn read reuse and its readers ask this. False for a write,
     * a READ declared `resultIsReusedWithinTurn: false`, a meta tool and an unknown name.
     */
    public static function isReusableReadToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->isReusedWithinTurn() === true;
    }

    /** True when the named tool creates, changes, publishes, sends, starts or removes something. */
    public static function changesStateForToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->changesState() === true;
    }

    /** True when the named tool removes something — its "not found" after an identical completed call means it is gone. */
    public static function isDeleteToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->effect === MCPToolEffect::DELETE;
    }

    /** True when the named tool's identical repeat does no new work and is refused ({@see MCPToolEffect::refusesIdenticalRepeat()}). */
    public static function refusesIdenticalRepeatForToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->refusesIdenticalRepeat() === true;
    }

    /** True when the named tool's completed call acts outward or needs the owner's explicit yes ({@see MCPToolEffect::$isOutwardAction}). */
    public static function isOutwardActionToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->isOutwardAction === true;
    }

    /** True when the named tool's completed call leaves the earlier reads of the turn reusable ({@see MCPToolEffect::$keepsEarlierReadsReusable}). */
    public static function keepsEarlierReadsReusableForToolName(string $toolName): bool
    {
        return self::getEffectForToolName($toolName)?->keepsEarlierReadsReusable === true;
    }

    /** @return array<string, MCPToolEffect|null> */
    protected static function getEffectsByToolName(): array
    {
        if (self::$effectsByToolName !== null) {
            return self::$effectsByToolName;
        }
        $effectsByToolName = [];
        // The loop's meta tools declare their own effect (`DECLARED_EFFECT`); `delegate`, `set_task_status` and `askOwner`
        // declare none. No meta READ is reused within the turn: a repeated `load_skills`, `load_tool`,
        // `check_delegations` or `get_task_plans` reads the loop's own state, which moves.
        // A meta tool that changes no account data declares `KEEPS_EARLIER_READS_REUSABLE` (`findWidgets`:
        // a catalog search, even one that loads a skill, leaves the turn's earlier reads valid).
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            $keepsEarlierReadsReusable = defined($metaToolClass . '::KEEPS_EARLIER_READS_REUSABLE') && constant($metaToolClass . '::KEEPS_EARLIER_READS_REUSABLE') === true;
            $effectsByToolName[$metaToolClass::NAME] = $metaToolClass::DECLARED_EFFECT !== null
                ? new MCPToolEffect($metaToolClass::DECLARED_EFFECT, resultIsReusedWithinTurn: false, keepsEarlierReadsReusable: $keepsEarlierReadsReusable)
                : null;
        }
        foreach (array_keys(MCPToolAttributeCatalog::getToolMethodsByToolName()) as $toolName) {
            $effectsByToolName[$toolName] = MCPToolAttributeCatalog::getAttributeOfToolName($toolName, MCPToolEffect::class);
        }
        self::$effectsByToolName = $effectsByToolName;

        return $effectsByToolName;
    }
}
