# Skill hierarchy, offer and loading

**Reader:** host developer and package maintainer

Contents: the hierarchy · what is visible in which state · the offer of a turn · `load_skills` and its gates ·
`load_tool` and sub-skill tools · loads nobody asked the model for · delegation children · a skill called as a tool ·
the discovery modes · what belongs to other skills

## The hierarchy

A skill is a root (the default) or a sub-skill (`isSubSkill: true`). A root lists its direct sub-skills in
`subSkillNames`; a sub-skill may be listed by several roots. The rule is the same at every depth: a loaded skill
lists its own sub-skills. A root may list a general skill too; that skill stays in the catalog and gets no line in
the block.

- The catalog in the system prompt (`LoadSkillsTool::renderCatalogBlock()`, header `LoadSkillsTool::CATALOG_HEADER`)
  holds the TOP level only, one line per offered root: `- <name> — <description>`. The line speaks for the whole
  sub-tree and names no sub-skill and no tool, so the catalog stays the same for the whole conversation.
- A LOADED skill lists its offered direct sub-skills in its own block, under `AISkill::SUB_SKILLS_HEADER`, each with
  its `description` (`AISkill::getSubSkillLines()`).
- Tools are visible of loaded skills only.

The cost of a call therefore grows with what is loaded, not with how many skills exist.

## What is visible in which state

| State | Catalog | Block of a loaded skill | `load_skills` enum | Its tools |
|---|---|---|---|---|
| root, not loaded | its line | — | yes | no |
| root, loaded | its line, unchanged | its block: tools line, sub-skill lines, body | no | yes |
| sub-skill, no listing root loaded | — | — | no | no |
| sub-skill, a listing root loaded | — | one line in the root's block | yes | no |
| sub-skill, loaded | — | its own block; the root's line of it stays | no | yes |

`AISkills::filterForLoadedRootSkills()` admits a sub-skill exactly when a loaded skill lists it.

## The offer of a turn

`AIConversation::buildOfferedSkillCatalog()` gathers the inputs (`AIConversation::buildAISkillOfferRestrictions()`:
the entitlement, the purpose, the delegation root's purpose, the initiator type, the purpose rail, the model's
vision) and `OfferedSkillCatalog::buildFor()` applies every offer policy in one place, so the `load_skills` enum, the
`delegate` enum, the structural preloads and the decision model's answer set read the same set. The steps, in order,
each recording what it dropped in `OfferedSkillCatalog::$rejectedSkillNamesByStep`:

1. the general catalog plus the sub-skills of the loaded skills;
2. `featureFlags` — the flag gate (only when the host answers flags);
3. `budgetPolicy` — `Assistant.Budget` is dropped when the exact AI budget may not be reported;
4. `accountRights` — the rights gate (only when the host answers rights);
5. `billingAccess` — only when the host answers that the account has NO billing access;
6. `purposeBinding` — `onlyUnderPurposeNames` and `notUnderPurposeNames`;
7. `initiatorBinding` — `onlyOnInitiatorTypes`;
8. `purposeRail` — the purpose's `allowedLoadSkillNames` plus its `preloadSkillNames`, when the rail is set.

Vision is not an offer filter: a vision skill stays offered on a blind model (its name is recorded in
`OfferedSkillCatalog::$visionLockedSkillNames`) and its load is refused. `OfferedSkillCatalog::getRejectionStep()`
answers "why was X not offered?"; `AIConversation::buildReachableSkillCatalog()` answers what the conversation could
reach with every root loaded.

## `load_skills` and its gates

`LoadSkillsTool` (`load_skills`) takes `skill_names`, an enum of the offered names minus the loaded ones; the tool is
left out when nothing is left to load. The call goes through the load path's own gates
(`AISkillLoadRefusals::buildFor()`), because a model can still name a skill the enum did not hold:

| Gate, in order | Refused names land in | What the model is told |
|---|---|---|
| entitlement: flags, then rights | `flagLockedSkillNames`, `missingAccountRightNamesBySkillName` | not in the plan (offer the upgrade) / the account lacks the right (ask the administrator) |
| vision on a blind model | `visionLockedSkillNames` | needs a vision-capable model |
| purpose and initiator binding | `purposeBoundSkillNames`, `initiatorBoundSkillNames` | only inside its own purposed conversation / only in a call; plus the host's `purposeBoundSkillLoadAdvice` wording |
| the purpose rail | `railDroppedSkillNames` | not part of this conversation's skill set |
| sub-skill without a loaded or co-requested listing root | `unrootedSubSkillNames` | load the root first |

A sub-skill may be requested in the SAME call as its root. Billing and the budget policy restrict the offer only.
`AISkillLoadRefusals::renderCorrectiveSentences()` is appended to the result, so the model pivots instead of retrying;
`AgentSkillLoadOutcome` carries the result text and the admitted names. A call without a name is answered with
`LoadSkillsTool::RESULT_WITHOUT_SKILL_NAMES`.

## `load_tool` and sub-skill tools

`LoadToolTool` (`load_tool`) promotes single tools of loaded skills from "available" (summary only) to "loaded" (full
schema). When it is offered (never under append-only loading, the package default) belongs to
`ddd-module-agents-context-specialist`. Only in the discovery modes `loadTool`, `loadToolByName` and `both` does it also offer the tools of offered,
not yet loaded sub-skills of the loaded roots (`OfferedSkillCatalog::buildLoadableSubSkillTools()`,
`LoadableSubSkillTools`), and loading such a tool loads its sub-skill first; under the default `hierarchy` it does not. A tool two sub-skills carry belongs to the first of them.

## Loads nobody asked the model for

Every non-model load passes the same offer (`OfferedSkillCatalog::buildAdmissionFor()`, reasons in
`AdmittedAISkills`: `unknown`, `alreadyLoaded`, `notOffered` or the dropping offer step, `subSkill`, `visionLocked`).
Sources, recorded on the loaded capability (`LoadedCapability::SOURCE_STRUCTURAL`, `SOURCE_PLAN_ITEM`,
`SOURCE_DECISION_MODEL`; a model load has `SOURCE_MODEL` or none):

| Source | Declared by | Note |
|---|---|---|
| purpose preload | the purpose's `preloadSkillNames` | on the kickoff signal, flag- and right-filtered (`AIConversation::findPermittedSkillNames()`), pinned when `pinPreloadedSkills` is true |
| initiator binding | the skill's `onlyOnInitiatorTypes` | a call conversation gets its call skill from turn one |
| a widget in the owner's message | the skill's `preloadOnWidgetTypes` | for that turn; the fence `type` is the routing signal |
| the next plan item | a seed item's `skillNames` | preloaded when the item is the plan's next; may name sub-skills |
| the decision model | — | root first, then a sub-skill of a loaded root; the decision layer belongs to `ddd-module-agents-decisions-and-guards-specialist` |

Only a plan item and the decision model may preload a sub-skill. The preload decisions are made in
`AgentSkillPreloadDecisionsService`.

## Delegation children

A child conversation carries no purpose of its own. Its offer keeps the inclusion rule on its own (empty) purpose, so
it never loads a purpose-bound root by itself; it is seeded with the skills its delegation names. The EXCLUSION
(`notUnderPurposeNames`) is judged on the tree root's purpose too. Delegation itself belongs to
`ddd-module-agents-autonomy-specialist`.

## A skill called as a tool

A model that calls a skill name as if it were a tool is told the skill's real tools
(`AISkill::getToolNamesForSkillName()`); a call to a tool whose skill is not loaded is told which skills provide it
(`AISkill::getSkillNamesProvidingToolName()`). Both read the static binding, so tool names must be unique across
packages (the lint checks it).

## The discovery modes

`SubSkillToolDiscovery::resolveMode()` reads `AGENT_SUB_SKILL_TOOL_DISCOVERY` (then the further name the host
declares in its application names). Unset = `hierarchy`, the design above. The other values (`off`, `catalogIndex`,
`loadTool`, `loadToolByName`, `both`) are measurement arms that list sub-skills or their tools in the catalog or in
`load_tool`; their cost grows with the number of tools. Leave the default.

## What belongs to other skills

How a loaded skill travels on the wire (system content or appended entry), the prompt cache and when a skill or tool
is evicted: `ddd-module-agents-context-specialist`. The tool attributes that gate a tool: `ddd-module-agents-tools-specialist`.
