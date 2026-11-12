<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinition;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One loaded capability — a skill BODY or a tool SCHEMA resident in the model prompt — plus the USAGE TELEMETRY the
 * eviction policy reads (its cost-benefit score).
 * The shared item carried by both {@see LoadedSkills} and {@see LoadedTools}; the telemetry + scoring live HERE (logic
 * on the object), the sets only sum/sweep.
 *
 * The telemetry is EVIDENT CODE — model-INVISIBLE, never wire-rendered — maintained incrementally (O(1)/turn): on load
 * {@see markLoadedAt()} stamps the baseline, on each call {@see markUsedAt()} resets recency + bumps the count. The loop
 * reads {@see shouldUnload()} once per appended turn.
 *
 * The decision is a COST-BENEFIT product, NOT a watermark drain:
 *
 *     evictionScore = staleness × sizeFactor × pressureFactor      (evict when ≥ 1)
 *
 *   - staleness      = how URGENTLY it is still needed (inverse): max over TWO recency axes — token-displacement
 *                      (a pasted page pushes it out of the attended window fast) OR turn-staleness (the topic moved on),
 *                      each normalised by its horizon × a usage-stickiness stretch. Size-INDEPENDENT.
 *   - sizeFactor     = what it COSTS us: residentTokens / referenceResidentTokens (the cached static cost). A big body
 *                      ages out at a lower staleness than a small tool schema.
 *   - pressureFactor = how crowded the loaded set is: loadedCapabilityTokens / pressureBudgetTokens (skills+tools only,
 *                      NO messages). High load lowers every item's bar smoothly; a FRESH item (staleness ≈ 0) still
 *                      never evicts, so this is a continuous pressure scaling, NOT a watermark sweep.
 *
 * @phpstan-consistent-constructor
 */
class LoadedCapability extends ValueObject
{
    public const string TYPE_SKILL = 'SKILL';

    public const string TYPE_TOOL = 'TOOL';

    // Global fallbacks when the per-conversation {@see ContextRetentionSettings} field is null. Tuned via the simulator
    // (a console command of the application); the production defaults land in AIConversation consts once observed.
    public const int DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS = 8000;

    public const int DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS = 10;

    public const int DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS = 5000;

    public const int DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS = 8;

    public const int DEFAULT_REFERENCE_RESIDENT_TOKENS = 500;

    // SOFT target (8k → 24k). Enters the TOOL score only — under the default `skill` unloading
    // it has no soft effect; under `tool` unloading it moves with the hard cap so the soft path and the jetsam agree.
    public const int DEFAULT_PRESSURE_BUDGET_TOKENS = 24000;

    // HARD ceiling (12k → 40k) — the carried skill+tool load is force-evicted back under this (jetsam). A free
    // chat carries 10–20k of resident capabilities; the old 12k made the jetsam a per-step prefix mutation.
    public const int DEFAULT_HARD_CAP_TOKENS = 40000;

    public const int DEFAULT_MIN_RESIDENCY_TURNS = 2;

    // --- provenance: WHO loaded this capability. Null = the model itself (legacy rows and load_skills). ---
    public const string SOURCE_MODEL = 'model';

    public const string SOURCE_STRUCTURAL = 'structural';

    public const string SOURCE_PLAN_ITEM = 'planItem';

    public const string SOURCE_DECISION_MODEL = 'decisionModel';

    /**
     * @var array<string, string> THE field list of a capability: canonical field name → compact key of a
     *      change event, in the canonical order every canonical entry carries ({@see toCanonicalEntry()}).
     */
    public const array FIELD_COMPACT_KEYS = [
        'name' => 'n',
        'type' => 't',
        'parentSkillNames' => 'p',
        'residentTokens' => 'rt',
        'isAlwaysLoaded' => 'al',
        'nonEvictable' => 'ne',
        'inputTokensAtLastUse' => 'it',
        'turnIndexAtLastUse' => 'ti',
        'usageCountSinceLoad' => 'u',
        'lastUsedInitiatorIndex' => 'li',
        'source' => 'src',
        'loadedAtTurnInitiatorMessageIndex' => 'lti',
        'loadedAtMessageIndex' => 'lmi',
        'descendantUsageCountSinceLoad' => 'du',
        'descendantLastUsedTurnInitiatorIndex' => 'dli',
    ];

    /** @var array<string, mixed> canonical field name → its default (the property initialisers below; what a compact entry omits) */
    public const array FIELD_DEFAULTS = [
        'type' => self::TYPE_TOOL,
        'parentSkillNames' => [],
        'residentTokens' => 0,
        'isAlwaysLoaded' => false,
        'nonEvictable' => false,
        'inputTokensAtLastUse' => 0,
        'turnIndexAtLastUse' => 0,
        'usageCountSinceLoad' => 0,
        'lastUsedInitiatorIndex' => null,
        'source' => null,
        'loadedAtTurnInitiatorMessageIndex' => null,
        'loadedAtMessageIndex' => null,
        'descendantUsageCountSinceLoad' => null,
        'descendantLastUsedTurnInitiatorIndex' => null,
    ];

    /** @var string[] the fields a {@see markUsedAt()} stamp changes — a change of only these is a `use` event */
    public const array USE_FIELDS = ['inputTokensAtLastUse', 'turnIndexAtLastUse', 'usageCountSinceLoad', 'lastUsedInitiatorIndex'];

    /** The capability's name — a dotted skill name (e.g. `Seo.Rankings`) or a tool name (e.g. `getRankings`). */
    public string $name = '';

    /** TYPE_SKILL (a resident body) or TYPE_TOOL (a resident schema). */
    public string $type = self::TYPE_TOOL;

    /**
     * The dotted names of the PARENT capabilities (skills) this one belongs to — the cascade key. For a TOOL: the
     * skill(s) that expose it (usually one, but a tool MAY be shared by several skills). For a SKILL: its parent
     * skill(s), enabling sub-skills + multi-level skill cascades (empty for a top-level skill). The cascade rule
     * generalises: a SKILL capability degrades once NO loaded capability (tool OR sub-skill) lists its name here.
     *
     * @var string[]
     */
    public array $parentSkillNames = [];

    /** The cached STATIC context cost in tokens — skill `residentBodyTokens` or tool `residentSchemaTokens`, BPE-measured once at doc-render. */
    public int $residentTokens = 0;

    /**
     * DISPLAY MARKER ONLY — NO eviction effect. True when this capability carries #[MCPToolAlwaysLoad]: it is
     * auto-provisioned on skill-load even in LAZY mode (so the model never spends a `load_tool` hop to first reach it).
     * It does NOT pin the tool or exempt it from eviction — the unload methodology is identical for every tool. The
     * admin UI reads it to show which tools were auto-loaded (independent of eager/lazy).
     */
    public bool $isAlwaysLoaded = false;

    /**
     * The PIN — TRUE eviction exemption (unlike {@see $isAlwaysLoaded}, which is display-only). True when the source
     * carries #[MCPToolNoEviction] (tool/package) or, for a skill body, the skill's `nonEvictable: true` frontmatter.
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities::computeEviction()} skips a pinned
     * capability in ALL three branches (SOFT score-unload, CASCADE childless-skill, HARD ceiling/jetsam) — it is fixed
     * always-resident overhead. The admin capability view shows it as 📌 (distinct from the ⚡ always-load-but-evictable).
     */
    public bool $nonEvictable = false;

    // --- usage telemetry (evident code, model-invisible) ---

    /** The conversation's context input-token footprint when this capability was last USED (or loaded). */
    public int $inputTokensAtLastUse = 0;

    /** The conversation's turn index when this capability was last USED (or loaded). */
    public int $turnIndexAtLastUse = 0;

    /** How many times this capability has been called since it was loaded (stickiness signal; 0 = loaded, not yet used). */
    public int $usageCountSinceLoad = 0;

    /**
     * The `indexInConversation` of the OWNER INITIATOR of the turn this capability was last loaded or used in
     * — the recency unit of the SKILL-granular unloading. Monotonic and compaction-proof, unlike
     * {@see $turnIndexAtLastUse} (a count over the LOADED tail that drops below the stamps after a fold). Stamped only
     * while the conversation unloads per skill; null under `tool` unloading and on legacy rows, so those persist
     * byte-identical. Serialized only when set.
     */
    public ?int $lastUsedInitiatorIndex = null;

    // --- provenance + speculative-usage telemetry — serialized ONLY when set, so a set without any
    // preload persists byte-identical to before; read ONLY by the speculative cleanup, never by the eviction score. ---

    /** Who loaded it: one of the SOURCE_* constants; null = the model (a `load_skills` call) or a row from before provenance. */
    public ?string $source = null;

    /** The `indexInConversation` of the turn's INITIATOR when this capability was loaded (the cleanup's turn unit — NOT the USER-message counter). */
    public ?int $loadedAtTurnInitiatorMessageIndex = null;

    /** The `indexInConversation` of the message the capability was promoted onto. */
    public ?int $loadedAtMessageIndex = null;

    /** SPECULATIVE parents only: successful calls of any descendant tool since load (a body is never "called" itself). */
    public ?int $descendantUsageCountSinceLoad = null;

    /** SPECULATIVE parents only: the initiator index of the turn a descendant tool was last called in. */
    public ?int $descendantLastUsedTurnInitiatorIndex = null;

    /**
     * A freshly loaded skill BODY: its resident tokens and pin from the skill, stamped loaded from `$origin`.
     *
     * @param string[] $parentSkillNames
     */
    public static function fromSkillBody(AISkill $skill, array $parentSkillNames, LoadedCapabilityOrigin $origin): static
    {
        $skillCapability = new static();
        $skillCapability->name = $skill->name;
        $skillCapability->type = self::TYPE_SKILL;
        $skillCapability->parentSkillNames = $parentSkillNames;
        $skillCapability->residentTokens = $skill->getResidentBodyTokens();
        $skillCapability->isAlwaysLoaded = false; // a skill body is never an always-load TOOL; the marker is tool-only
        $skillCapability->nonEvictable = $skill->nonEvictable; // a SKILL body CAN be pinned (frontmatter nonEvictable: true)
        $skillCapability->markLoadedFrom($origin);
        return $skillCapability;
    }

    /** A freshly loaded tool SCHEMA under its (first) parent skill, stamped loaded from `$origin`. */
    public static function fromToolDefinition(ToolDefinition $toolDefinition, string $parentSkillName, LoadedCapabilityOrigin $origin): static
    {
        $toolCapability = new static();
        $toolCapability->name = $toolDefinition->name;
        $toolCapability->type = self::TYPE_TOOL;
        $toolCapability->parentSkillNames = [$parentSkillName];
        $toolCapability->residentTokens = $toolDefinition->residentSchemaTokens;
        $toolCapability->isAlwaysLoaded = $toolDefinition->isAlwaysLoad; // display marker only — does NOT exempt from eviction
        $toolCapability->nonEvictable = $toolDefinition->isNonEvictable; // the PIN — DOES exempt from eviction (all 3 branches)
        $toolCapability->markLoadedFrom($origin);
        return $toolCapability;
    }

    /**
     * The capability as a CANONICAL entry — every field with its full name, in the canonical order, raw values (the parents
     * re-indexed). The value form the change encoder diffs and the decoder rebuilds from.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalEntry(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'parentSkillNames' => array_values($this->parentSkillNames),
            'residentTokens' => $this->residentTokens,
            'isAlwaysLoaded' => $this->isAlwaysLoaded,
            'nonEvictable' => $this->nonEvictable,
            'inputTokensAtLastUse' => $this->inputTokensAtLastUse,
            'turnIndexAtLastUse' => $this->turnIndexAtLastUse,
            'usageCountSinceLoad' => $this->usageCountSinceLoad,
            'lastUsedInitiatorIndex' => $this->lastUsedInitiatorIndex,
            'source' => $this->source,
            'loadedAtTurnInitiatorMessageIndex' => $this->loadedAtTurnInitiatorMessageIndex,
            'loadedAtMessageIndex' => $this->loadedAtMessageIndex,
            'descendantUsageCountSinceLoad' => $this->descendantUsageCountSinceLoad,
            'descendantLastUsedTurnInitiatorIndex' => $this->descendantLastUsedTurnInitiatorIndex,
        ];
    }

    /**
     * A NEW capability from a canonical entry ({@see toCanonicalEntry()}) or a stored row — a missing field falls back to
     * its default, a nullable field present as null reads as null.
     *
     * @param array<string, mixed> $entry
     */
    public static function fromCanonicalEntry(array $entry): static
    {
        $capability = new static();
        $capability->name = (string)($entry['name'] ?? '');
        $capability->type = (string)($entry['type'] ?? self::TYPE_TOOL);
        $parents = $entry['parentSkillNames'] ?? [];
        $capability->parentSkillNames = is_array($parents) ? array_values(array_map('strval', $parents)) : [];
        $capability->residentTokens = (int)($entry['residentTokens'] ?? 0);
        $capability->isAlwaysLoaded = (bool)($entry['isAlwaysLoaded'] ?? false);
        $capability->nonEvictable = (bool)($entry['nonEvictable'] ?? false);
        $capability->inputTokensAtLastUse = (int)($entry['inputTokensAtLastUse'] ?? 0);
        $capability->turnIndexAtLastUse = (int)($entry['turnIndexAtLastUse'] ?? 0);
        $capability->usageCountSinceLoad = (int)($entry['usageCountSinceLoad'] ?? 0);
        $capability->lastUsedInitiatorIndex = isset($entry['lastUsedInitiatorIndex']) ? (int)$entry['lastUsedInitiatorIndex'] : null;
        $capability->source = isset($entry['source']) ? (string)$entry['source'] : null;
        $capability->loadedAtTurnInitiatorMessageIndex = isset($entry['loadedAtTurnInitiatorMessageIndex']) ? (int)$entry['loadedAtTurnInitiatorMessageIndex'] : null;
        $capability->loadedAtMessageIndex = isset($entry['loadedAtMessageIndex']) ? (int)$entry['loadedAtMessageIndex'] : null;
        $capability->descendantUsageCountSinceLoad = isset($entry['descendantUsageCountSinceLoad']) ? (int)$entry['descendantUsageCountSinceLoad'] : null;
        $capability->descendantLastUsedTurnInitiatorIndex = isset($entry['descendantLastUsedTurnInitiatorIndex']) ? (int)$entry['descendantLastUsedTurnInitiatorIndex'] : null;
        return $capability;
    }

    /**
     * The capability as a STORED checkpoint row — THE byte contract of the `loadedCapabilities` column (frozen by
     * a test of the application): nine fields always; the provenance and the speculative telemetry and the skill-granular recency stamp only when set, so a set without them persists exactly as
     * before; `lastUsedInitiatorIndex` LAST (its own order, not the canonical one); the parents written as held.
     *
     * @return array<string, mixed>
     */
    public function toRepositoryRow(): array
    {
        $row = [
            'name' => $this->name,
            'type' => $this->type,
            'parentSkillNames' => $this->parentSkillNames,
            'residentTokens' => $this->residentTokens,
            'isAlwaysLoaded' => $this->isAlwaysLoaded,
            'nonEvictable' => $this->nonEvictable,
            'inputTokensAtLastUse' => $this->inputTokensAtLastUse,
            'turnIndexAtLastUse' => $this->turnIndexAtLastUse,
            'usageCountSinceLoad' => $this->usageCountSinceLoad,
        ];
        if ($this->source !== null) {
            $row['source'] = $this->source;
            $row['loadedAtTurnInitiatorMessageIndex'] = $this->loadedAtTurnInitiatorMessageIndex;
            $row['loadedAtMessageIndex'] = $this->loadedAtMessageIndex;
        }
        if ($this->descendantUsageCountSinceLoad !== null) {
            $row['descendantUsageCountSinceLoad'] = $this->descendantUsageCountSinceLoad;
            $row['descendantLastUsedTurnInitiatorIndex'] = $this->descendantLastUsedTurnInitiatorIndex;
        }
        if ($this->lastUsedInitiatorIndex !== null) {
            $row['lastUsedInitiatorIndex'] = $this->lastUsedInitiatorIndex;
        }
        return $row;
    }

    /**
     * A canonical entry in the compact form of a change event — defaults omitted, the name always present.
     *
     * @param array<string, mixed> $canonicalEntry
     * @return array<string, mixed>
     */
    public static function compactCanonicalEntry(array $canonicalEntry): array
    {
        $compactEntry = ['n' => (string)($canonicalEntry['name'] ?? '')];
        foreach (self::FIELD_DEFAULTS as $field => $default) {
            $value = array_key_exists($field, $canonicalEntry) ? $canonicalEntry[$field] : $default;
            if ($value !== $default) {
                $compactEntry[self::FIELD_COMPACT_KEYS[$field]] = $value;
            }
        }
        return $compactEntry;
    }

    /**
     * A compact entry back in the canonical form — omitted fields restored to their defaults.
     *
     * @param array<string, mixed> $compactEntry
     * @return array<string, mixed>
     */
    public static function expandCompactEntry(array $compactEntry): array
    {
        $canonicalEntry = ['name' => (string)($compactEntry['n'] ?? '')];
        foreach (self::FIELD_DEFAULTS as $field => $default) {
            $compactKey = self::FIELD_COMPACT_KEYS[$field];
            $canonicalEntry[$field] = array_key_exists($compactKey, $compactEntry) ? $compactEntry[$compactKey] : $default;
        }
        return $canonicalEntry;
    }

    /** The capability name is the identity — so a {@see LoadedTools} ObjectSet dedups by name (re-adding a tool is a no-op). */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }

    /** Stamp the load baseline — recency starts here, usage count resets (stickiness factor = 1 until first use). */
    public function markLoadedAt(int $contextInputTokens, int $turnIndex, ?int $initiatorMessageIndex = null): void
    {
        $this->inputTokensAtLastUse = $contextInputTokens;
        $this->turnIndexAtLastUse = $turnIndex;
        $this->usageCountSinceLoad = 0;
        if ($initiatorMessageIndex !== null) {
            $this->lastUsedInitiatorIndex = $initiatorMessageIndex;
        }
    }

    /**
     * Stamp a NEW capability from its origin: the load baseline ({@see markLoadedAt()}) and, with a source, the provenance
     * — a carried-forward capability keeps its own.
     */
    public function markLoadedFrom(LoadedCapabilityOrigin $origin): void
    {
        $this->markLoadedAt($origin->contextInputTokens, $origin->turnIndex, $origin->recencyInitiatorIndex);
        if ($origin->source === null) {
            return;
        }
        $this->source = $origin->source;
        $this->loadedAtTurnInitiatorMessageIndex = $origin->turnInitiatorMessageIndex;
        $this->loadedAtMessageIndex = $origin->messageIndex;
    }

    /** Record a call — reset both recency axes to now, bump the stickiness count. */
    public function markUsedAt(int $contextInputTokens, int $turnIndex, ?int $initiatorMessageIndex = null): void
    {
        $this->inputTokensAtLastUse = $contextInputTokens;
        $this->turnIndexAtLastUse = $turnIndex;
        $this->usageCountSinceLoad++;
        if ($initiatorMessageIndex !== null) {
            $this->lastUsedInitiatorIndex = $initiatorMessageIndex;
        }
    }

    /**
     * The initiator index the skill-granular recency reads: the stamp when present, else the load
     * provenance of a preload, else null (a legacy or `tool`-mode row — the caller falls back to the turn count).
     */
    public function resolveRecencyInitiatorIndex(): ?int
    {
        return $this->lastUsedInitiatorIndex ?? $this->loadedAtTurnInitiatorMessageIndex;
    }

    /** Loaded by the decision model — the only capabilities the speculative cleanup may remove. */
    public function isSpeculative(): bool
    {
        return $this->source === self::SOURCE_DECISION_MODEL;
    }

    /** A successful call of a DESCENDANT tool — bumps the speculative-usage telemetry of a preloaded parent, nothing else. */
    public function markDescendantUsedAt(int $turnInitiatorMessageIndex): void
    {
        $this->descendantUsageCountSinceLoad = ($this->descendantUsageCountSinceLoad ?? 0) + 1;
        $this->descendantLastUsedTurnInitiatorIndex = $turnInitiatorMessageIndex;
    }

    /** True when this speculative capability has seen no use (own or descendant) and was loaded in an EARLIER turn than $currentTurnInitiatorMessageIndex. */
    public function isSpeculativeAndUnusedSince(int $currentTurnInitiatorMessageIndex): bool
    {
        if (!$this->isSpeculative() || $this->nonEvictable) {
            return false;
        }
        if ($this->usageCountSinceLoad > 0 || ($this->descendantUsageCountSinceLoad ?? 0) > 0) {
            return false;
        }
        return ($this->loadedAtTurnInitiatorMessageIndex ?? PHP_INT_MAX) < $currentTurnInitiatorMessageIndex;
    }

    /**
     * COPY-ON-WRITE: a resident capability is SHARED by every message's carried-forward set, so a
     * parent change must never mutate it in place (it would rewrite earlier messages' persisted history). Returns a
     * copy with $skillName added to the parents; the caller replaces the element at the same index.
     */
    public function withAddedParentSkillName(string $skillName): static
    {
        $copy = clone $this;
        if (!in_array($skillName, $copy->parentSkillNames, true)) {
            $copy->parentSkillNames[] = $skillName;
        }
        return $copy;
    }

    /**
     * COPY-ON-WRITE detach of several parents — a copy without any of `$skillNames` among its parents, the order of the
     * rest kept (see {@see withAddedParentSkillName()}).
     *
     * @param string[] $skillNames
     */
    public function withoutParentSkillNames(array $skillNames): static
    {
        $copy = clone $this;
        $copy->parentSkillNames = array_values(array_filter($copy->parentSkillNames, static fn (string $parent): bool => !in_array($parent, $skillNames, true)));
        return $copy;
    }

    /** COPY-ON-WRITE detach — a copy without $skillName among its parents (see {@see withAddedParentSkillName()}). */
    public function withoutParentSkillName(string $skillName): static
    {
        $copy = clone $this;
        $copy->parentSkillNames = array_values(array_filter($copy->parentSkillNames, static fn (string $parent): bool => $parent !== $skillName));
        return $copy;
    }

    /** Tokens of OTHER content that have entered the context since this capability's last use (the displacement axis). */
    public function tokenDeltaSinceLastUse(int $currentContextInputTokens): int
    {
        return max(0, $currentContextInputTokens - $this->inputTokensAtLastUse);
    }

    /** Turns elapsed since this capability's last use (the topical-staleness axis). */
    public function turnsSinceLastUse(int $currentTurnIndex): int
    {
        return max(0, $currentTurnIndex - $this->turnIndexAtLastUse);
    }

    /** A frequently-used capability clings — stretches BOTH recency horizons. `1 + ln(1 + usageCountSinceLoad)`. */
    public function usageStickinessFactor(): float
    {
        return 1.0 + log(1.0 + $this->usageCountSinceLoad);
    }

    /** The token-displacement horizon for this type, stretched by usage (settings override the per-type default). */
    public function tokenHorizon(ContextRetentionSettings $settings): float
    {
        $base = $this->type === self::TYPE_SKILL
            ? ($settings->unloadSkillAfterUnusedTokens ?? self::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS)
            : ($settings->unloadToolAfterUnusedTokens ?? self::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS);
        return $base * $this->usageStickinessFactor();
    }

    /** The turn-staleness horizon for this type, stretched by usage. */
    public function turnHorizon(ContextRetentionSettings $settings): float
    {
        $base = $this->type === self::TYPE_SKILL
            ? ($settings->unloadSkillAfterUnusedTurns ?? self::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS)
            : ($settings->unloadToolAfterUnusedTurns ?? self::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TURNS);
        return $base * $this->usageStickinessFactor();
    }

    /**
     * How stale this capability is — `max` of the two normalised recency ratios (token-displacement OR turn-staleness).
     * ≥ 1 means "stale on its own axis"; an axis whose horizon is 0 contributes 0 (disabled). Size-INDEPENDENT.
     */
    public function staleness(int $currentContextInputTokens, int $currentTurnIndex, ContextRetentionSettings $settings): float
    {
        $tokenHorizon = $this->tokenHorizon($settings);
        $turnHorizon = $this->turnHorizon($settings);
        $tokenStaleness = $tokenHorizon > 0 ? $this->tokenDeltaSinceLastUse($currentContextInputTokens) / $tokenHorizon : 0.0;
        $turnStaleness = $turnHorizon > 0 ? $this->turnsSinceLastUse($currentTurnIndex) / $turnHorizon : 0.0;
        return max($tokenStaleness, $turnStaleness);
    }

    /** What it costs to keep — `residentTokens / referenceResidentTokens`. A big body > 1, a small tool < 1. */
    public function sizeFactor(ContextRetentionSettings $settings): float
    {
        $reference = max(1, $settings->referenceResidentTokens ?? self::DEFAULT_REFERENCE_RESIDENT_TOKENS);
        return $this->residentTokens / $reference;
    }

    /** How crowded the loaded set is — `loadedCapabilityTokens / pressureBudgetTokens` (skills+tools only, no messages). */
    public function pressureFactor(int $loadedCapabilityTokens, ContextRetentionSettings $settings): float
    {
        $budget = max(1, $settings->pressureBudgetTokens ?? self::DEFAULT_PRESSURE_BUDGET_TOKENS);
        return $loadedCapabilityTokens / $budget;
    }

    /** The cost-benefit product: `staleness × sizeFactor × pressureFactor`. Evict when ≥ 1. */
    public function evictionScore(
        int $currentContextInputTokens,
        int $currentTurnIndex,
        int $loadedCapabilityTokens,
        ContextRetentionSettings $settings
    ): float {
        return $this->staleness($currentContextInputTokens, $currentTurnIndex, $settings)
            * $this->sizeFactor($settings)
            * $this->pressureFactor($loadedCapabilityTokens, $settings);
    }

    /**
     * Whether to unload this capability now: the footprint layer enabled, past the cold-start grace (in turns), and the
     * cost-benefit score ≥ 1. Identical for EVERY tool — there is no pin / always-load exemption.
     */
    public function shouldUnload(
        int $currentContextInputTokens,
        int $currentTurnIndex,
        int $loadedCapabilityTokens,
        ContextRetentionSettings $settings
    ): bool {
        if (!$settings->isEnabled()) {
            return false;
        }
        $minResidencyTurns = $settings->minResidencyTurns ?? self::DEFAULT_MIN_RESIDENCY_TURNS;
        if ($this->turnsSinceLastUse($currentTurnIndex) < $minResidencyTurns) {
            return false;
        }
        return $this->evictionScore($currentContextInputTokens, $currentTurnIndex, $loadedCapabilityTokens, $settings) >= 1.0;
    }

    /**
     * This capability's eviction telemetry as a flat, JSON-serialisable row — the observability snapshot the CLI /
     * eval suite prints per turn so an eviction can be WATCHED on real data: the static facts (name/type/residentTokens/
     * alwaysLoaded/parents) + the live recency telemetry + the three cost-benefit factors + the resulting score and the
     * SOFT-unload verdict (no pin exemption — every tool unloads on the same rule). Pure — the same methods the sweep reads. `$loadedCapabilityTokens` = the set's total resident
     * tokens (the pressure denominator), passed in by {@see LoadedCapabilities::buildEvictionSnapshot()}.
     *
     * @return array<string, mixed>
     */
    public function toEvictionTelemetry(int $currentContextInputTokens, int $currentTurnIndex, int $loadedCapabilityTokens, ContextRetentionSettings $settings): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'residentTokens' => $this->residentTokens,
            'isAlwaysLoaded' => $this->isAlwaysLoaded,
            'nonEvictable' => $this->nonEvictable,
            'parentSkillNames' => $this->parentSkillNames,
            'usageCountSinceLoad' => $this->usageCountSinceLoad,
            'source' => $this->source,
            'descendantUsageCountSinceLoad' => $this->descendantUsageCountSinceLoad,
            'turnsSinceLastUse' => $this->turnsSinceLastUse($currentTurnIndex),
            'tokenDeltaSinceLastUse' => $this->tokenDeltaSinceLastUse($currentContextInputTokens),
            'staleness' => round($this->staleness($currentContextInputTokens, $currentTurnIndex, $settings), 3),
            'sizeFactor' => round($this->sizeFactor($settings), 3),
            'pressureFactor' => round($this->pressureFactor($loadedCapabilityTokens, $settings), 3),
            'evictionScore' => round($this->evictionScore($currentContextInputTokens, $currentTurnIndex, $loadedCapabilityTokens, $settings), 3),
            'wouldUnloadSoft' => $this->shouldUnload($currentContextInputTokens, $currentTurnIndex, $loadedCapabilityTokens, $settings),
        ];
    }
}
