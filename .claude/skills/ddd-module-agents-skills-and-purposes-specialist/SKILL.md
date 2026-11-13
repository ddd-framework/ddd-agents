---
name: ddd-module-agents-skills-and-purposes-specialist
description: "Writes and wires the agent skills and conversation purposes of the ddd-agents module: the skill file, every frontmatter key and its YAML traps, the skillPackages.php binding by class or package id, same-path overrides of a package skill, the overlays skillRequirements.php and purposeRequirements.php, the skill-file lint, sub-skills and the hierarchy, load_skills refusals and preloads, purpose and initiator binding, the purpose rail, gating by flags, rights, billing and vision, purposes with purposeClass and the purpose registry, the seeded task plan, done-criterion classes, the progress stepper, skill variants with marked sections and dimensions, and the purpose of a scheduled run. Use when writing an agent skill or a purpose, binding a skill to tools, overriding or gating a package skill, a skill never offered or never loaded, NOT loaded refusals, a frontmatter that does not parse, a purpose that does not load or start, a done criterion that never gates, or adding a variant dimension."
metadata:
  author: mgamadeus
  version: "0.1.0"
  module: mgamadeus/ddd-agents
---

# Agents — skills and purposes

How a host writes the agent skills (markdown files the AGENT loads at runtime) and the purposes (goals a conversation
is started for), binds a skill to MCP tool packages, gates it, overrides a package skill, and gives a purpose a
seeded plan, done criteria and variants.

> **Not here:** installing the module, the host's override of the entitlement service and the chat routes →
> `ddd-module-agents-specialist`. Writing an MCP tool and its attributes → `ddd-module-agents-tools-specialist`. How a
> loaded skill travels on the wire, the prompt cache and eviction → `ddd-module-agents-context-specialist`. The task
> plan tools and their tick gates → `ddd-module-agents-loop-specialist`. Firing schedules, delegation, supervision →
> `ddd-module-agents-autonomy-specialist`. The decision model's preloads → `ddd-module-agents-decisions-and-guards-specialist`.

## The pieces

| Piece | Where | Read by |
|---|---|---|
| Skill file | `config/app/AI/Skills/<Domain>/<Feature>.md`; dotted path = name | `AISkillsService::getAISkillByName()` |
| Skill binding (the registry) | `config/app/AI/skillPackages.php`, config `AI.skillPackages` | `AISkill::getPackageClassesBySkillName()` |
| Skill gates overlay | config `AI.skillRequirements` | `AISkillsService::applySkillRequirementOverlay()` |
| Purpose file | `config/app/AI/Purposes/<Domain>/<Feature>.md` | `AIConversationPurposesService::getPurposeByName()` |
| Purpose class | named by the file's `purposeClass`, extends `AIConversationPurpose` | the loop's hooks |
| Purpose registry | `REGISTERED_PURPOSE_NAMES` on the host's same-path subclass of `AIConversationPurpose` | `AIConversationPurpose::isRegisteredPurposeName()` |
| Purpose gates overlay | config `AI.purposeRequirements` | `AIConversationPurposesService::getPurposeByName()` |
| Done criterion | one `TaskPlanDoneCriterion` subclass per seed key, listed in `AIConversationPurpose::DONE_CRITERION_CLASSES` | `TaskPlanDoneCriteria` |
| Lint | `AISkillFilesLintService` | the host's test suite |

What runs where: the reference kernel (`examples/reference-kernel`) runs one skill, `Demo.Notes`, bound to
`DemoNotesMCPPackage`. It has no purpose of its own: the purpose, criterion and registry files of `templates/` exist as
templates only (the kernel declares no purpose registry; see "Same-path classes" below). The package
ships the skills `Assistant.Mandates` and `Assistant.TaskRunReports` and the purposes `Schedule.Run`,
`Supervisor.Checkpoint` and `Background.MechanicalOperation`.

## Same-path classes

A host's subclass of a package class (the purpose registry, an overridden service) is found by the framework's
same-path lookup: under `App\` at the mirrored path with no configuration, under another root namespace through a
`services.yaml` definition whose id is the package class (`ddd-module-agents-specialist`, override points).

## Rules that always hold

1. **The `description` is the only routing signal of a skill.** The agent starts with no skill loaded and loads one
   only when its catalog line covers the request. A tool behind a description that does not name its intent is never
   called.
2. **The binding is the registry.** A skill file without a key in `AI.skillPackages` is never offered. The host's
   [skillPackages.php](templates/skillPackages.php) replaces the package's whole: it re-lists `Assistant.Mandates` and `Assistant.TaskRunReports`
   when it wants them (`Schedule.Run` preloads the second).
3. **Application files replace module files, never merge.** A skill or purpose file at a module's path replaces the
   module's; the five config files `AI.skillPackages`, `AI.skillRequirements`, `AI.purposeRequirements`,
   `AI.skillNamespaceGroups` and `AI.agentPersona` are replaced whole: the host's file and the package's file are
   never merged. What unites is one entry of the (host's) requirement overlay with the frontmatter of the skill or
   purpose it names: its gates are added to the file's own gates.
4. **Gates on a skill filter the offer; they are not authorization.** A right on a skill must also sit on its
   packages as `MCPRequiresAccountRights`; every tool runs with the account's own rights.
5. **Every gate is inert until the host answers it.** The default `AIConversationEntitlementsService` answers no
   flags, no rights and unknown billing: every gate passes.
6. **The catalog holds the top level only.** A sub-skill appears in its loaded root's block; tools of a skill that is
   not loaded are visible nowhere.
7. **A purpose needs its file, its class and its registered name.** No `purposeClass` (or an invalid one) = the
   purpose does not load. The space-scoped start and the phone attach refuse an unregistered name;
   `AIConversationAgentService::driveConversation()` with a purpose name does not check the registry, so its caller
   validates the name.
8. **A done-criterion key without a registered class is no gate.** The model's tick is simply accepted.
9. **The platform resolves variants, never the model.** A purpose declares dimensions and resolves them from the
   record; a skill carries the conditional parts as marked sections.
10. **Examples in code follow the package rules:** services from the container, no closures in regular methods, no
    `private`, no `final`.

## Traps

| Symptom | Cause |
|---|---|
| A skill is never offered | not in the binding; a gate fails (`OfferedSkillCatalog::getRejectionStep()` names the step); `isSubSkill` without a loaded root listing it; `onlyUnderPurposeNames` / `onlyOnInitiatorTypes` |
| "NOT loaded (…)" in a `load_skills` result | a load gate refused it; the sentence names which (`AISkillLoadRefusals`) |
| `LogicException` about the skill binding at the first turn | the binding is missing, empty, or names an id no package declares |
| A package id does not resolve in the host | an id resolves only for a package the source-root scan finds; a host under another root namespace binds by class (the scan and its override: `ddd-module-agents-specialist`) |
| Every turn of every conversation fails | ONE bound skill file's frontmatter is not valid YAML (an inner double quote, a colon followed by a space): `AISkillsService::getCatalog()` parses every bound file for each turn's offer and does not catch. Run the lint before every release |
| A gate does nothing | a misspelt key (ignored), or the host does not answer that axis |
| `nonEvictable: "false"` pins the skill | a non-empty string casts to true |
| A purpose start answers "Unknown conversation purpose" | the name is not in the host's registry |
| A purpose loads as null with an error log | `purposeClass` missing, or not an existing subclass of `AIConversationPurpose` |
| Every read of a purpose throws a YAML parse error | `purposeClass` in double quotes: the backslashes are YAML escapes |
| A misspelt purpose key | silently ignored: purpose files have no key lint |
| A section never renders | its marker does not parse (stays text), or the purpose does not declare that dimension |
| A binding, overlay or package change has no effect | read once per process: restart the workers |

## Minimal template — one skill bound to its tools

The skill file, `config/app/AI/Skills/Demo/Notes.md` (the reference kernel's):

```markdown
---
decisionCriteria: "The owner asks to keep a note, or asks what the notes say."
shortDescription: "keeps the owner's notes and reads them back"
description: >-
  Keep a note for the owner with addDemoNote, read the notes back with getDemoNotes.
---
# The owner's notes

- When the owner asks you to note, remember or write down something, call `addDemoNote` with the owner's words.
- When the owner asks what the notes say, call `getDemoNotes` and list them.
- Confirm in one short sentence; never invent a note.
```

The binding, `config/app/AI/skillPackages.php`:

```php
use AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage;
use DDD\Domain\AI\Entities\Skills\AISkill;

return [
    AISkill::ASSISTANT_MANDATES => ['agent.mandates'],
    AISkill::ASSISTANT_TASK_RUN_REPORTS => ['agent.taskRunReports'],
    'Demo.Notes' => [DemoNotesMCPPackage::class],
];
```

Then run the lint in the host's suite. Copy-ready files with placeholders are in [templates/](templates/): [agent-skill.md](templates/agent-skill.md),
[skillPackages.php](templates/skillPackages.php), [purpose.md](templates/purpose.md), [PurposeClass.php](templates/PurposeClass.php), [DoneCriterion.php](templates/DoneCriterion.php), [PurposeNameRegistry.php](templates/PurposeNameRegistry.php). Each names its
placeholders with a sample value in its first lines.

## Task or symptom → reference

**Host developer**

| Task or symptom | Reference |
|---|---|
| Write a skill file; which frontmatter key does what; YAML traps; what the loaded block looks like | [references/skill-file-format.md](references/skill-file-format.md) |
| Bind a skill to packages; package ids; override, gate or drop a package skill; the lint test; admin catalog figures | [references/catalog-binding-overrides-and-lint.md](references/catalog-binding-overrides-and-lint.md) |
| Sub-skills; why a skill is not offered; load refusals; `load_tool`; preloads; delegation children | [references/hierarchy-and-loading.md](references/hierarchy-and-loading.md) |
| Gate a skill or purpose by plan, right, billing access or vision; upgrade texts | [references/gating-by-entitlements-and-rights.md](references/gating-by-entitlements-and-rights.md) |
| Write a purpose: file, class, registry, keys, start, hooks, seeded plan, done criteria, stepper, hidden purposes | [references/purposes-seeded-plan-done-criteria.md](references/purposes-seeded-plan-done-criteria.md) |
| Conditional instructions per situation: dimensions and marked sections | [references/variants.md](references/variants.md) |
| Make a skill reachable and small; move a rule into structure | [references/writing-good-agent-skills.md](references/writing-good-agent-skills.md) |

**Package maintainer**

| Task or symptom | Reference |
|---|---|
| Why skills are files plus a binding, why the catalog holds the top level, why offer is not authorization, why a purpose needs a class, why a key without a class is no gate, why a run's purpose owns its tool surface | [references/design-decisions.md](references/design-decisions.md) |
| The offer pipeline and its steps; the load gates in order; the discovery modes | [references/hierarchy-and-loading.md](references/hierarchy-and-loading.md) |
| What the lint checks and what it does not; what is memoized per process | [references/catalog-binding-overrides-and-lint.md](references/catalog-binding-overrides-and-lint.md) |
| Done-criterion lifecycle (seed, re-sweep, tick, owner turn, legacy keys) | [references/purposes-seeded-plan-done-criteria.md](references/purposes-seeded-plan-done-criteria.md) |
| The section parser and the variant state | [references/variants.md](references/variants.md) |

## Cross-references

- `ddd-module-agents-specialist` — installation, the override of `AIConversationEntitlementsService` (flags, rights,
  billing, product awareness), the agent space, the chat routes that start a purposed conversation.
- `ddd-module-agents-tools-specialist` — MCP packages, `MCPPackage::$name`, the entitlement attributes of a tool.
- `ddd-module-agents-context-specialist` — skill and tool loading on the wire, eviction, `nonEvictable`, the
  situation line, retention settings of a purpose.
- `ddd-module-agents-loop-specialist` — the task plan, `set_task_status` and `update_task_plan`, the seed gate keys.
- `ddd-module-agents-autonomy-specialist` — `Schedule.Run` firing and delivery, delegation, supervision.
- `ddd-module-agents-widgets-specialist` — `providesWidgetTypes`, `preloadOnWidgetTypes` fences, `findWidgets`.
- `ddd-module-agents-decisions-and-guards-specialist` — the decision model's skill preload.
- `ddd-module-agents-observability-specialist` — reading a turn's request snapshot when a skill was not used.
