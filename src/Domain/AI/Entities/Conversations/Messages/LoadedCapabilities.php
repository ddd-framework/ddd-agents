<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The capabilities currently LOADED (resident in the model prompt) on a {@see AIConversationMessage} — ONE uniform
 * ObjectSet of {@see LoadedCapability}, holding BOTH skill BODIES (type SKILL) and tool SCHEMAS (type TOOL). This
 * replaces the old asymmetric `LoadedSkills` (name-only fulltext) + `LoadedTools` (rich JSON) pair: a skill body and a
 * tool schema are the SAME concept under the eviction model (resident cost, recency, pin, the cost-benefit score / the
 * cascade), so they share one item type, one set, one column, one carry-forward.
 * Persists as one JSON TEXT column ({@see mapToRepository()}).
 *
 * EAGER vs LAZY differ ONLY in the initial population at `load_skills`: EAGER loads the skill body + ALL its tool
 * schemas ({@see withSkillsPromoted()} with `$eager=true`); LAZY loads only the body, each tool promoted on first
 * `load_tool` ({@see withToolsPromoted()}). After that the eviction governs both identically — there is no per-mode logic.
 *
 * Owns the per-turn EVICTION SWEEP ({@see computeEviction()}) over `$this` alone (skill bodies + tool schemas):
 *   - TOOL — SOFT: a tool whose cost-benefit score ≥ 1 unloads (back to `available`).
 *   - SKILL — CASCADE (not score-driven): a skill body degrades once NO loaded capability (tool OR sub-skill) lists its
 *     name in `parentSkillNames` — generalised to a DAG (multi-parent tools + sub-skills). Its OWN `turnIndexAtLastUse`
 *     (stamped at load, never bumped — a skill is not "called") is the cold-start-grace anchor.
 *   - HARD — CEILING/jetsam: while the carried load exceeds `hardCapTokens`, force-evict the lowest-priority eligible
 *     tool until under. Only within-min-residency tools are exempt — there is NO pin; eviction hits every tool the same.
 * The math is validated by an application's eviction simulator; this is the same logic on the real VO.
 *
 * @property LoadedCapability[] $elements
 * @method LoadedCapability[] getElements()
 * @method LoadedCapability|null first()
 * @phpstan-consistent-constructor
 */
class LoadedCapabilities extends ObjectSet
{
    /** The resolved skills of the loaded SKILL items, remembered for the resolver ({@see getRememberedLoadedSkills()}). */
    protected ?AISkills $rememberedLoadedSkills = null;

    /**
     * @var array<string, string> Why capabilities left this set since the last persist — name → the eviction turn-event
     *      vocabulary (`sweep`, `skillUnloading`, `speculative`). Never persisted on the set itself: the change encoder
     *      moves the reasons into the `unload` events of the row it writes.
     */
    protected array $unloadReasons = [];

    /** @var int The version marker of a stored change record: `{"v":2,"e":[…]}`. */
    public const int CHANGE_RECORD_VERSION = 2;

    /**
     * Set only on the STORED form of a change row: this object then carries no capabilities, only the
     * changes against the row before. A loaded change row keeps it until its set is first read — readers go through
     * {@see AIConversationMessage::getLoadedCapabilities()}, which builds the set from the row's persisted state.
     */
    protected ?LoadedCapabilityChanges $changeRecord = null;

    /** The stored form of a change row: `$changes` against the row before, no capabilities of its own. */
    public static function asChangeRecord(LoadedCapabilityChanges $changes): static
    {
        $changeRecord = new static();
        $changeRecord->changeRecord = $changes;
        return $changeRecord;
    }

    /** The changes this object stores when it is a change record ({@see asChangeRecord()}), else null. */
    public function getChangeRecord(): ?LoadedCapabilityChanges
    {
        return $this->changeRecord;
    }

    /** Whether this object is a stored change record — NOT a set: it must be resolved against the row before. */
    public function isChangeRecord(): bool
    {
        return $this->changeRecord !== null;
    }

    /**
     * A NEW set (copy-on-write): this set's capabilities carried forward PLUS the requested SKILLS as
     * loaded skill BODIES; in EAGER mode also ALL their tool schemas. Telemetry is carried forward by name; a newly-loaded
     * capability is stamped from `$origin` (the load baseline and, with a source, the provenance —
     * {@see LoadedCapabilityOrigin}). `$eager` = {@see ContextRetentionSettings::$loadToolsOnSkillLoad} (default true).
     * The skills come RESOLVED (`$requestedAISkills` — the requested AND this set's loaded names; an unknown name is
     * skipped) and their tools come as `$loadedSkillToolDefinitions`, already filtered by the agent space's feature flags
     * (a locked tool must never become a loaded capability — everything downstream that NAMES the loaded tools to
     * the model reads this set). Resolves nothing itself. This set is never changed.
     */
    public function withSkillsPromoted(RequestedAISkills $requestedAISkills, LoadedSkillToolDefinitions $loadedSkillToolDefinitions, bool $eager, LoadedCapabilityOrigin $origin): self
    {
        $capabilities = $this->buildCarriedCopy();
        $existing = $capabilities->getNameSet();
        /** @var LoadedCapability[] $existingByName */
        $existingByName = [];
        /** @var LoadedCapability $carriedCapability */
        foreach ($capabilities->getElements() as $carriedCapability) {
            $existingByName[$carriedCapability->name] = $carriedCapability;
        }

        foreach ($requestedAISkills->requestedSkillNames as $skillName) {
            if ($skillName === '') {
                continue;
            }
            $skill = $requestedAISkills->getSkill($skillName);
            if ($skill === null) {
                continue;
            }
            if (!isset($existing[$skillName])) {
                // The DAG half for SUB-SKILLS: a loaded sub-skill lists the loaded
                // ROOT(s) whose `subSkillNames` carry it — the root's body then counts it as a loaded child and does
                // not cascade-degrade away while its act sub-skills are still resident. A general skill has no parents.
                $skillCapability = LoadedCapability::fromSkillBody(
                    $skill,
                    $skill->isSubSkill ? self::loadedRootNamesListing($skillName, $existingByName, $requestedAISkills) : [],
                    $origin
                );
                $capabilities->add($skillCapability);
                $existing[$skillName] = true;
                $existingByName[$skillName] = $skillCapability;
            }
            // EAGER → load ALL the skill's tools now; LAZY → ALSO auto-load the #[MCPToolAlwaysLoad] tools (provisioned
            // even in lazy mode so the model never spends a load_tool hop to first reach them), the rest stay "available"
            // via the load_tool catalog. Always-load only changes the INITIAL provisioning — once loaded, every tool is
            // evicted by the same rule (no pin). loadedCapabilities stays the single source of truth in both modes.
            foreach ($loadedSkillToolDefinitions->getToolDefinitionsOf($skillName)->getElements() as $toolDefinition) {
                /** @var LoadedCapability|null $residentToolCapability */
                $residentToolCapability = $existingByName[$toolDefinition->name] ?? null;
                if ($residentToolCapability !== null) {
                    // MULTI-ANCHOR: the tool is already resident via another skill —
                    // record THIS skill as an additional parent instead of skipping silently. Schema tokens stay
                    // counted ONCE; the childless cascade now sees BOTH skills as parents, so neither body degrades
                    // while the shared tool is resident, and the tool's accounting survives either skill's eviction.
                    if ($residentToolCapability->type === LoadedCapability::TYPE_TOOL
                        && !in_array($skillName, $residentToolCapability->parentSkillNames, true)) {
                        // COPY-ON-WRITE: the resident instance is shared with every earlier message's
                        // carried set — replace it AT THE SAME INDEX by a copy carrying the added parent, never
                        // mutate it (the in-place append rewrote persisted history and broke the byte-identical
                        // gate on earlier messages).
                        $extendedToolCapability = $residentToolCapability->withAddedParentSkillName($skillName);
                        $capabilities->replace($extendedToolCapability);
                        $existingByName[$toolDefinition->name] = $extendedToolCapability;
                    }
                    continue;
                }
                if (!$eager && !$toolDefinition->isAlwaysLoad) {
                    continue;
                }
                // ObjectSet::add() takes its elements BY REFERENCE — pass a variable, never a method return directly
                // ("Only variables should be passed by reference"). Mirrors the test helper's foreach-variable note.
                $toolCapability = LoadedCapability::fromToolDefinition($toolDefinition, $skillName, $origin);
                $capabilities->add($toolCapability);
                $existing[$toolDefinition->name] = true;
                $existingByName[$toolDefinition->name] = $toolCapability;
            }
        }
        return $capabilities;
    }

    /**
     * A NEW set (copy-on-write): LAZY promotion `available → loaded` of the named TOOLS (a `load_tool` call), each from
     * the loaded skills' definitions (`$loadedSkillToolDefinitions`, in loaded-skill order, flag-filtered — a locked tool
     * cannot be promoted through a hallucinated `load_tool` call either). Carries prior telemetry; an unknown or
     * already-loaded name is skipped. A tool promotion records no provenance — `$origin`'s source is ignored here.
     *
     * @param string[] $toolNames
     */
    public function withToolsPromoted(LoadedSkillToolDefinitions $loadedSkillToolDefinitions, array $toolNames, LoadedCapabilityOrigin $origin): self
    {
        $origin = $origin->withoutSource();
        $capabilities = $this->buildCarriedCopy();
        $existing = $capabilities->getNameSet();

        foreach ($toolNames as $toolName) {
            if ($toolName === '' || isset($existing[$toolName])) {
                continue;
            }
            // EVERY loaded skill whose package carries the tool is recorded as a parent (the former
            // first-match `break` recorded one, and a shared tool then left with that one parent's group).
            $toolCapability = null;
            foreach ($loadedSkillToolDefinitions->getSkillNames() as $skillName) {
                foreach ($loadedSkillToolDefinitions->getToolDefinitionsOf($skillName)->getElements() as $toolDefinition) {
                    if ($toolDefinition->name !== $toolName) {
                        continue;
                    }
                    if ($toolCapability === null) {
                        $toolCapability = LoadedCapability::fromToolDefinition($toolDefinition, $skillName, $origin);
                    } elseif (!in_array($skillName, $toolCapability->parentSkillNames, true)) {
                        $toolCapability->parentSkillNames[] = $skillName;
                    }
                    break;
                }
            }
            if ($toolCapability !== null) {
                // ObjectSet::add() is by-reference — a variable, never a method return.
                $capabilities->add($toolCapability);
                $existing[$toolName] = true;
            }
        }
        return $capabilities;
    }

    /**
     * The names of the loaded ROOT skill capabilities whose registered `subSkillNames` list the given sub-skill — the
     * parents a freshly-loaded sub-skill body records ({@see withSkillsPromoted()}); each root read from the resolved
     * request (it holds every requested and loaded name).
     *
     * @param LoadedCapability[] $existingByName
     * @return string[]
     */
    protected static function loadedRootNamesListing(string $subSkillName, array $existingByName, RequestedAISkills $requestedAISkills): array
    {
        $loadedRootNames = [];
        foreach ($existingByName as $loadedCapability) {
            if ($loadedCapability->type !== LoadedCapability::TYPE_SKILL) {
                continue;
            }
            $loadedRootSkill = $requestedAISkills->getSkill($loadedCapability->name);
            if ($loadedRootSkill !== null && in_array($subSkillName, $loadedRootSkill->subSkillNames, true)) {
                $loadedRootNames[] = $loadedCapability->name;
            }
        }
        return $loadedRootNames;
    }

    /** A fresh set carrying all of this set's items — the same instances, telemetry preserved (copy-on-write base). */
    protected function buildCarriedCopy(): static
    {
        $capabilities = new static();
        /** @var LoadedCapability $capability */
        foreach ($this->getElements() as $capability) {
            $capabilities->add($capability);
        }
        return $capabilities;
    }

    /** @return array<string, true> name → true for this set's items (membership guard). */
    protected function getNameSet(): array
    {
        $names = [];
        foreach ($this->getElements() as $capability) {
            $names[$capability->name] = true;
        }
        return $names;
    }

    /** @return string[] the loaded SKILL names, in order. */
    public function getLoadedSkillNames(): array
    {
        return $this->getLoadedNamesOfType(LoadedCapability::TYPE_SKILL);
    }

    /**
     * The loaded SKILL names a given loader put here ({@see LoadedCapability::$source}) — the plan-item preload's
     * skills are the ones whose tools the offer may hold back; a skill the model loaded itself is never among them.
     *
     * @return string[]
     */
    public function getSkillNamesLoadedBySource(string $source): array
    {
        $skillNames = [];
        foreach ($this->getElements() as $capability) {
            if ($capability->type === LoadedCapability::TYPE_SKILL && $capability->source === $source) {
                $skillNames[] = $capability->name;
            }
        }
        return $skillNames;
    }

    /** @return string[] the loaded TOOL names, in order. */
    public function getLoadedToolNames(): array
    {
        return $this->getLoadedNamesOfType(LoadedCapability::TYPE_TOOL);
    }

    /** @return string[] the names of the loaded capabilities of `$type`, in order */
    protected function getLoadedNamesOfType(string $type): array
    {
        $names = [];
        foreach ($this->getElements() as $capability) {
            if ($capability->type === $type) {
                $names[] = $capability->name;
            }
        }
        return $names;
    }

    /**
     * The resolved skills of this set's loaded SKILL names, as remembered by the resolver
     * ({@see \DDD\Domain\AI\Services\Skills\AISkillsService::findLoadedSkillsOf()}) — null when none is remembered yet
     * or the set changed since. The set resolves nothing itself; it only keeps what the resolver found.
     */
    public function getRememberedLoadedSkills(): ?AISkills
    {
        return $this->rememberedLoadedSkills;
    }

    /** Keep the resolver's answer for this set (forgotten on {@see removeByNames()} and {@see restoreSnapshot()}). */
    public function rememberLoadedSkills(AISkills $loadedSkills): void
    {
        $this->rememberedLoadedSkills = $loadedSkills;
    }

    public function getByName(string $name): ?LoadedCapability
    {
        foreach ($this->getElements() as $capability) {
            if ($capability->name === $name) {
                return $capability;
            }
        }
        return null;
    }

    /** Σ residentTokens of ALL loaded capabilities (skills + tools) — the `loadedCapabilityTokens` of the eviction (NO messages). */
    public function getResidentTokens(): int
    {
        return array_sum(array_map(static fn (LoadedCapability $c): int => $c->residentTokens, $this->getElements()));
    }

    /** Σ residentTokens of the loaded capabilities of `$type` (TYPE_SKILL bodies vs TYPE_TOOL schemas) — the load-curve split. */
    public function getResidentTokensOfType(string $type): int
    {
        return array_sum(array_map(
            static fn (LoadedCapability $c): int => $c->type === $type ? $c->residentTokens : 0,
            $this->getElements()
        ));
    }

    /**
     * The full per-turn EVICTION SNAPSHOT — the load-curve split (skill vs tool vs total) + every loaded capability's
     * {@see LoadedCapability::toEvictionTelemetry()} row (recency + the three cost-benefit factors + score + verdict),
     * sorted highest-score-first. The observability payload an application's agent console command and its
     * eviction eval suite print each turn so an eviction can be WATCHED on real data. Pure — the same cost-benefit math
     * the per-turn sweep ({@see computeEviction()}) decides on, just rendered not applied.
     */
    public function buildEvictionSnapshot(int $currentContextInputTokens, int $currentTurnIndex, ContextRetentionSettings $settings): LoadedCapabilitiesEvictionSnapshot
    {
        $loadedCapabilityTokens = $this->getResidentTokens();
        $capabilityTelemetryRows = [];
        foreach ($this->getElements() as $capability) {
            $capabilityTelemetryRows[] = $capability->toEvictionTelemetry($currentContextInputTokens, $currentTurnIndex, $loadedCapabilityTokens, $settings);
        }
        usort($capabilityTelemetryRows, static fn (array $a, array $b): int => $b['evictionScore'] <=> $a['evictionScore']);
        return LoadedCapabilitiesEvictionSnapshot::create(
            $currentTurnIndex,
            $currentContextInputTokens,
            $this->getResidentTokensOfType(LoadedCapability::TYPE_SKILL),
            $this->getResidentTokensOfType(LoadedCapability::TYPE_TOOL),
            $loadedCapabilityTokens,
            $capabilityTelemetryRows
        );
    }

    /** Record a tool call — refresh its recency + bump stickiness. No-op if not loaded (skills are never "called"). */
    public function markUsedAt(string $toolName, int $contextInputTokens, int $turnIndex, ?int $initiatorMessageIndex = null): void
    {
        $this->getByName($toolName)?->markUsedAt($contextInputTokens, $turnIndex, $initiatorMessageIndex);
    }

    /**
     * A successful DESCENDANT call for the tool's SPECULATIVE parents only: the preloaded body learns
     * its prediction was right. An ordinary (model-loaded) parent is never touched — its recency fields drive the
     * cascade grace and the persisted JSON, which must stay byte-identical with every site off.
     */
    public function markDescendantUsedAt(string $toolName, int $turnInitiatorMessageIndex): void
    {
        $tool = $this->getByName($toolName);
        if ($tool === null) {
            return;
        }
        foreach ($tool->parentSkillNames as $parentSkillName) {
            $parent = $this->getByName($parentSkillName);
            if ($parent !== null && $parent->isSpeculative()) {
                $parent->markDescendantUsedAt($turnInitiatorMessageIndex);
            }
        }
    }

    /** @return string[] The SPECULATIVE capabilities (skills and tools) that saw no use and were loaded before the current turn. */
    public function getSpeculativeUnusedNames(int $currentTurnInitiatorMessageIndex): array
    {
        $names = [];
        foreach ($this->getElements() as $capability) {
            if ($capability->isSpeculativeAndUnusedSince($currentTurnInitiatorMessageIndex)) {
                $names[] = $capability->name;
            }
        }
        return $names;
    }

    /**
     * The SPECULATIVE CLEANUP: remove every decision-model preload that saw no use (own or descendant)
     * in the turn it was loaded for — one unused turn, counted by INITIATOR index. Independent of retention: runs even
     * when the eviction layer is off. Pins precede. A removed skill is DETACHED (copy-on-write) from every remaining
     * tool that listed it as a parent; a tool left with no parent goes with it. Returns the removed names.
     *
     * @return string[]
     */
    public function removeSpeculativeUnused(int $currentTurnInitiatorMessageIndex): array
    {
        $victimNames = $this->getSpeculativeUnusedNames($currentTurnInitiatorMessageIndex);
        if ($victimNames === []) {
            return [];
        }
        $removedSkillNames = [];
        foreach ($victimNames as $victimName) {
            if ($this->getByName($victimName)?->type === LoadedCapability::TYPE_SKILL) {
                $removedSkillNames[] = $victimName;
            }
        }
        $this->recordUnloadReason('speculative', ...$victimNames);
        $this->removeByNames(...$victimNames);
        $orphanedToolNames = [];
        foreach ($this->getElements() as $capability) {
            if ($capability->type !== LoadedCapability::TYPE_TOOL) {
                continue;
            }
            $remainingParents = array_values(array_diff($capability->parentSkillNames, $removedSkillNames));
            if ($remainingParents === $capability->parentSkillNames) {
                continue;
            }
            if ($remainingParents === []) {
                $orphanedToolNames[] = $capability->name;
                continue;
            }
            // ObjectSet::replace() is by-reference — a variable, never a method return
            $detachedCapability = $capability->withoutParentSkillNames($removedSkillNames);
            $this->replace($detachedCapability);
        }
        if ($orphanedToolNames !== []) {
            $this->recordUnloadReason('speculative', ...$orphanedToolNames);
            $this->removeByNames(...$orphanedToolNames);
        }
        return array_values(array_unique(array_merge($victimNames, $orphanedToolNames)));
    }

    /**
     * An INDEPENDENT deep copy through the persistence round trip — the exact shape the DB holds, no
     * shared instances. The admission simulation and the no-collateral check run on copies, never on the live set.
     */
    public function deepCopy(): static
    {
        $copy = new static();
        $copy->mapFromRepository($this->mapToRepository());
        return $copy;
    }

    /**
     * PIN every currently-loaded capability non-evictable — used when a PURPOSED conversation preloads its skills:
     * a flow skill unloaded mid-flow is the weak-model reload-hop failure class, so the whole
     * preload set is pinned for the conversation's lifetime. At seed time prior=null, so "all" = exactly the
     * preloaded skills + their tools. (Distinct from the per-skill frontmatter `nonEvictable`, a GLOBAL pin; this
     * is a per-CONVERSATION pin driven by the purpose.)
     */
    public function markAllNonEvictable(): void
    {
        foreach ($this->getElements() as $capability) {
            $capability->nonEvictable = true;
        }
    }

    /** Record why the named capabilities leave this set — read by the change encoder ({@see LoadedCapabilityChanges::between()}). */
    public function recordUnloadReason(string $why, string ...$names): void
    {
        foreach ($names as $name) {
            $this->unloadReasons[$name] = $why;
        }
    }

    /** @return array<string, string> name → why, for the capabilities removed since the reasons were last taken */
    public function getUnloadReasons(): array
    {
        return $this->unloadReasons;
    }

    /** Drop the recorded reasons of the named capabilities — the change encoder has written them into an `unload` event. */
    public function forgetUnloadReasons(string ...$names): void
    {
        foreach ($names as $name) {
            unset($this->unloadReasons[$name]);
        }
    }

    /**
     * The set as CANONICAL entries — name → every field with its full name, in set order. The value form the change
     * encoder diffs and the decoder rebuilds from: two sets are equal exactly when these arrays are equal.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toCanonicalEntries(): array
    {
        $entries = [];
        foreach ($this->getElements() as $capability) {
            $entries[$capability->name] = $capability->toCanonicalEntry();
        }
        return $entries;
    }

    /**
     * The set's state as a value — its capabilities and the unload reasons not yet persisted — for
     * {@see restoreSnapshot()} (an admission whose write rolls back puts the set back as it was).
     */
    public function buildSnapshot(): LoadedCapabilitiesSnapshot
    {
        return LoadedCapabilitiesSnapshot::ofLoadedCapabilities($this);
    }

    /** Put the set back to a snapshot {@see buildSnapshot()} took — in place, so every holder of this object sees it. */
    public function restoreSnapshot(LoadedCapabilitiesSnapshot $snapshot): void
    {
        $this->clear();
        foreach ($snapshot->getCanonicalEntries() as $entry) {
            $capability = LoadedCapability::fromCanonicalEntry($entry);
            $this->add($capability);
        }
        $this->unloadReasons = $snapshot->getUnloadReasons();
        $this->rememberedLoadedSkills = null;
    }

    /**
     * A NEW set built from canonical entries ({@see toCanonicalEntries()}) — fresh capability instances, never shared.
     *
     * @param array<string, array<string, mixed>> $entries
     */
    public static function fromCanonicalEntries(array $entries): static
    {
        $capabilities = new static();
        foreach ($entries as $entry) {
            $capability = LoadedCapability::fromCanonicalEntry($entry);
            $capabilities->add($capability);
        }
        return $capabilities;
    }

    /** Remove the named capabilities (the applied eviction result). Collect-then-remove (no modify-during-iterate). */
    public function removeByNames(string ...$names): void
    {
        if ($names === []) {
            return;
        }
        $drop = array_fill_keys($names, true);
        $victims = [];
        foreach ($this->getElements() as $capability) {
            if (isset($drop[$capability->name])) {
                $victims[] = $capability;
            }
        }
        foreach ($victims as $victim) {
            $this->remove($victim);
        }
        $this->rememberedLoadedSkills = null; // the loaded-skill view may have changed
    }

    /** Does ANY still-loaded capability list `$skillName` among its parents (a tool of, or a sub-skill of, the skill)? */
    protected function hasLoadedChild(string $skillName, array &$stillLoaded): bool
    {
        foreach ($stillLoaded as $capability) {
            if (in_array($skillName, $capability->parentSkillNames, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The per-turn eviction sweep over the loaded capabilities (skill bodies + tool schemas). PURE — returns the names
     * to evict; the caller applies them via {@see removeByNames()}. Operates over `$this` alone (load-turn + body
     * tokens are now carried IN the set). The mobile-OS shape: SOFT (tool score ≥ 1) + CASCADE (childless skill body) +
     * HARD (ceiling/jetsam).
     *
     * @return list<string> capability names to evict (skills + tools)
     */
    public function computeEviction(int $currentContextInputTokens, int $currentTurnIndex, ContextRetentionSettings $settings): array
    {
        $minResidencyTurns = $settings->minResidencyTurns ?? LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS;
        $hardCap = $settings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS;

        // Working set (name-keyed) we shrink as we decide; the caller applies the result to the real set.
        $stillLoaded = [];
        foreach ($this->getElements() as $capability) {
            $stillLoaded[$capability->name] = $capability;
        }
        $evicted = [];

        // SOFT — a TOOL whose cost-benefit score ≥ 1 unloads; then cascade-degrade any now-childless skill body.
        $loadBeforeSoft = self::sumResidentTokensOf($stillLoaded);
        foreach ($stillLoaded as $name => $capability) {
            if ($capability->type === LoadedCapability::TYPE_TOOL
                && !$capability->nonEvictable // PIN — never soft-evict
                && $capability->shouldUnload($currentContextInputTokens, $currentTurnIndex, $loadBeforeSoft, $settings)
            ) {
                $evicted[$name] = true;
                unset($stillLoaded[$name]);
            }
        }
        $this->cascadeDegradeChildlessSkills($stillLoaded, $evicted, $currentTurnIndex, $minResidencyTurns);

        // HARD — while over the ceiling, force-evict the lowest-priority eligible TOOL (highest score), then cascade.
        while (self::sumResidentTokensOf($stillLoaded) > $hardCap) {
            $victim = null;
            $victimScore = -1.0;
            $load = self::sumResidentTokensOf($stillLoaded);
            foreach ($stillLoaded as $capability) {
                if ($capability->type !== LoadedCapability::TYPE_TOOL
                    || $capability->nonEvictable // PIN — exempt from the HARD ceiling too (fixed always-resident overhead)
                    || $capability->turnsSinceLastUse($currentTurnIndex) < $minResidencyTurns
                ) {
                    continue;
                }
                $score = $capability->evictionScore($currentContextInputTokens, $currentTurnIndex, $load, $settings);
                if ($score > $victimScore) {
                    $victimScore = $score;
                    $victim = $capability;
                }
            }
            if ($victim === null) {
                break; // everything left is fresh (within min-residency) / a skill — the active working set is over the ceiling
            }
            $evicted[$victim->name] = true;
            unset($stillLoaded[$victim->name]);
            $this->cascadeDegradeChildlessSkills($stillLoaded, $evicted, $currentTurnIndex, $minResidencyTurns);
        }

        return array_keys($evicted);
    }

    /**
     * Σ residentTokens of a working set of capabilities.
     *
     * @param LoadedCapability[] $capabilitiesByName
     */
    protected static function sumResidentTokensOf(array $capabilitiesByName): int
    {
        return array_sum(array_map(static fn (LoadedCapability $capability): int => $capability->residentTokens, $capabilitiesByName));
    }

    /**
     * The CASCADE of {@see computeEviction()}: a skill body degrades once it has NO loaded child (tool or sub-skill) and
     * is past its own cold-start grace — repeated until nothing changes (dropping a sub-skill may orphan its parent). A
     * pinned skill body never cascade-degrades. Works on the eviction's working set.
     *
     * @param LoadedCapability[] $stillLoaded Keyed by capability name
     * @param array<string, true> $evicted
     */
    protected function cascadeDegradeChildlessSkills(array &$stillLoaded, array &$evicted, int $currentTurnIndex, int $minResidencyTurns): void
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($stillLoaded as $name => $capability) {
                if ($capability->type !== LoadedCapability::TYPE_SKILL) {
                    continue;
                }
                if ($capability->nonEvictable) {
                    continue; // PIN — a pinned skill body never cascade-degrades, even when childless
                }
                if ($capability->turnsSinceLastUse($currentTurnIndex) < $minResidencyTurns) {
                    continue;
                }
                if (!$this->hasLoadedChild($name, $stillLoaded)) {
                    $evicted[$name] = true;
                    unset($stillLoaded[$name]);
                    $changed = true; // dropping a sub-skill may orphan its parent → re-sweep
                }
            }
        }
    }

    // ---- SKILL-granular unloading (the default): a skill leaves with ALL its tools at once ----

    /**
     * The recency of a skill's GROUP as "owner turns since the last use": the MINIMUM over the body's own
     * turns-since, its tools' and — recursively — its sub-skills' groups (a live sub-skill keeps its root live). A member
     * without an initiator stamp (a legacy / `tool`-mode row) falls back to the turn-count axis ({@see LoadedCapabilityRecencies}).
     *
     * @param array<string, true> $visiting cycle guard over the sub-skill recursion
     */
    protected function groupTurnsSinceUse(string $skillName, LoadedSkillGroups $skillGroups, LoadedCapabilityRecencies $recencies, array &$visiting = []): int
    {
        if (isset($visiting[$skillName])) {
            return PHP_INT_MAX;
        }
        $visiting[$skillName] = true;
        $body = $this->getByName($skillName);
        $turns = $body !== null ? $recencies->getOwnerTurnsSince($body) : PHP_INT_MAX;
        foreach ($skillGroups->getToolNamesOfSkill($skillName) as $toolName) {
            $tool = $this->getByName($toolName);
            if ($tool !== null) {
                $turns = min($turns, $recencies->getOwnerTurnsSince($tool));
            }
        }
        foreach ($skillGroups->getSubSkillNamesOfSkill($skillName) as $subSkillName) {
            $turns = min($turns, $this->groupTurnsSinceUse($subSkillName, $skillGroups, $recencies, $visiting));
        }
        unset($visiting[$skillName]);
        return $turns;
    }

    /** The largest stickiness stretch of a group's members (a frequently used member keeps the whole group). */
    protected function groupStickinessFactor(string $skillName, LoadedSkillGroups $skillGroups): float
    {
        $factor = $this->getByName($skillName)?->usageStickinessFactor() ?? 1.0;
        foreach ($skillGroups->getToolNamesOfSkill($skillName) as $toolName) {
            $factor = max($factor, $this->getByName($toolName)?->usageStickinessFactor() ?? 1.0);
        }
        return $factor;
    }

    /** Whether a group holds a PIN anywhere (a pinned body pins its whole group; a pinned tool keeps its body). */
    protected function groupIsPinned(string $skillName, LoadedSkillGroups $skillGroups): bool
    {
        if ($this->getByName($skillName)?->nonEvictable ?? false) {
            return true;
        }
        foreach ($skillGroups->getToolNamesOfSkill($skillName) as $toolName) {
            if ($this->getByName($toolName)?->nonEvictable ?? false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The SKILL-granular unloading — the default replacement of the per-tool score. PURE: returns the
     * names to remove; the caller applies them via {@see removeByNames()}. A group is STALE when its owner-turns-since-use
     * (the recursive group recency) reaches `unloadSkillAfterUnusedTurns × stickiness` AND `minResidencyTurns`; a stale
     * group leaves WHOLE: its body, its sub-skill groups that are stale themselves, and every tool whose group parents are
     * ALL leaving. A tool with a surviving parent stays (the caller detaches the leaving parent copy-on-write). Pinned
     * groups never leave. The token axis is NOT consulted (it measured net growth, never size). Then the HARD cap at
     * group level: while the resident load exceeds `hardCapTokens`, the stalest unpinned group past its minimum residency
     * leaves whole (ties: the largest); best-effort when only pinned or fresh groups remain.
     *
     * @param LoadedSkillGroups $skillGroups the groups of this set (catalog edges ∪ recorded links)
     * @param LoadedCapabilityRecencies $recencies owner turns since each capability's last use
     * @return list<string> capability names to remove (skills + tools)
     */
    public function computeSkillUnloading(ContextRetentionSettings $settings, LoadedSkillGroups $skillGroups, LoadedCapabilityRecencies $recencies): array
    {
        $minResidencyTurns = $settings->minResidencyTurns ?? LoadedCapability::DEFAULT_MIN_RESIDENCY_TURNS;
        $skillTurnHorizon = $settings->unloadSkillAfterUnusedTurns ?? LoadedCapability::DEFAULT_UNLOAD_SKILL_AFTER_UNUSED_TURNS;
        $hardCap = $settings->hardCapTokens ?? LoadedCapability::DEFAULT_HARD_CAP_TOKENS;
        $groupsBySkillName = $skillGroups->getGroupsBySkillName();

        $leavingSkills = [];
        $recencyByGroup = [];
        foreach (array_keys($groupsBySkillName) as $skillName) {
            $recencyByGroup[$skillName] = $this->groupTurnsSinceUse($skillName, $skillGroups, $recencies);
        }
        if ($skillTurnHorizon > 0) {
            foreach (array_keys($groupsBySkillName) as $skillName) {
                if ($this->groupIsPinned($skillName, $skillGroups)) {
                    continue;
                }
                $turns = $recencyByGroup[$skillName];
                if ($turns < $minResidencyTurns) {
                    continue;
                }
                if ($turns >= $skillTurnHorizon * $this->groupStickinessFactor($skillName, $skillGroups)) {
                    $leavingSkills[$skillName] = true;
                }
            }
        }

        $removed = $this->resolveGroupRemoval($skillGroups, $leavingSkills);

        // HARD cap at group level (jetsam): the stalest unpinned group past its minimum residency leaves whole, repeat.
        while ($this->sumResidentTokensExcept($removed) > $hardCap) {
            $victimSkill = null;
            $victimTurns = -1;
            $victimTokens = -1;
            foreach ($groupsBySkillName as $skillName => $loadedSkillGroup) {
                if (isset($leavingSkills[$skillName]) || $this->groupIsPinned($skillName, $skillGroups)) {
                    continue;
                }
                $turns = $recencyByGroup[$skillName];
                if ($turns < $minResidencyTurns) {
                    continue;
                }
                $tokens = $this->getByName($skillName)?->residentTokens ?? 0;
                foreach ($loadedSkillGroup->toolNames as $toolName) {
                    $tokens += $this->getByName($toolName)?->residentTokens ?? 0;
                }
                if ($turns > $victimTurns || ($turns === $victimTurns && $tokens > $victimTokens)) {
                    $victimSkill = $skillName;
                    $victimTurns = $turns;
                    $victimTokens = $tokens;
                }
            }
            if ($victimSkill === null) {
                break; // only pinned or fresh groups remain — the active working set is over the ceiling
            }
            $leavingSkills[$victimSkill] = true;
            $removed = $this->resolveGroupRemoval($skillGroups, $leavingSkills);
        }
        return array_keys($removed);
    }

    /**
     * The members that leave with the given leaving groups: every leaving body, plus every loaded tool
     * whose group parents (catalog ∪ recorded) are ALL leaving or not loaded. A tool with a surviving parent stays.
     *
     * @param array<string, true> $leavingSkills
     * @return array<string, true> name → true
     */
    protected function resolveGroupRemoval(LoadedSkillGroups $skillGroups, array $leavingSkills): array
    {
        $removed = [];
        foreach (array_keys($leavingSkills) as $skillName) {
            $removed[$skillName] = true;
        }
        foreach ($this->getElements() as $capability) {
            if ($capability->type !== LoadedCapability::TYPE_TOOL) {
                continue;
            }
            $parents = array_values(array_unique(array_merge($skillGroups->getParentSkillNamesOfTool($capability->name), $capability->parentSkillNames)));
            $loadedParents = array_values(array_filter($parents, fn (string $parent): bool => $this->getByName($parent) !== null));
            if ($loadedParents === []) {
                continue; // an orphan already — the cascade of the old sweep; left to the caller's rules
            }
            $survivingParents = array_values(array_filter($loadedParents, static fn (string $parent): bool => !isset($leavingSkills[$parent])));
            if ($survivingParents === []) {
                $removed[$capability->name] = true;
            }
        }
        return $removed;
    }

    /**
     * Detach the leaving skills from every SURVIVING tool that still lists one of them as a parent — copy-on-write
     * ({@see LoadedCapability::withoutParentSkillNames()}), the same discipline as the speculative cleanup. Called by the
     * loop right before {@see removeByNames()} with the names {@see computeSkillUnloading()} returned.
     *
     * @param string[] $leavingNames
     */
    public function detachLeavingParents(array $leavingNames): void
    {
        $leaving = array_fill_keys($leavingNames, true);
        foreach ($this->getElements() as $capability) {
            if ($capability->type !== LoadedCapability::TYPE_TOOL || isset($leaving[$capability->name])) {
                continue;
            }
            $remaining = array_values(array_filter($capability->parentSkillNames, static fn (string $parent): bool => !isset($leaving[$parent])));
            if ($remaining !== $capability->parentSkillNames) {
                $detachedCapability = $capability->withoutParentSkillNames($leavingNames);
                $this->replace($detachedCapability);
            }
        }
    }

    /**
     * The SPECULATIVE cleanup at GROUP granularity: a decision-model preload leaves only as a WHOLE group
     * that saw no use — neither its body, nor any of its tools, nor a sub-skill under it (a preloaded
     * tool whose sibling was used stays with its live skill). Victims: every speculative body that is unused since
     * `$currentTurnInitiatorMessageIndex` and whose group saw no use, plus the tools whose group parents are all victims
     * or absent. Detaches survivors copy-on-write like {@see removeSpeculativeUnused()}. Returns the removed names.
     *
     * @return string[]
     */
    public function removeSpeculativeUnusedGroups(int $currentTurnInitiatorMessageIndex, LoadedSkillGroups $skillGroups): array
    {
        $victimSkills = [];
        foreach (array_keys($skillGroups->getGroupsBySkillName()) as $skillName) {
            $body = $this->getByName($skillName);
            if ($body !== null && $body->isSpeculativeAndUnusedSince($currentTurnInitiatorMessageIndex) && !$this->isGroupUsed($skillName, $skillGroups)) {
                $victimSkills[$skillName] = true;
            }
        }
        if ($victimSkills === []) {
            return [];
        }
        $removed = $this->resolveGroupRemoval($skillGroups, $victimSkills);
        // a speculative tool stays only with a live parent; a used tool never leaves with a preload cleanup
        foreach (array_keys($removed) as $name) {
            $capability = $this->getByName($name);
            if ($capability !== null && $capability->type === LoadedCapability::TYPE_TOOL && $capability->usageCountSinceLoad > 0) {
                unset($removed[$name]);
            }
        }
        $removedNames = array_keys($removed);
        $this->detachLeavingParents($removedNames);
        $this->recordUnloadReason('speculative', ...$removedNames);
        $this->removeByNames(...$removedNames);
        return $removedNames;
    }

    /**
     * Σ residentTokens of this set's capabilities without the given ones (the group-level hard cap of
     * {@see computeSkillUnloading()}).
     *
     * @param array<string, true> $removedNames
     */
    protected function sumResidentTokensExcept(array $removedNames): int
    {
        $residentTokens = 0;
        foreach ($this->getElements() as $capability) {
            if (!isset($removedNames[$capability->name])) {
                $residentTokens += $capability->residentTokens;
            }
        }
        return $residentTokens;
    }

    /**
     * Whether a skill's GROUP saw any use: its body, any of its tools, or — recursively — a sub-skill's
     * group. `$visiting` guards the recursion: a recorded-parent cycle between two loaded skills ends instead of
     * recursing forever (a catalog-derived set is a DAG, where the guard changes nothing).
     *
     * @param array<string, true> $visiting
     */
    protected function isGroupUsed(string $skillName, LoadedSkillGroups $skillGroups, array &$visiting = []): bool
    {
        if (isset($visiting[$skillName])) {
            return false;
        }
        $body = $this->getByName($skillName);
        if ($body === null) {
            return false;
        }
        if ($body->usageCountSinceLoad > 0 || ($body->descendantUsageCountSinceLoad ?? 0) > 0) {
            return true;
        }
        foreach ($skillGroups->getToolNamesOfSkill($skillName) as $toolName) {
            if (($this->getByName($toolName)?->usageCountSinceLoad ?? 0) > 0) {
                return true;
            }
        }
        $visiting[$skillName] = true;
        foreach ($skillGroups->getSubSkillNamesOfSkill($skillName) as $subSkillName) {
            if ($subSkillName !== $skillName && $this->isGroupUsed($subSkillName, $skillGroups, $visiting)) {
                unset($visiting[$skillName]);
                return true;
            }
        }
        unset($visiting[$skillName]);
        return false;
    }

    /** JSON-encode the loaded capabilities (skills + tools) for the message's JSON column. */
    public function mapToRepository(): mixed
    {
        if ($this->changeRecord !== null) {
            return (string)json_encode(['v' => self::CHANGE_RECORD_VERSION, 'e' => $this->changeRecord->mapToRepository()]);
        }
        $rows = [];
        foreach ($this->getElements() as $capability) {
            $rows[] = $capability->toRepositoryRow();
        }
        return (string)json_encode($rows);
    }

    public function mapFromRepository(mixed $repoObject): void
    {
        if (!is_string($repoObject) || $repoObject === '') {
            return;
        }
        $rows = json_decode($repoObject, true);
        if (!is_array($rows)) {
            return;
        }
        // a change row stores `{"v":2,"e":[…]}` — kept as a change record, never read as capabilities
        if (isset($rows['v']) && (int)$rows['v'] === self::CHANGE_RECORD_VERSION) {
            $this->changeRecord = new LoadedCapabilityChanges();
            $this->changeRecord->mapFromRepository($rows['e'] ?? []);
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // a compact entry (`n` instead of `name`) is read as well — checkpoints are written in the full
            // form so production code keeps reading them; this only keeps the decoder total.
            if (!isset($row['name']) && isset($row['n'])) {
                $row = LoadedCapability::expandCompactEntry($row);
            }
            if (!isset($row['name'])) {
                continue;
            }
            $capability = LoadedCapability::fromCanonicalEntry($row);
            $this->add($capability);
        }
    }
}
