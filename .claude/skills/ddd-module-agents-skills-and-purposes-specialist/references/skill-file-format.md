# Skill file format — every key the loader reads

**Reader:** host developer

Contents: where the file lives · the shape of the file · the frontmatter keys · persona placeholders · what the
model sees once the skill is loaded · YAML traps · checking a file

## Where the file lives

One agent skill is one markdown file below `config/app/AI/Skills/` of a config root. Its dotted name is its path:
`Assistant/Mandates.md` is the skill `Assistant.Mandates`. There is no id besides the name. The name is the key of
`AISkillsService::getAISkillByName()`, which reads it as the config value `AI.Skills.<name>` from the FIRST config
root that holds the file (the application's roots before a module's). A file alone is not offered: the skill also
needs its entry in the binding `AI.skillPackages` (reference [catalog-binding-overrides-and-lint.md](catalog-binding-overrides-and-lint.md)).

The package ships two skill files, `Assistant.Mandates` and `Assistant.TaskRunReports`; the reference kernel ships
`Demo.Notes`. Read `Demo.Notes` first: it is the smallest complete skill.

## The shape of the file

```markdown
---
decisionCriteria: "The owner asks to keep a note, or asks what the notes say."
shortDescription: "keeps the owner's notes and reads them back"
description: >-
  Keep a note for the owner with addDemoNote, read the notes back with getDemoNotes.
---
# The owner's notes

- When the owner asks you to note something, call `addDemoNote` with the owner's words.
```

`AISkillsService::splitFrontmatter()` splits a leading `---` … `---` YAML block from the body; a file without that
block is all body and has no description (the lint reports it). The body, trimmed, becomes `AISkill::$instruction`.

## The frontmatter keys

`AISkillsService::SKILL_FRONTMATTER_KEYS` lists exactly the keys the loader reads; the lint rejects any other key,
because a misspelt gate is silently no gate. Lists are read with `(array)` and cast to strings; the lint demands a
real list of strings (`AISkillFilesLintService::SKILL_FRONTMATTER_LIST_KEYS`).

| Key | Type | What it does | Read by |
|---|---|---|---|
| `description` | string, required | The routing signal of the chat model: the skill's catalog line `- <name> — <description>`, and for a sub-skill its line in the parent's block. Also the decision model's catalog entry (`AISkill::getDecisionCatalogEntry()`, capped at `AISkill::MAX_DECISION_CATALOG_ENTRY_CHARS`) | catalog, decision layer |
| `decisionCriteria` | string | The situations in which this skill is the one to load, for the decision model. Empty = the first sentence of `description`, cut to 200 characters (`AISkill::getDecisionCriteria()`). A change moves `AISkillsService::getDecisionCriteriaVersion()` | decision layer |
| `shortDescription` | string | A one-line, customer-facing summary. The package only parses it; the host's product awareness renders it, and `AISkills::buildRestrictedCapabilitiesMarkdown()` names a right-locked skill by it | host |
| `salesTriggers` | string | The observable moments at which a locked capability is worth offering. Parsed only; a host's product awareness renders it | host |
| `nonEvictable` | bool | Pins the loaded body: the eviction sweep never unloads it. Keep pins few and small; the mechanism belongs to the context skill | context |
| `requiredFeatureFlags` | list | The agent space must have ALL of these flags | offer, load, awareness |
| `anyOfRequiredFeatureFlags` | list | On top of the required ones, AT LEAST ONE of these. The first entry is the one an upgrade offer names (`AISkill::getFeatureFlagNamesAnUpgradeUnlocks()`): list the cheapest first | offer, load, awareness |
| `requiredAccountRights` | list | The acting account must hold every right. An offer filter only: the same rights must also sit on the skill's packages as `MCPRequiresAccountRights` | offer, load |
| `requiresAccountBillingAccess` | bool | Offered only where the host answers that the account has billing access | offer |
| `requiresVisionCapabilities` | bool | `load_skills` refuses it on a model without `AIModel::$hasVisionCapabilities` | load, preload |
| `isSubSkill` | bool | Not in the general catalog; offered only while a root that lists it is loaded | offer, load |
| `subSkillNames` | list | The root's edges: the sub-skills its loaded block lists. The lint checks that each name resolves to a file | offer, loaded block |
| `onlyUnderPurposeNames` | list | Offered, loadable and preloaded only inside a conversation of one of these purposes | offer, load, preload |
| `notUnderPurposeNames` | list | Never offered, loaded or preloaded under these purposes, also not in a delegation child whose tree root has one | offer, load, preload |
| `onlyOnInitiatorTypes` | list | Offered only in a conversation of this initiator type, e.g. `PHONE_CALL`, and preloaded there from the first turn | offer, load, preload |
| `preloadOnWidgetTypes` | list | A customer message that carries a widget fence of one of these types loads the skill for that turn, no `load_skills` call needed | preload |
| `providesWidgetTypes` | list | Metadata: the widget types this skill teaches; `findWidgets` loads the skill for such a widget. It offers nothing by itself (widgets skill) | widgets |

Every gate key is also settable without editing the file, through the overlay `AI.skillRequirements` (reference
`gating-by-entitlements-and-rights.md`). How the offer and load paths apply the binding keys is in reference
`hierarchy-and-loading.md`.

## Persona placeholders

`AISkillsService::substitutePersonaPlaceholders()` replaces `{%AGENT_NAME%}` and `{%PRODUCT_NAME%}` in the WHOLE raw
file before the frontmatter is parsed, from the config `AI.agentPersona` (`agentName`, `productName`; defaults
`AISkillsService::DEFAULT_AGENT_NAME` and `AISkillsService::DEFAULT_PRODUCT_NAME`). A skill of a module therefore
never names an agent or a product: `Assistant.Mandates` writes `{%AGENT_NAME%}` in its description. Purpose files go
through the same step.

## What the model sees once the skill is loaded

`AISkill::getInstructionBlock()` renders:

```
## Assistant.Mandates
Tools from this skill (already available): grantMandate, listMandates, revokeMandate
<one sentence per entitlement exclusion, if any>
<the SUB_SKILLS_HEADER line and one line per offered sub-skill, if the skill lists sub-skills>
<the body: the core, plus the conditional sections that match the conversation's variants>
```

- The tools line names the tools the plan includes and the user may call. A tool gated tighter than the skill that
  the space lacks is dropped from the line and one sentence says that other tools the body mentions are not in the
  plan; a tool the user lacks the right for gets its own sentence (ask the administrator, never an upgrade).
- The sub-skill lines carry each sub-skill's `description`; they do not change when a sub-skill is loaded.
- A body with marked headings renders by variant (reference [variants.md](variants.md)); a body without them renders whole.
- How and where the block travels on the wire, and when it is evicted, belongs to the context skill
  (`ddd-module-agents-context-specialist`). The skill's resident cost the eviction weighs is
  `AISkill::getResidentBodyTokens()`, the block's characters divided by four.

## YAML traps

The first trap is the blast radius: a bound skill whose frontmatter does not parse breaks the offer of EVERY turn of
every conversation. `AISkillsService::getCatalog()` parses every file bound in `AI.skillPackages` for each turn's offer,
and the YAML parse error is not caught (`AISkillsService::$throwErrors` does not change that). An unbound file breaks
only the load or preload that names it. Before every deploy, run `AISkillFilesLintService::findSkillFileProblems()`,
`findPurposeFileProblems()` and `findBindingProblems()` in the host's test suite (the test is in
[catalog-binding-overrides-and-lint.md](catalog-binding-overrides-and-lint.md)); the package ships the service, no
console command.

Each of these is silent until the lint runs or a turn fails:

| Written | What happens | Write instead |
|---|---|---|
| `description: "say "yes" first"` | the frontmatter does not parse; every turn fails while the skill is bound | single quotes, a folded block (`>-`), or `\"` |
| `description: Load it when: the owner asks` | a colon followed by a space in a plain value does not parse | quote the value or use `>-` |
| `description: notes #2 and more` | everything after ` #` is a YAML comment; the description is cut | quote the value |
| `requiredFeatureFlags: X` | loads as `['X']`, but the lint reports "not a list of strings" | `requiredFeatureFlags: [X]` |
| `nonEvictable: "false"` | a non-empty string casts to true: the skill is pinned | `nonEvictable: false` |
| `requiredFeatureFlag: [X]` (typo) | ignored, the skill is ungated | the exact key; the lint names unknown keys |
| an agent name with a quote character in `AI.agentPersona` | the placeholder is substituted before parsing and can break a double-quoted value | keep `{%AGENT_NAME%}` out of double-quoted values, or use `>-` |

Two files whose names differ only in case are one name for the config reader; which one loads depends on the
directory order. `AISkillSourcesService::findCollidingNames()` finds them; the lint reports them.

## Checking a file

`AISkillFilesLintService::findSkillFileProblems()` over the skill folders returns one line per problem (file and
cause); an empty list is clean. Run it in the host's test suite over every registered root (reference
`catalog-binding-overrides-and-lint.md` has the test). The lint does not check that a gate's flag or right exists in
the host, that a sub-skill graph has no cycle, or that the description covers the intents of the skill's tools.
