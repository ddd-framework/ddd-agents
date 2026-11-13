# Skill and tool eviction

**Reader:** host developer and package maintainer

Contents: 1. What is loaded and how it is stored · 2. When the loop unloads · 3. Skill granularity (the default) ·
4. Tool granularity · 5. Pins and initial residency · 6. Knobs and scaling · 7. Symptoms

## 1. What is loaded and how it is stored

`LoadedCapabilities` is one set of `LoadedCapability` items on a message: skill bodies (`LoadedCapability::TYPE_SKILL`)
and tool schemas (`TYPE_TOOL`). Each item carries model-invisible telemetry: `residentTokens` (chars / 4 of the skill's
instruction block or the tool's wire schema, memoized per name per worker), the context size and turn at its last use,
`usageCountSinceLoad`, `lastUsedInitiatorIndex` (the owner message of its last use; survives compaction), its parent
skills, its source (`SOURCE_MODEL`, `SOURCE_STRUCTURAL`, `SOURCE_PLAN_ITEM`, `SOURCE_DECISION_MODEL`) and its pin
(`nonEvictable`).

A skill load promotes the body and, when eager, all its tools (`LoadedCapabilities::withSkillsPromoted()`); a lazy load
promotes each tool on `load_tool` (`withToolsPromoted()`). Eager or lazy is `ContextRetentionSettings::$loadToolsOnSkillLoad`;
append-only loading is always eager. `load_tool` is offered only under `systemPromptSwap`, when a loaded skill has a
tool that is not resident (lazy, or evicted) (`AgentOfferedTools::buildFor()`); under `conversationAppend` it is never
offered, because its enum would change with every load.

Stored in one column per message: a row carries the complete set (a checkpoint), only its changes against the row
before (`LoadedCapabilities::asChangeRecord()`, version `CHANGE_RECORD_VERSION`), or nothing.
`LoadedCapabilitiesChain` turns the chain back into sets after a load and decides the encoding before a write; readers
always go through `AIConversationMessage::getLoadedCapabilities()`.

Rows written before the chain format carry their complete set each. `AIConversationMessagesService::compactLoadedCapabilities()`
rewrites them into checkpoints and change records, one conversation at a time from a cursor in the cache: it skips a
conversation with a run in progress or a message younger than `$quietMinutes`, writes each block in one short
transaction that locks the rows and compares their stored sets first, pauses between writes, backs off while the
database is busy and stops at its time budget; dry run and verification are options
(`compactLoadedCapabilitiesOfConversation()` for one conversation, report `AIConversationLoadedCapabilitiesRewriteReport`).
A host runs it from a console command of its own as an optional maintenance job, off peak, until the cursor has passed
every conversation; nothing in the package calls it.

Which skill a call renders, under which loading strategy, is in [prompt-cache-and-stable-prefix.md](prompt-cache-and-stable-prefix.md); what a skill file
declares is the skills-and-purposes skill's.

## 2. When the loop unloads

`AgentColdCapabilityUnloading::applyTo()` works on the newest message that carries a set; the messages service
persists that row once and writes one `eviction` turn event per victim after the persist
(`AIConversationMessagesService::unloadColdCapabilitiesOfRun()`).

| Granularity | Moment |
|---|---|
| `skill` (default) | once per owner turn, before the turn's first model call, after the speculative cleanup and before the plan-item preload |
| `tool` | after every step |
| any, under `conversationAppend` | never: skipped inside an append-only epoch |

At the head of every step the speculative cleanup runs too (`cleanupUnusedSpeculativeCapabilitiesOfRun()`, also
skipped under `conversationAppend`): a decision-model preload (`LoadedCapability::isSpeculative()`, source `SOURCE_DECISION_MODEL`) loaded in an earlier
owner turn and never used leaves — under `skill`
only as a whole unused group (`LoadedCapabilities::removeSpeculativeUnusedGroups()`), under `tool` per item.

## 3. Skill granularity (the default)

`LoadedCapabilities::computeSkillUnloading()` works on GROUPS: a skill body, every loaded tool its packages carry
(catalog edges plus the recorded parents, `LoadedSkillGroups`), and its sub-skills recursively.

- **Recency of a group** = the minimum owner turns since the last use of any member, sub-skills included
  (`LoadedCapabilityRecencies`); a live sub-skill keeps its root live.
- **A group leaves whole** when that recency reaches `unloadSkillAfterUnusedTurns` (8) × the largest stickiness of its
  members (1 + ln(1 + uses)) and at least `minResidencyTurns` (2). No token axis, no pressure term.
- A tool with a surviving parent stays; the leaving parent is detached copy-on-write
  (`LoadedCapabilities::detachLeavingParents()`).
- **Hard cap**: while the resident tokens exceed `hardCapTokens` (40000), the stalest unpinned group past its minimum
  residency leaves (ties: the largest). Only pinned or fresh groups left: the set stays above the cap.
- A pinned body pins its group; a pinned tool keeps its body.

One mutation per owner turn keeps the prefix byte-stable through all the steps of the turn.

## 4. Tool granularity

`LoadedCapabilities::computeEviction()`, per item:

```
staleness   = max(tokensSinceLastUse / (unloadToolAfterUnusedTokens × stickiness),
                  turnsSinceLastUse  / (unloadToolAfterUnusedTurns  × stickiness))
sizeFactor  = residentTokens / referenceResidentTokens
pressure    = residentTokensOfTheSet / pressureBudgetTokens
unload when turnsSinceLastUse ≥ minResidencyTurns and staleness × sizeFactor × pressure ≥ 1 and not pinned
```

- A skill body is not scored: it leaves when it has no loaded child left and is past its own grace (the cascade), so
  a tool never stays without its guidance.
- Hard cap: while over `hardCapTokens`, the highest-scoring eligible tool leaves, then the cascade runs again.
- An unused speculative preload leaves first, then the sweep re-runs (`AgentColdCapabilityUnloading`).

Because a single cooled tool can leave at any step, this mode changes the tools array more often; it stays available
for comparison.

## 5. Pins and initial residency

| Declaration | Effect |
|---|---|
| `#[MCPToolNoEviction]` on a tool method or package; `nonEvictable: true` in a skill file (`AISkill::$nonEvictable`) | the item is never unloaded, not even by the hard cap |
| `#[MCPToolAlwaysLoad]` on a tool | the tool's schema enters on the skill load even when lazy; no eviction effect |

A pin is fixed overhead in every call of the conversation: keep pins few and small.

## 6. Knobs and scaling

All on `ContextRetentionSettings`, null = the `LoadedCapability::DEFAULT_*` constant:

| Knob | Default | Decides something in | Only reported (eviction telemetry) |
|---|---|---|---|
| `unloadGranularity` | `skill` (config, env `AGENT_UNLOAD_GRANULARITY`) | picks the mode | — |
| `unloadSkillAfterUnusedTurns` | 8 | `skill` only: the group's turn horizon | `tool`: a skill body leaves by the cascade (no loaded child, past `minResidencyTurns`); no turn backstop |
| `unloadSkillAfterUnusedTokens` | 5000 | no unloading decision reads it | both modes |
| `unloadToolAfterUnusedTurns` / `unloadToolAfterUnusedTokens` | 10 / 8000 | `tool`: the tool's staleness | `skill` |
| `referenceResidentTokens` | 500 | `tool`: the size factor | `skill` |
| `pressureBudgetTokens` | 24000 | `tool`: the pressure factor | `skill` |
| `hardCapTokens` | 40000 | both: the hard cap (per tool in `tool`, per group in `skill`) | — |
| `minResidencyTurns` | 2 | both: the cold-start grace | — |
| `loadToolsOnSkillLoad` | null (eager) | both, at load time (always eager under `conversationAppend`) | — |

No knob decides anything inside an append-only epoch: nothing unloads there.

A purpose whose `contextBudgetTokens` exceeds `ContextRetentionSettings::RETENTION_REFERENCE_CONTEXT_TOKENS` (50000)
gets every unset token knob (the two token horizons — the skill one included, though nothing decides on it, the pressure budget, the hard cap) scaled by budget / 50000
(`ContextRetentionSettings::withTokenKnobsScaledToContextBudget()`); turn knobs never scale. Precedence of the
granularity: the conversation's value, the purpose's map, the environment variable, the config file.

## 7. Symptoms

| Symptom | Cause to check |
|---|---|
| A skill the next plan item needs is gone | it is re-admitted by the plan-item preload that runs right after the unloading |
| A skill never leaves | a pinned member, a live sub-skill, a frequently used tool (stickiness), or the conversation runs append-only |
| The agent calls `load_skills` for a skill it just lost | expected after 8 idle owner turns; raise `unloadSkillAfterUnusedTurns` for the purpose |
| Cache misses on every step | `unloadGranularity = tool`; switch to `skill` |
| The set grows past the hard cap | every remaining group is pinned or inside its minimum residency |
