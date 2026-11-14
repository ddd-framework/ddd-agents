---
name: ddd-module-agents-context-specialist
description: "Explains and tunes what the ddd-agents model sees on each call and how that input stays small, stable and prompt-cached. Covers the model input (AgentModelInput system segments, volatile tail, core prompts), prompt cache and stable prefix (breakpoints, Chat Completions vs Responses, append-only skill loading, prefix epochs, reasoning carry-over, cached base prompt), context reduction (compaction tree, absolute threshold, preserved facts, tool-result stubbing), skill and tool eviction, state epochs and once-per-change notes, recall and conversation search, embeddings, memories and standing directives, owner presence, device and time zones, tool-call efficiency, contextRetention.php. Use when cached tokens drop to zero, a skill load costs the whole prompt, the context grows too large, compaction never fires or loses an id, a skill unloads too early, the agent re-loads a skill or repeats a read, the time or zone is wrong, search returns nothing, or a host adds memories or embeddings."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents Context Specialist

What the model of `mgamadeus/ddd-agents` reads on every call, in which order, and the mechanisms that keep that input
small (context reduction, eviction, tool-result stubbing), stable (one clock, state epochs, append-only skill loading)
and cached (prefix order, breakpoints, the cached base prompt). The turn that sends the call belongs to the loop skill,
the tools and their results to the tools skill.

## Scope

| This skill owns | Main classes |
|---|---|
| The per-call model input: system segments, history, volatile tail | `AgentModelInput`, `AgentContextSegmentType`, `AgentModelInputTailEntries` |
| The core prompts and the cached base prompt | `AgentAIPromptNames`, `AgentBaseSystemPromptCacheService` |
| Wire APIs, append-only skill loading, prefix epochs, reasoning carry-over | `AgentWireConfiguration`, `AgentPrefixEpoch`, `AgentAppendOnlyCall`, `AgentPreviousReasoningCut` |
| Context reduction (compaction tree, tool-result stubbing) | `AIConversationCompactionService`, `CompactionBudgets`, `CompactionModelLimits`, `ToolResultRetention` |
| Skill and tool eviction | `LoadedCapabilities`, `LoadedCapability`, `AgentColdCapabilityUnloading` |
| Volatile context delivered once per change | `AgentStateEpoch`, `AgentStateDelivery`, `AgentStateDelta`, `AgentSpaceContextFields` |
| Recall, conversation search, embeddings | `SemanticSearchService`, `AIConversationMessageEmbeddingsService`, `AITextEmbeddingsService` |
| Memories, standing directives, day context, local situation | `AgentMemoriesService`, `AgentStandingDirectives`, `AgentSituationalContextService` |
| Owner presence, device, time | `OwnerPresence`, `OwnerDevice`, `AgentClock` |
| The knobs | `ContextRetentionSettings`, `CompactionSettings`, `config/app/AI/contextRetention.php` |

## Rules that always hold

1. **The model input is rebuilt for every call and never persisted.** `AIConversation::buildModelInputUpToAndIncludingMessage()`
   assembles an `AgentModelInput` from the stored rows, so a historical call can be rebuilt as it was sent. Anything
   you want the model to read must come from a stored row, a request snapshot, a config file or a host service the
   build asks, never from a value set on the object in passing.
2. **Order is cache order.** The provider caches the longest byte-identical prefix: tools, then the system content,
   then the history. The order of the system segments is fixed and roughly most to least stable; what matters is
   that every segment is stable within the conversation, except LOADED_SKILLS (the loaded set, or the epoch) and
   COMPACTION_SUMMARY (the cover), which come last. Everything that changes per call rides the volatile tail AFTER the history
   and is never stored (`AgentModelInput::buildTailEntries()`); the reply-language cue is always its last entry.
3. **No volatile value in the prefix.** The clock, the unconfirmed-zone note, the situation provenance, the task plan
   and the "already fetched" hint are tail entries. A new value you add goes to the tail or becomes a state note; a
   new system segment must be stable for the whole conversation.
4. **One clock.** The model reads and writes moments in the agent space's local time; the tool executor converts at
   the boundary. A conversation owns no zone except a schedule-bound one; it re-reads the space's zone at every model
   request and every tool call.
5. **The stamped choices are frozen.** `ContextRetentionSettings::$skillLoadingStrategy`,
   `$rebuildEpochOnSituationChange` and `$stateChangeDelivery` are stamped at creation (request, then the purpose's
   `contextRetention:` map, then `config/app/AI/contextRetention.php`) and never change after the first model call.
6. **Nothing is unloaded inside an append-only epoch.** Under `conversationAppend` the cold-capability unloading is
   skipped (`AIConversationMessagesService::unloadColdCapabilitiesOfRun()` returns early); the set is rebuilt only when
   a new epoch starts (compaction, state epoch start).
7. **Token estimates are chars / 4.** Retention, eviction, the append-only tool-set ceiling and the fixed overhead use
   the stored character counts divided by four, never a tokenizer. Thresholds are ratios in that unit.
8. **Replace, never merge.** A host's `config/app/AI/contextRetention.php` and a host prompt file at the same path
   replace the package's file whole; a missing key falls back to the built-in default in the settings class, not to
   the package file.
9. **Host hooks are same-path subclasses** of `AgentMemoriesService`, `AgentSituationalContextService`,
   `AITextEmbeddingsService`, `AgentSpacesService` and `AgentModelInput`, resolved through the container; the defaults
   answer "nothing".

## Traps

- **`stateChangeDelivery = onceWhenChanged` and a literal placeholder.** The base prompt is then a template;
  `{%AGENT_SPACE_CONTEXT%}` and `{%OWNER_ADDRESSING%}` are filled per state epoch. A host prompt that drops either
  placeholder loses the value for the whole epoch; one that adds a new volatile placeholder breaks the prefix on every
  change.
- **A Chat Completions model silently runs `systemPromptSwap`.** The global default `conversationAppend` falls back
  to the swap on Chat Completions (`ContextRetentionSettings::resolveDefaultSkillLoadingStrategyForAgentWireApi()`);
  an append the start request names is refused
  (`AgentWireConfiguration::REJECTION_CONVERSATION_APPEND_NEEDS_RESPONSES`), while a purpose's wire profile the model
  cannot run is dropped at the purposed start with the trace line `purpose.agentWireProfile.fellBack`.
- **Every skill load under the swap costs the whole prefix.** It changes the top-level tools and the LOADED_SKILLS
  segment. Under append-only it costs one appended item; a second `additional_tools` item is what
  `AgentAppendOnlyToolSet` avoids by offering the whole reachable tool set top-level when it fits the ceiling.
- **No embedding provider = keyword-only search.** The default `AITextEmbeddingsService` throws
  `TextEmbeddingNotGeneratedException`; message rows get no embedding, compaction nodes keep the zero vector, the
  vector legs of the search find nothing. Nothing throws; every message, node and search logs one warning on the
  issues channel (`AITextEmbeddingsService::logEmbeddingFailure()`).
- **A rerank the host's gate refuses fails open.** `SemanticSearchService::rerankTextIndices()` falls back to the
  unfiltered order on any throw. With a host gate that requires an envelope
  (`AIModelCallGateService::refuseModelCallWithoutUsageScopeOrEnvelope()`; the default of `mgamadeus/ddd-ai` refuses
  nothing) that is every rerank outside one: run a host search endpoint inside a usage envelope, so the call is
  attributed and not refused.
- **`AIConversation::$timezone` is null for every conversation that is not schedule-bound.** Read the zone from
  `AIConversation::getTimezone()` or, in a tool, from `AgentToolCallProvenance::getTimezone()`.
- **Compaction never runs on a delegation child**, and the async path loads its own rows; a transient (id-less)
  conversation compacts in memory only.
- **The base prompt cache never sees a host write by itself.** The package calls no
  `AgentBaseSystemPromptCacheService::bumpInputsVersionOfAgentSpace()`; a host that changes what its space contributes
  to the prompt calls it (or `bumpInputsVersionForAccountId()`), else the old text stays for up to one hour. This host
  duty is described in the entry skill `ddd-module-agents-specialist`; the mechanism is in
  [prompt-cache-and-stable-prefix.md](references/prompt-cache-and-stable-prefix.md).
- **Standing directives refresh only after a tool that declares it.** `AIConversation::findMemoryDirectivesBlock()`
  re-reads the host's directives within one conversation object only when the number of completed calls of tools
  declaring `MCPToolWritesAgentMemoryOnly` changes. A host memory tool without the attribute changes the directives
  only for the next conversation object (the next run).

## Minimal template — standing directives from the host's own store

A same-path subclass under the host's root namespace (here the reference kernel's `AgentsReference\`; template only,
the kernel does not ship it). The block renders as the MEMORY_DIRECTIVES segment, right after the owner presence, in
the cached prefix; under `onceWhenChanged` a later change reaches the model once as a `standing-instructions` note.

```php
<?php

declare(strict_types=1);

namespace AgentsReference\Domain\AI\Services\Conversations\Memories;

use AgentsReference\Domain\Demo\Services\DemoNotesService;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentStandingDirectives;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService as BaseAgentMemoriesService;
use DDD\Infrastructure\Services\DDDService;

/** The owner's demo notes, read as standing directives (the demo store keeps them per authenticated account). */
class AgentMemoriesService extends BaseAgentMemoriesService
{
    public function findStandingDirectivesForAgentSpace(int $accountId, ?AgentSpace $agentSpace): ?AgentStandingDirectives
    {
        /** @var DemoNotesService $demoNotesService */
        $demoNotesService = DDDService::instance()->getService(DemoNotesService::class);
        $notes = $demoNotesService->findNotesOfCurrentAccount();
        if ($notes === []) {
            return null;
        }
        $agentStandingDirectives = new AgentStandingDirectives();
        // the block carries its own leading separator and header: the model input appends it as it is
        $agentStandingDirectives->directivesBlock = "\n\n# Standing instructions of the owner\n"
            . implode("\n", array_map(static fn (string $note): string => '- ' . $note, $notes));
        return $agentStandingDirectives;
    }
}
```

The directives are read once per conversation object and re-read only when the number of completed calls of tools
declaring `MCPToolWritesAgentMemoryOnly` changes (`AIConversation::findMemoryDirectivesBlock()`). The demo tool
`addDemoNote` does not declare it: a note it adds reaches the directives at the next run, not within the running one.
Keep the method a single indexed read.

## The knobs (`config/app/AI/contextRetention.php`)

| Key | Package value | Built-in default without the key | Read by |
|---|---|---|---|
| `unloadGranularity` | `skill` | `skill` (env `AGENT_UNLOAD_GRANULARITY` wins when non-empty) | `ContextRetentionSettings::resolveDefaultUnloadGranularity()` |
| `skillLoadingStrategy` | `conversationAppend` | `systemPromptSwap` | `ContextRetentionSettings::resolveDefaultSkillLoadingStrategy()` |
| `rebuildEpochOnSituationChange` | `true` | `false` | `ContextRetentionSettings::resolveDefaultRebuildEpochOnSituationChange()` |
| `stateChangeDelivery` | `onceWhenChanged` | `everyCall` | `ContextRetentionSettings::resolveDefaultStateChangeDelivery()` |
| `appendOnlyReachableToolSetCeilingTokens` | 55000 | 55000 | `ContextRetentionSettings::resolveAppendOnlyReachableToolSetCeilingTokens()` |
| `previousReasoningCapTokens` | 1000 | 1000 | `AgentWireConfiguration::resolveDefaultPreviousReasoningCapTokens()` |

Per conversation, every other knob lives on `ContextRetentionSettings` (tool-result window and budget, image cap,
eviction horizons, pressure, hard cap, pins) and `CompactionSettings` (threshold override, model, fallbacks); a
purpose sets them in its `contextRetention:` map. The purpose file format belongs to the skills-and-purposes skill.

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| What exactly is sent on a call; which segment says what; adding a day context or local situation | [what-the-model-sees.md](references/what-the-model-sees.md) |
| Cached tokens are 0 or low; a deploy or a load costs the whole prompt; choosing the wire API | [prompt-cache-and-stable-prefix.md](references/prompt-cache-and-stable-prefix.md) |
| The context grows past the model's reliable length; compaction never fires; an id is lost after compaction; old tool results disappear | [context-reduction.md](references/context-reduction.md) |
| A skill or tool leaves too early or never; tuning `unloadGranularity`, pins, the hard cap | [skill-and-tool-eviction.md](references/skill-and-tool-eviction.md) |
| A change of the space's context, the zone or the directives; `[SIGNAL:<kind>]` notes; the 30-minute rule | [volatile-context-once-per-change.md](references/volatile-context-once-per-change.md) |
| Search returns nothing or everything; adding embeddings; the message context window | [recall-and-search.md](references/recall-and-search.md) |
| Phone vs chat vs unattended run; the device bracket; time zones in tools | [owner-presence-device-and-time.md](references/owner-presence-device-and-time.md) |
| The agent re-loads a skill, repeats a read, or calls a tool three times | [tool-call-efficiency.md](references/tool-call-efficiency.md) |
| Memories, standing directives, episodic history | [memories-and-standing-directives.md](references/memories-and-standing-directives.md) |

**Package maintainer**

| Task | Reference |
|---|---|
| Adding a system segment or a tail entry without breaking the cache | [what-the-model-sees.md](references/what-the-model-sees.md), [prompt-cache-and-stable-prefix.md](references/prompt-cache-and-stable-prefix.md) |
| Changing the compaction tree, its budgets or the cover read | [context-reduction.md](references/context-reduction.md) |
| Changing the eviction math or the unloading moment | [skill-and-tool-eviction.md](references/skill-and-tool-eviction.md) |
| Adding a state kind or changing an epoch start rule | [volatile-context-once-per-change.md](references/volatile-context-once-per-change.md) |
| Changing a search leg, the rerank or the fusion | [recall-and-search.md](references/recall-and-search.md) |
| Why the mechanisms are built this way, and when to revisit them | [design-decisions.md](references/design-decisions.md) |

## Cross-references

- Installation, the transports that run compaction and embeddings, host duties (marking and reducing finalized
  conversations, repairing embeddings): the entry skill, `ddd-module-agents-specialist`.
- The turn that builds and sends the call, run claims, the summary call, task plan rendering: the loop skill,
  `ddd-module-agents-loop-specialist`.
- What a skill file declares (`nonEvictable`, sections keyed on a variant, the purpose's `contextRetention:` map and
  `contextBudgetTokens`), the catalog and the hierarchy: `ddd-module-agents-skills-and-purposes-specialist`.
- Tool results and their size, `toLlmMarkdown()`, `#[MCPToolEffect]` declarations, `#[MCPToolNoEviction]` on a tool,
  the agent-space default argument: `ddd-module-agents-tools-specialist`.
- The composed-view kit the base prompt teaches, widgets on a phone call: `ddd-module-agents-widgets-specialist`.
- Request snapshots, cache diffs, inspecting a sent payload, trace events: `ddd-module-agents-observability-specialist`.
- The decision sites that preload skills or measure the reply language: `ddd-module-agents-decisions-and-guards-specialist`.
- Models, the catalog keys `effectiveContextTokens` and `agentWireApi`, prompt cache modes, usage envelopes:
  `ddd-module-ai-specialist` (in `mgamadeus/ddd-ai`).
