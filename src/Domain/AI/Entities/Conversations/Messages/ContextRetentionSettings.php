<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Environment\AgentEnvironmentVariables;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Per-conversation OVERRIDE of the SYSTEM-BLOCK footprint knobs
 * — the non-compaction half of context engineering. Compaction ({@see CompactionSettings}) bounds the conversation
 * HISTORY; this bounds the parts compaction cannot reach: the tool RESULTS in the raw tail, and (later) the loaded-skill
 * BODIES + tool SCHEMAS. Every field is nullable: null = inherit the global default. Stored as a JSON column on
 * {@see \DDD\Domain\AI\Entities\Conversations\AIConversation::$contextRetentionSettings} (same mechanism as
 * {@see CompactionSettings}). Sibling, NOT superset, of CompactionSettings.
 *
 * Phase 1 (tool-result clearing) is LIVE: keep tool results FULL only for the last `keepToolResultsRecentTurns` turns
 * AND within `toolResultTokenBudget`; older/over-budget results are stubbed at wire-build time (the stored result is
 * untouched — restorable). The budget is denominated in TOKENS for consistency with the compaction threshold but
 * computed from the STORED {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$toolResultChars}
 * via chars/4 — never the GPT-3 tokenizer (a TOOL_CALL message has no measured token usage; chars are the free metric).
 *
 * Phase 2 (skill + tool footprint — progressive disclosure + USAGE-DRIVEN eviction) holds ONLY the POLICY THRESHOLDS
 * below. The per-skill / per-tool usage TELEMETRY (loaded-since-message, token-delta-since-last-use, usage count/score)
 * is NOT here — it is EVIDENT CODE computed incrementally and stored as STATE on each message's loaded-skill/loaded-tool
 * registry; the MODEL never sees the telemetry. The loop reads the telemetry, applies these thresholds, and unloads a
 * cold TOOL back to "available" (its full schema leaves the canonical `tools` array; its `#[MCPToolSummary]` stays in the
 * `load_tool` catalog); a SKILL whose tools are all unloaded auto-degrades to "available" (its body drops) — the cascade.
 * Fields CONSUMED LATER (not read yet) until Phase 2 ships.
 *
 * @phpstan-consistent-constructor
 */
class ContextRetentionSettings extends ValueObject
{
    /** Unload per SKILL: a skill leaves with all its tools at once, checked once per owner turn (the default). */
    public const string UNLOAD_GRANULARITY_SKILL = 'skill';

    /** Unload per TOOL: the cost-benefit score per tool after every step (the earlier behaviour). */
    public const string UNLOAD_GRANULARITY_TOOL = 'tool';

    /** The config key of the global default (`config/app/AI/contextRetention.php`, `unloadGranularity`). */
    public const string CONFIG_KEY = 'AI.contextRetention';

    /**
     * The environment override of the global default: `AGENT_UNLOAD_GRANULARITY=skill|tool` (non-empty wins over the
     * config; the further name the application declares, {@see AgentEnvironmentVariables::getNamesInReadOrder()}, is read after it).
     */
    public const string ENV_UNLOAD_GRANULARITY = AgentEnvironmentVariables::UNLOAD_GRANULARITY;

    /**
     * The working-context reference the TOKEN knobs were calibrated against: they are scaled by
     * `contextBudgetTokens / reference`. Its own constant on purpose — it used to be the compaction ceiling
     * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationCompactionService::DEFAULT_EFFECTIVE_CONTEXT_TOKENS}),
     * so raising that ceiling would silently have set every 100k purpose's scale factor to 1.
     */
    public const int RETENTION_REFERENCE_CONTEXT_TOKENS = 50000;

    /**
     * A skill load changes the system content and the top-level `tools` array (today's behaviour; the
     * cold-capability unloading applies).
     */
    public const string SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP = 'systemPromptSwap';

    /**
     * The system content up to the loaded skills and the top-level `tools` are fixed for a prefix epoch;
     * a skill loaded inside the epoch is an appended `SKILLS_LOADED` signal row (its instructions as a developer message,
     * its tools as an `additional_tools` item). Responses API only; nothing is unloaded inside an epoch.
     */
    public const string SKILL_LOADING_STRATEGY_CONVERSATION_APPEND = 'conversationAppend';

    /** @var string[] The skill-loading strategies. */
    public const array SKILL_LOADING_STRATEGIES = [self::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP, self::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND];

    /** Every volatile value is rendered on every model call (the system content, the base prompt, the tail). */
    public const string STATE_CHANGE_DELIVERY_EVERY_CALL = 'everyCall';

    /**
     * The volatile values are fixed per state epoch (the epoch record renders the system content and the
     * base-prompt placeholders) and every change reaches the model ONCE, as a `[SIGNAL:<kind>]` note in the history.
     */
    public const string STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED = 'onceWhenChanged';

    /** @var string[] The state-change deliveries. */
    public const array STATE_CHANGE_DELIVERIES = [self::STATE_CHANGE_DELIVERY_EVERY_CALL, self::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED];

    /** @var bool|null Force the whole footprint layer on/off for THIS conversation (null = on, the default). */
    public ?bool $enabled = null;

    /**
     * @var string|null The unloading unit for THIS conversation: {@see UNLOAD_GRANULARITY_SKILL} (a skill
     * leaves with all its tools at once, checked once per owner turn) or {@see UNLOAD_GRANULARITY_TOOL} (the per-tool
     * score after every step). Null = the global default ({@see resolveDefaultUnloadGranularity()}: the config file,
     * overridden per environment by `AGENT_UNLOAD_GRANULARITY`). A purpose sets it in its `contextRetention:` map; the
     * admin run-settings panel and the impersonation panel set it per conversation; the CLI via `--unload-granularity`.
     */
    public ?string $unloadGranularity = null;

    /**
     * @var string|null How a skill loaded during this conversation reaches the model:
     * {@see SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP} or {@see SKILL_LOADING_STRATEGY_CONVERSATION_APPEND}. STAMPED at the
     * conversation's creation with the resolved value (conversation request → purpose `contextRetention:` map → the
     * global default in `config/app/AI/contextRetention.php`; NO environment variable) and never changed once the
     * conversation made a model call — the strategy shapes the stored history. Null on a conversation created before
     * the field existed = the global default.
     */
    public ?string $skillLoadingStrategy = null;

    /**
     * @var bool|null Under {@see SKILL_LOADING_STRATEGY_CONVERSATION_APPEND} (opt-in): true = the
     * system content carries the loaded skills and the purpose directive WITH the sections of the current situation, and
     * a situation change (a variant dimension such as `businessClass`, `[surface=…]` — whatever replaces a
     * `# Situation` entry under the default behaviour) starts a new prefix epoch instead of appending a replacing
     * entry: the new epoch's
     * system content carries every loaded skill with the new sections, the top-level `tools` stay, the skill texts and
     * entries the earlier rows appended are no longer rendered. Costs one full cache miss after the base prompt per
     * change. False = the default behaviour (appended `# Situation` entries). STAMPED at the conversation's creation like
     * {@see $skillLoadingStrategy} (conversation request → purpose `contextRetention:` map → the global default in
     * `config/app/AI/contextRetention.php`, false; no environment variable) and frozen once the conversation made a
     * model call. Null = the global default. Inert under {@see SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP}.
     */
    public ?bool $rebuildEpochOnSituationChange = null;

    /**
     * @var string|null How a change of a volatile value (the context text of the space, the owner addressing, the zone, the
     * standing instructions, the product blocks, the situation note, the task plan) reaches the model:
     * {@see STATE_CHANGE_DELIVERY_EVERY_CALL} (re-rendered on every call) or {@see STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED}
     * (fixed per state epoch, a change delivered once as a note). Works under both skill-loading strategies. STAMPED at
     * the conversation's creation like {@see $skillLoadingStrategy} (conversation request → purpose `contextRetention:`
     * map → the global default in `config/app/AI/contextRetention.php`; no environment variable), frozen once the
     * conversation made a model call, inherited by its sub-agents. Null = the global default.
     */
    public ?string $stateChangeDelivery = null;

    // --- Phase 1 — tool-result clearing (LIVE) ---

    /**
     * @var int|null Keep tool RESULTS verbatim only for the last K TURNS (a turn = a user-initiator message); older
     * results are stubbed. The hard ceiling — anchored to turns, not raw messages, so a multi-step turn stays
     * cache-stable and the boundary advances batched (once per turn). Null = the global default.
     */
    public ?int $keepToolResultsRecentTurns = null;

    /**
     * @var int|null Within the turn window, keep the newest tool results verbatim until their cumulative size reaches
     * this budget IN TOKENS (computed as Σ `toolResultChars` / 4 — never the tokenizer); older results are stubbed. The
     * tightener that bounds a few huge recent results. Null = the global default.
     */
    public ?int $toolResultTokenBudget = null;

    /**
     * @var int|null The hard ceiling on how many tool-call IMAGES ride on ONE model call's wire: the loop
     * keeps the newest image-bearing tool results whole until their pictures reach N, so a multi-step browser turn does
     * not stack every viewport it ever captured (≈1.1k tokens each). The unit is PICTURES, not results (a result with
     * three pictures uses three) — a cap that counted results would let 2 results carry 8 images.
     * Default {@see DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE} = 2 — previous + current viewport, the pair that shows a
     * transition. A purpose whose tools serve durable catalog pictures raises it in its frontmatter.
     * Null = the default.
     */
    public ?int $maxToolCallImagesOnWire = null;

    // --- Phase 2 — skill + tool footprint: progressive disclosure + usage-driven eviction (CONSUMED LATER) ---

    /**
     * @var bool|null Loading mode for a skill's tools. `true` = EAGER: all of the skill's tool schemas enter the
     * canonical `tools` array on skill-load (no extra hop, but unused tools sit resident). `false` = LAZY: a skill-load
     * loads only the BODY; each tool loads on first use (`load_tool`) — the footprint-lean mode, no never-used tool
     * resident. A specific tool can FORCE eager-load via `#[MCPToolAlwaysLoad]`. The global default is undecided
     * (no production yet) — the simulator (a console command of the application) drives both modes off this field.
     */
    public ?bool $loadToolsOnSkillLoad = null;

    // --- Recency horizons: BOTH axes, per type. An item ages out when stale on EITHER axis (the `max` of the two
    //     normalised ratios in the cost-benefit score) — TOKEN-displacement (a pasted page displaces it fast) OR
    //     TURN-staleness (many small turns on a new topic). `usageCountSinceLoad` stretches both (a frequent item
    //     clings). See {@see LoadedCapability::staleness()}. 0 on an axis = that axis off.

    /** @var int|null Tool: token-displacement horizon — base tokens since last use before it ages out. Null = default. */
    public ?int $unloadToolAfterUnusedTokens = null;

    /** @var int|null Tool: turn-staleness horizon — base turns since last use before it ages out. Null = default. */
    public ?int $unloadToolAfterUnusedTurns = null;

    /** @var int|null Skill BODY: token-displacement horizon (skills are big → a smaller base evicts them sooner). Null = default. */
    public ?int $unloadSkillAfterUnusedTokens = null;

    /** @var int|null Skill BODY: turn-staleness horizon — also the BACKSTOP beyond the cascade (a skill degrades once it has no loaded tools). Null = default. */
    public ?int $unloadSkillAfterUnusedTurns = null;

    // --- Cost-benefit knobs (eviction = `staleness × sizeFactor × pressureFactor ≥ 1`, NOT a watermark) ---

    /** @var int|null `sizeFactor = residentTokens / referenceResidentTokens` — a bigger item ages out at lower staleness (costs more to keep). Null = default. */
    public ?int $referenceResidentTokens = null;

    /** @var int|null SOFT target: `pressureFactor = loadedCapabilityTokens / pressureBudgetTokens` — total resident skill+tool cost (NO messages); higher load lowers every item's bar smoothly (mobile-OS "moderate memory pressure" — gradual background reclaim, never drains a fresh item). Null = default. */
    public ?int $pressureBudgetTokens = null;

    /** @var int|null HARD ceiling on the carried skill+tool token load — when the loaded set would exceed it, the lowest-priority eligible items are force-evicted until under (mobile-OS "critical memory" / jetsam). Only within-min-residency items are exempt (there is NO pin — every tool is evicted by the same rule), so the working set itself can briefly sit above it. Null = default. */
    public ?int $hardCapTokens = null;

    /** @var int|null Cold-start grace in TURNS — never unload an item used/loaded within this many turns (a just-loaded tool survives a same-turn paste so the model can still call it). Null = default. */
    public ?int $minResidencyTurns = null;

    /**
     * @var bool|null When true, the agent gets an in-context hint listing the conversation's recent read tool-calls
     * (`getX(args)`) with a "reuse these, do not call them again" instruction, to discourage repeating the same fetch
     * (and varied-arg over-exploration the byte-exact dedup can't see). The hint is emitted as a USER message at the
     * TAIL of the wire (out of the cacheable system prefix, so it never invalidates the prompt cache); a write since the
     * reads clears the list. Null/false = DEFAULT OFF — no hint is emitted at all. Per-conversation A/B knob (its token
     * cost vs. its effect on redundant reads is unmeasured).
     */
    public ?bool $hintRecentToolCallsToDiscourageRepeats = null;

    // --- Debug capture (admin/debug only — default OFF) ---

    /**
     * @var bool|null DEBUG: when true, every model call of this conversation stores its FULL request payload (system
     * content + tools + tool_choice + the wire messages) AND the raw model response on the per-message
     * {@see RequestSnapshot} — the data the slim snapshot drops for O(n²) reasons. Null/false = slim (production
     * default). Turn it on for ONE conversation to debug what was actually sent/received (e.g. whether
     * `tool_choice:"required"` reached the model and what it returned). The capture is stamped on the assistant turn
     * AND on the loop's empty-turn nudge, so even a stall (no assistant message) is inspectable.
     */
    public ?bool $storeRequestSnapshot = null;

    /** Whether the footprint layer is enabled for this conversation (default true when unset). */
    public function isEnabled(): bool
    {
        return $this->enabled ?? true;
    }

    /** The unloading unit in effect for this conversation: the per-conversation value, else the global default. */
    public function getUnloadGranularity(): string
    {
        return self::canonicalUnloadGranularity($this->unloadGranularity) ?? self::resolveDefaultUnloadGranularity();
    }

    /** Whether this conversation unloads per SKILL — the default. */
    public function unloadsPerSkill(): bool
    {
        return $this->getUnloadGranularity() === self::UNLOAD_GRANULARITY_SKILL;
    }

    /** `skill` / `tool` (any case, surrounding blanks) → the constant; anything else → null (= inherit). */
    public static function canonicalUnloadGranularity(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, [self::UNLOAD_GRANULARITY_SKILL, self::UNLOAD_GRANULARITY_TOOL], true) ? $normalized : null;
    }

    /**
     * The GLOBAL default of the unloading unit: a non-empty `AGENT_UNLOAD_GRANULARITY` environment value
     * (then the further name the application declares; read raw first — `$_SERVER`, `getenv()` — then through {@see Config::getEnv()}) wins over the config file
     * (`AI.contextRetention.unloadGranularity`); an unreadable or unknown value falls back to `skill`. Never throws:
     * a container-less test resolves to the built-in default.
     */
    public static function resolveDefaultUnloadGranularity(): string
    {
        $rawValue = AgentEnvironmentVariables::readRawProcessValue(self::ENV_UNLOAD_GRANULARITY);
        foreach (AgentEnvironmentVariables::getNamesInReadOrder(self::ENV_UNLOAD_GRANULARITY) as $environmentVariableName) {
            if ($rawValue !== null && $rawValue !== '') {
                break;
            }
            try {
                $rawValue = Config::getEnv($environmentVariableName);
            } catch (Throwable) {
                $rawValue = null;
            }
        }
        $fromEnvironment = self::canonicalUnloadGranularity(is_string($rawValue) ? $rawValue : null);
        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }
        try {
            $configured = Config::get(self::CONFIG_KEY);
            $fromConfig = is_array($configured) ? self::canonicalUnloadGranularity(is_string($configured['unloadGranularity'] ?? null) ? $configured['unloadGranularity'] : null) : null;
            if ($fromConfig !== null) {
                return $fromConfig;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return self::UNLOAD_GRANULARITY_SKILL;
    }

    /** The skill-loading strategy in effect for this conversation: the stamped value, else the global default. */
    public function getSkillLoadingStrategy(): string
    {
        return self::canonicalSkillLoadingStrategy($this->skillLoadingStrategy) ?? self::resolveDefaultSkillLoadingStrategy();
    }

    /** Whether this conversation loads skills append-only. */
    public function usesConversationAppend(): bool
    {
        return $this->getSkillLoadingStrategy() === self::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
    }

    /** Whether a situation change starts a new prefix epoch: the stamped value, else the global default. */
    public function rebuildsEpochOnSituationChange(): bool
    {
        return $this->rebuildEpochOnSituationChange ?? self::resolveDefaultRebuildEpochOnSituationChange();
    }

    /**
     * The GLOBAL default of {@see $rebuildEpochOnSituationChange}: `AI.contextRetention.rebuildEpochOnSituationChange`
     * from the committed config only (no environment variable); absent or not a bool = false. Never throws.
     */
    public static function resolveDefaultRebuildEpochOnSituationChange(): bool
    {
        try {
            $configured = Config::get(self::CONFIG_KEY);
            $configuredValue = is_array($configured) ? ($configured['rebuildEpochOnSituationChange'] ?? null) : null;
            if (is_bool($configuredValue)) {
                return $configuredValue;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return false;
    }

    /** The state-change delivery in effect for this conversation: the stamped value, else the global default. */
    public function getStateChangeDelivery(): string
    {
        return self::canonicalStateChangeDelivery($this->stateChangeDelivery) ?? self::resolveDefaultStateChangeDelivery();
    }

    /** Whether this conversation delivers a volatile value's change once, as a note. */
    public function deliversStateChangesOnce(): bool
    {
        return $this->getStateChangeDelivery() === self::STATE_CHANGE_DELIVERY_ONCE_WHEN_CHANGED;
    }

    /** `everyCall` / `onceWhenChanged` (any case, surrounding blanks) → the constant; anything else → null (= inherit). */
    public static function canonicalStateChangeDelivery(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        foreach (self::STATE_CHANGE_DELIVERIES as $stateChangeDelivery) {
            if ($normalized === strtolower($stateChangeDelivery)) {
                return $stateChangeDelivery;
            }
        }
        return null;
    }

    /**
     * The GLOBAL default of {@see $stateChangeDelivery}: `AI.contextRetention.stateChangeDelivery` from the committed
     * config only (no environment variable); absent or unknown = {@see STATE_CHANGE_DELIVERY_EVERY_CALL}. Never throws.
     */
    public static function resolveDefaultStateChangeDelivery(): string
    {
        try {
            $configured = Config::get(self::CONFIG_KEY);
            $fromConfig = is_array($configured) ? self::canonicalStateChangeDelivery(is_string($configured['stateChangeDelivery'] ?? null) ? $configured['stateChangeDelivery'] : null) : null;
            if ($fromConfig !== null) {
                return $fromConfig;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return self::STATE_CHANGE_DELIVERY_EVERY_CALL;
    }

    /** `systemPromptSwap` / `conversationAppend` (any case, surrounding blanks) → the constant; anything else → null (= inherit). */
    public static function canonicalSkillLoadingStrategy(?string $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        foreach (self::SKILL_LOADING_STRATEGIES as $skillLoadingStrategy) {
            if ($normalized === strtolower($skillLoadingStrategy)) {
                return $skillLoadingStrategy;
            }
        }
        return null;
    }

    /**
     * The GLOBAL default skill-loading strategy: `AI.contextRetention.skillLoadingStrategy` from the
     * committed config only — no environment variable (no switches in `.env`); an absent or unknown value =
     * {@see SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP}. Never throws: a container-less test resolves to the built-in default.
     */
    public static function resolveDefaultSkillLoadingStrategy(): string
    {
        try {
            $configured = Config::get(self::CONFIG_KEY);
            $fromConfig = is_array($configured) ? self::canonicalSkillLoadingStrategy(is_string($configured['skillLoadingStrategy'] ?? null) ? $configured['skillLoadingStrategy'] : null) : null;
            if ($fromConfig !== null) {
                return $fromConfig;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return self::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
    }

    /**
     * The skill-loading strategy a conversation takes from the GLOBAL default when it runs on `$agentWireApi` (the wire
     * API its model resolves to, {@see \DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration::resolveWireApiFor()};
     * null = not known, the plain global default): {@see resolveDefaultSkillLoadingStrategy()}, except that a global
     * {@see SKILL_LOADING_STRATEGY_CONVERSATION_APPEND} on the Chat Completions wire becomes
     * {@see SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP} — append-only loading needs the Responses API, and a default must
     * never make a model unusable. Only the default is adapted: a strategy a purpose, an admin start, a CLI option or a
     * stored stamp names is kept, and the creation refuses an append-only request on Chat Completions as before.
     * {@see $rebuildEpochOnSituationChange} and {@see $stateChangeDelivery} keep their defaults on this path: the epoch
     * rebuild is read only under append-only loading (inert under the swap) and `onceWhenChanged` runs under both
     * strategies (refused only on the server-state arm, which is never a catalog wire API). Pure; never throws.
     */
    public static function resolveDefaultSkillLoadingStrategyForAgentWireApi(?string $agentWireApi): string
    {
        $defaultSkillLoadingStrategy = self::resolveDefaultSkillLoadingStrategy();
        if ($defaultSkillLoadingStrategy === self::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND
            && $agentWireApi !== null
            && !RunSettings::isResponsesAgentWireApi($agentWireApi)
        ) {
            return self::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
        }
        return $defaultSkillLoadingStrategy;
    }

    /** The ceiling of the reachable tool set an append-only epoch offers top-level when the config names none. */
    public const int DEFAULT_APPEND_ONLY_REACHABLE_TOOL_SET_CEILING_TOKENS = 55000;

    /**
     * The estimated tokens (chars/4 of the JSON schemas) up to which an append-only conversation offers its purpose's
     * whole reachable domain tool set as the top-level `tools` from the epoch start:
     * `AI.contextRetention.appendOnlyReachableToolSetCeilingTokens` from the committed config, else
     * {@see DEFAULT_APPEND_ONLY_REACHABLE_TOOL_SET_CEILING_TOKENS}. No environment variable. Never throws.
     */
    public static function resolveAppendOnlyReachableToolSetCeilingTokens(): int
    {
        try {
            $configured = Config::get(self::CONFIG_KEY);
            $configuredCeilingTokens = is_array($configured) ? ($configured['appendOnlyReachableToolSetCeilingTokens'] ?? null) : null;
            if (is_int($configuredCeilingTokens) && $configuredCeilingTokens >= 0) {
                return $configuredCeilingTokens;
            }
        } catch (Throwable) {
            // no config file — the built-in default
        }
        return self::DEFAULT_APPEND_ONLY_REACHABLE_TOOL_SET_CEILING_TOKENS;
    }

    /**
     * The settings a NEW conversation is stamped with: `$requestedSettings` (an admin start, a purpose's
     * `contextRetention:` map; null = none) carrying the resolved strategy — `$requestedSkillLoadingStrategy` (the run
     * settings' creation request, the eval arm) first, then the strategy the settings name, then the global default.
     * A conversation created without settings gets fresh settings carrying only the strategy. The same order stamps
     * {@see $rebuildEpochOnSituationChange} (`$requestedRebuildEpochOnSituationChange`, then the settings' value, then the
     * global default), and {@see $stateChangeDelivery} (`$requestedStateChangeDelivery`, the settings' value, the global
     * default). `$agentWireApi` is the wire API the new conversation's model resolves to (null = not known): the global
     * default strategy is taken through {@see resolveDefaultSkillLoadingStrategyForAgentWireApi()}, so a Chat Completions
     * model starts on the system-prompt swap. Pure; the argument is not changed.
     */
    public static function buildForNewConversation(
        ?self $requestedSettings,
        ?string $requestedSkillLoadingStrategy,
        ?bool $requestedRebuildEpochOnSituationChange = null,
        ?string $requestedStateChangeDelivery = null,
        ?string $agentWireApi = null
    ): self {
        $settingsForNewConversation = $requestedSettings !== null ? clone $requestedSettings : new static();
        $settingsForNewConversation->skillLoadingStrategy = self::canonicalSkillLoadingStrategy($requestedSkillLoadingStrategy)
            ?? self::canonicalSkillLoadingStrategy($settingsForNewConversation->skillLoadingStrategy)
            ?? self::resolveDefaultSkillLoadingStrategyForAgentWireApi($agentWireApi);
        $settingsForNewConversation->rebuildEpochOnSituationChange = $requestedRebuildEpochOnSituationChange
            ?? $settingsForNewConversation->rebuildEpochOnSituationChange
            ?? self::resolveDefaultRebuildEpochOnSituationChange();
        $settingsForNewConversation->stateChangeDelivery = self::canonicalStateChangeDelivery($requestedStateChangeDelivery)
            ?? self::canonicalStateChangeDelivery($settingsForNewConversation->stateChangeDelivery)
            ?? self::resolveDefaultStateChangeDelivery();
        return $settingsForNewConversation;
    }

    /**
     * Whether these settings carry nothing but the creation stamps of the skill-loading strategy, of
     * {@see $rebuildEpochOnSituationChange} and of {@see $stateChangeDelivery} ({@see buildForNewConversation()} without requested settings) — a purpose's
     * knobs may still be applied over them.
     */
    public function isOnlyTheSkillLoadingStrategyStamp(): bool
    {
        foreach ((new ReflectionClass(self::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $knobProperty) {
            if ($knobProperty->isStatic() || $knobProperty->getDeclaringClass()->getName() !== self::class || in_array($knobProperty->getName(), ['skillLoadingStrategy', 'rebuildEpochOnSituationChange', 'stateChangeDelivery'], true)) {
                continue;
            }
            if ($knobProperty->isInitialized($this) && $knobProperty->getValue($this) !== null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether these settings name any of the three choices a conversation is stamped with at its creation — the
     * {@see $skillLoadingStrategy}, {@see $rebuildEpochOnSituationChange} or {@see $stateChangeDelivery} (null = inherit).
     */
    public function namesSkillLoadingOrStateChangeDeliveryChoice(): bool
    {
        return $this->skillLoadingStrategy !== null || $this->rebuildEpochOnSituationChange !== null || $this->stateChangeDelivery !== null;
    }

    /**
     * A copy of these settings without the three creation choices ({@see $skillLoadingStrategy},
     * {@see $rebuildEpochOnSituationChange}, {@see $stateChangeDelivery} back to null = the global defaults); every other
     * knob is kept. The retention settings a conversation gets when its purpose's agent wire profile does not apply
     * ({@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::buildContextRetentionSettingsForConversationWithoutAgentWireProfile()}).
     * Pure; `$this` is unchanged.
     */
    public function withoutSkillLoadingAndStateChangeDeliveryChoices(): self
    {
        $settingsWithoutChoices = clone $this;
        $settingsWithoutChoices->skillLoadingStrategy = null;
        $settingsWithoutChoices->rebuildEpochOnSituationChange = null;
        $settingsWithoutChoices->stateChangeDelivery = null;
        return $settingsWithoutChoices;
    }

    /** Whether to store the full request/response debug snapshot for this conversation (default false). */
    public function shouldStoreRequestSnapshot(): bool
    {
        return $this->storeRequestSnapshot ?? false;
    }

    /** Whether to emit the recent-tool-calls reuse hint at the wire tail for this conversation (default false). */
    public function shouldHintRecentToolCalls(): bool
    {
        return $this->hintRecentToolCallsToDiscourageRepeats ?? false;
    }

    /**
     * A copy of these settings whose TOKEN-denominated knobs are scaled to a purpose's context budget: the
     * eviction horizons ({@see $unloadToolAfterUnusedTokens}, {@see $unloadSkillAfterUnusedTokens}), the pressure
     * target ({@see $pressureBudgetTokens}) and the hard ceiling ({@see $hardCapTokens}) were all tuned against a
     * working context of `$referenceContextBudgetTokens`
     * (the 50k compaction ceiling). A purpose that raises its working context to `$contextBudgetTokens` gets every
     * still-UNSET knob filled with `default × (budget / reference)`, so the capability layer grows with the history
     * layer instead of evicting the build's skills at the old 5k-token horizon while the context now runs to 100k.
     * A knob the purpose set explicitly in its `contextRetention:` map is never touched. A budget at or below the
     * reference leaves the copy identical (the defaults are the floor they were measured at; a smaller window keeps
     * them). The turn-denominated knobs are untouched — a turn is a turn at any budget. The tool-result budget
     * ({@see $toolResultTokenBudget}) is NOT scaled: a purpose runs on the global default
     * ({@see AIConversation::CONTEXT_RETENTION_DEFAULT_TOOL_RESULT_TOKEN_BUDGET}) unless its `contextRetention:` map
     * pins its own value. Pure; `$this` is unchanged.
     */
    public function withTokenKnobsScaledToContextBudget(int $contextBudgetTokens, int $referenceContextBudgetTokens): self
    {
        $scaledSettings = clone $this;
        if ($contextBudgetTokens <= 0 || $referenceContextBudgetTokens <= 0 || $contextBudgetTokens <= $referenceContextBudgetTokens) {
            return $scaledSettings;
        }
        $scaleFactor = $contextBudgetTokens / $referenceContextBudgetTokens;
        $scaledSettings->unloadToolAfterUnusedTokens ??= (int)round(LoadedCapability::DEFAULT_UNLOAD_TOOL_AFTER_UNUSED_TOKENS * $scaleFactor);
        $scaledSettings->unloadSkillAfterUnusedTokens ??= (int)round(LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TOKENS * $scaleFactor);
        $scaledSettings->pressureBudgetTokens ??= (int)round(LoadedCapability::DEFAULT_PRESSURE_BUDGET_TOKENS * $scaleFactor);
        $scaledSettings->hardCapTokens ??= (int)round(LoadedCapability::DEFAULT_HARD_CAP_TOKENS * $scaleFactor);
        return $scaledSettings;
    }

    /** @var int The wire image cap (in PICTURES) when {@see $maxToolCallImagesOnWire} is unset — previous + current viewport. */
    public const int DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE = 2;

    /** How many tool-call images may ride on one model call's wire for this conversation (default {@see DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE}). */
    public function getMaxToolCallImagesOnWire(): int
    {
        return $this->maxToolCallImagesOnWire ?? self::DEFAULT_MAX_TOOL_CALL_IMAGES_ON_WIRE;
    }
}
