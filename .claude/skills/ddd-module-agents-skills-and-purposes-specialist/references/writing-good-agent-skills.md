# Writing good agent skills

**Reader:** host developer

Contents: the description decides reachability · cutting skills · the hierarchy rules for authors · the body ·
structure over prose · size · checking that a skill is reached

## The description decides reachability

The agent starts a conversation with no skill loaded. It sees the catalog lines and `load_skills`. A tool is reachable
only after its skill is loaded, and a skill is loaded only when its `description` covers the intent of the request.
A capability wired into a skill whose description does not name that intent exists and is never used.

- Name every intent the skill's tools serve, in the owner's words: "keep a note", "what do my notes say", not "notes
  CRUD".
- Name the tools when they help the model: `Demo.Notes` says "with addDemoNote … with getDemoNotes".
- End with what the skill is NOT for when a neighbour skill shares words. The decision model's catalog entry keeps the
  end of a long description (`AISkill::MAX_DECISION_CATALOG_ENTRY_CHARS`).
- `decisionCriteria` names SITUATIONS for the decision model, at most 200 characters. Without it the first sentence of
  the description is used, so make that sentence a complete statement of when to load.

## Cutting skills

- One skill per family of intents the owner would phrase together, not one per package. Fold related packages into
  one skill when a model would have to choose between them on wording alone; let it choose at the tool layer, where
  the schemas disambiguate.
- A settings intent ("from now on always …", "change how you …") gets its own skill: a model rarely maps it to a skill
  titled by the domain the setting affects.
- The working skill of a purposed run is bound to its purpose (`onlyUnderPurposeNames`): offered in an open chat it
  would start the whole run inline. The reverse, keeping a skill out of a run, is `notUnderPurposeNames`.

## The hierarchy rules for authors

- The root's line carries the whole discovery of its sub-tree. When a sub-skill or a tool is added, read the root's
  `description` and `decisionCriteria` and ask whether an owner who wants exactly this would be led to the root. If
  not, change both in the same delivery.
- The root's line names intents, never a sub-skill and never a tool, and keeps a fixed length. A root whose line can
  no longer cover its sub-tree is split into two roots or gains a level.
- A sub-skill's `description` states what its tools do and where its data is READ ("which entries exist today is
  read in the root"); a description that only names its topic draws every question about the topic.
- A root's own read tools name the fields they return within the first `AISkill::MAX_DECISION_OWN_TOOL_SUMMARY_CHARS`
  characters of their summary: that is all the decision model knows of the root's own work when it weighs a
  sub-skill.
- Never move a tool into the root to make it reachable, and never list the tools of an unloaded skill anywhere.
- The root's body refers to its sub-skills as listed in its block; it does not repeat their descriptions.

## The body

- Write what the agent must DO: when to call which tool, with which arguments, in which order, and what to say.
  `Demo.Notes` is three lines and complete.
- Name tools exactly as their method names. Never describe a tool the binding does not provide.
- Keep the core unconditional. A rule that holds only in one situation of a purpose goes into a marked section
  (reference [variants.md](variants.md)), never into an "if … then" paragraph in the core.
- Use `{%AGENT_NAME%}` and `{%PRODUCT_NAME%}` instead of names; never a host word in a module's skill.
- Do not restate what the platform renders itself: the blocked tools of a purpose, the tools line, the sub-skill
  lines, the plan, the situation line.

## Structure over prose

When a skill keeps failing on the same step, ask which responsibility can move OFF the model before adding a rule or
a refusal:

| Instead of | Move it into |
|---|---|
| a paragraph telling the model to read X before Y | a tool that returns X with its result |
| a rule "never call Z in this run" | `blockedToolNames` of the purpose |
| a rule "only in situation S" | a marked section and a variant dimension |
| "remember to tick the item when done" | a done criterion that closes the leaf from the record |
| "always load skill K for this" | a plan item's `skillNames`, a purpose preload or `preloadOnWidgetTypes` |
| a fine-grained refusal that leaves no valid next move | nothing: refuse only invalid data and ordering the platform owns |

## Size

Every loaded skill is resident on every call until it is evicted. `AISkill::getResidentBodyTokens()` is the estimate
the eviction weighs; `AISkillCatalogDocService::buildSkillCatalog()` measures every skill, package and tool exactly.
Keep bodies short, pins (`nonEvictable`) rare, and big reference material in tool results the model asks for.

## Checking that a skill is reached

1. The lint passes (reference [catalog-binding-overrides-and-lint.md](catalog-binding-overrides-and-lint.md)).
2. A cold case: a request that names only the intent, in a fresh conversation, run through a console command of the
   host. Read which skills were loaded and which tools were called. No skill loaded = the description does not cover
   the intent; the root without the sub-skill = the sub-skill's line does not.
3. Read the request snapshot of the call that went wrong: was the tool offered at all, which sections rendered,
   which refusal sentence came back. Debugging a turn belongs to `ddd-module-agents-observability-specialist`.
