# Design decisions — skills and purposes

**Reader:** host developer and package maintainer

Contents: a skill is a markdown file plus a binding · the agent selects from a catalog, then loads · offer is not
authorization · a host file replaces a module file, overlays unite · the catalog holds the top level only · the
platform resolves the situation, the skill carries sections · a purpose is data plus a required class · one class per
done criterion, a key without a class is no gate · the run's purpose owns its tool surface and its delivery path ·
two directions of binding between skills and purposes

## A skill is a markdown file plus a binding

**Rule.** One agent skill is one markdown file (frontmatter = selection signal and gates, body = instruction) at a
dotted path, plus one entry in `AI.skillPackages` that names its MCP packages by class or by package id. The dotted
name is the identity.

**Reason.** The prose changes often and is reviewed as text; the wiring to packages needs type safety and must not
point from the domain into the presentation layer. Splitting them along their rate of change keeps both simple, and a
config binding lets a module ship defaults a host replaces.

**Rejected.** A class per skill with the prose in constants: every wording change becomes a code change. Pure markdown
with package names inside the file: no refactor protection for the wiring. A database entity: not reviewable in
version control, needs migrations, and the package references stay strings.

**Would change if.** Skills had to be configured per account at runtime by non-developers; then an enablement layer
over the file registry would come first, not a database skill.

## The agent selects from a catalog, then loads

**Rule.** The model sees a cheap catalog of skill lines and the `load_skills` tool; the tool schemas and the body of a
skill enter the context only after it is loaded. One agent, skills loaded dynamically.

**Reason.** Tool definitions are paid on every call, and selection accuracy drops when a model sees many tools at
once. Loading on demand keeps the per-call context proportional to the task.

**Rejected.** All tools always on the wire: does not scale. A router agent with one sub-agent per domain: more
machinery for an isolation the catalog already provides. A provider's native deferred tool loading as the core
mechanism: ties the package to one vendor; it stays an optional adapter concern.

**Would change if.** A provider-neutral deferred loading became available on every supported model, or the catalog
outgrew one line per root even under the hierarchy.

## Offer is not authorization

**Rule.** Every skill-level gate (flags, rights, billing, binding, rail) filters what is OFFERED. Execution is guarded
separately on the tool (its entitlement attributes, the account's rights in every repository). A right declared on a
skill must also be declared on its packages.

**Reason.** The executor receives package classes, not skills, and a tool may belong to several skills; three callers
reach it (the agent, an MCP client, a console command of the host). Only a gate on the tool holds for all of them.

**Rejected.** Treating the skill as the hard gate: an MCP client or a console command would bypass it. Resolving "the
skill that exposed this call" at execution: there is no single answer.

**Would change if.** Tools were bound to exactly one skill and executed only through the agent loop.

## A host file replaces a module file, overlays unite

**Rule.** A skill, purpose or config file of the application at a module's path replaces the module's file whole. Gates
a host adds to a module's skill or purpose go into the requirement overlays, which are united with the frontmatter.

**Reason.** A merge of two markdown files has no clear meaning, and a host must be able to see the one file that loads.
Gates are the exception: a module's skill knows no host flag, and editing a module file only to gate it would fork
the whole file.

**Rejected.** Merging frontmatter key by key across roots: hidden state, hard to read. Gates only in the file: forces a
fork of every module skill a host gates.

**Would change if.** Hosts needed to change single sections of module skills routinely; then a section-level override
would be designed, not a file merge.

## The catalog holds the top level only

**Rule.** The catalog lists root skills only, one line each that speaks for the whole sub-tree. A loaded skill lists
its direct sub-skills in its own block. Tools are visible of loaded skills only, at every depth.

**Reason.** Everything the catalog says is sent on every call. A catalog that grows with sub-skills or tools does not
scale, and a catalog that changes when a skill loads rewrites the cached prefix. Under the hierarchy the catalog is
constant for the conversation and a sub-tree is paid only by conversations that load its root.

**Rejected.** Listing the tools of unloaded sub-skills in the catalog or in `load_tool`: grows with the number of tools
(kept as measurement modes of `SubSkillToolDiscovery`). Appending sub-skill descriptions to the root line: paid by
every conversation. Moving the tool into the root: defeats the cut. Retrieval of skills per step: an extra model call
or index per step, justified only at a catalog size far beyond one line per root.

**Would change if.** The roots themselves no longer fitted the catalog budget; then a retrieval step or another level
above the roots would be measured.

## The platform resolves the situation, the skill carries sections

**Rule.** A purpose declares variant dimensions; the platform resolves their values deterministically from the record
and the owner's statements, never the model; a skill keeps its core and carries conditional instructions as marked
sections that render only for matching values. Every call records the values and the rendered sections.

**Reason.** A body that carries every case as prose conditions lets the model mix the cases, and a failure cannot be
attributed. With sections the model never reads the other cases, and a wrong instruction points to one section or to
one resolver source.

**Rejected.** A full state machine with one skill per state: duplicates the shared core and multiplies files. Letting
the model decide the situation: the same mixing, with no record of why.

**Would change if.** Situations stopped being enumerable as small closed value sets; then a retrieval of instruction
pieces would be considered.

## A purpose is data plus a required class

**Rule.** A purpose is a markdown file whose frontmatter is the data stamped at creation and whose body is the
directive, plus a REQUIRED class named in the file's own `purposeClass` line. A missing or invalid class makes the
purpose not load; nothing falls back to the base class. The class is never inherited through `inheritsFrom`.

**Reason.** Data belongs in config, logic in code. A purpose loaded without its class would run without its criteria,
close logic and hooks and look healthy. A child purpose that inherited its parent's class would run the parent's
rules.

**Rejected.** A name-to-class map in code: a second registry that drifts. A default class when the line is missing:
silent loss of behaviour.

**Would change if.** Purposes never needed individual logic; they do (criteria, close, variants).

## One class per done criterion, a key without a class is no gate

**Rule.** Each seed `doneCriterion` key is decided by one small class registered on the purpose. It reads only its
inputs, declares the tools that can change it, and is evaluated lazily when a tick, a sweep or a seed walk reaches the
key. A stored key without a class is no gate.

**Reason.** Small classes are testable alone and owned by the purpose's domain, never by the agent core. Lazy
evaluation reads only what a call needs. Stored plans outlive code: a removed criterion must not block old plans
forever; a renamed one keeps its gate through `LEGACY_KEYS`.

**Rejected.** One switch over all keys in the core: couples the core to every domain. Computing every verdict up front:
reads facts no call needs. Failing on an unknown key: strands stored conversations after a refactor.

**Would change if.** A purpose needed a key to fail closed after its class was removed. Today no purpose can:
`enforcesDoneCriterionOnTicks()` makes an undecided verdict of a registered criterion refuse a done tick, and a key
whose class was removed is dropped from the stored plan at the next run start under every purpose.

## The run's purpose owns its tool surface and its delivery path

**Rule.** An unattended run (a scheduled run) is a purposed conversation. Its purpose declares the role statement, the
delivery contract per channel (as variant sections) and the tools the run must not use (`blockedToolNames`, which also
bind its delegation tree): names in the purpose file, plus the tools the purpose class blocks by what they declare
(`getToolNamesBlockedByDeclaration()`; a scheduled run blocks every tool declaring `MCPToolSendsMessageToOwner`). The
loop holds no tool name.

**Reason.** A delivery contract in a compactable user message is lost; a send tool left on the wire gives the model a
second delivery path and duplicated reports. Declaring the tool surface on the purpose keeps the rule generic: the wire
drops the tools, the directive names them from the same list, the executor refuses a stale call.

**Rejected.** A tool-name list inside the loop: per-tool knowledge in generic code. A tool attribute tied to a
conversation column: lets the tool decide what the conversation kind owns. One purpose per channel: a channel is a
value of the same run, not another kind of conversation.

**Would change if.** A kind of conversation without a purpose needed a restricted tool surface; the tool-side attribute
is the fallback for that case.

## Two directions of binding between skills and purposes

**Rule.** A skill binds itself to purposes (`onlyUnderPurposeNames`, `notUnderPurposeNames`, `onlyOnInitiatorTypes`); a
purpose binds itself to skills (`preloadSkillNames`, `allowedLoadSkillNames`). The rail always includes the preloaded
skills.

**Reason.** The skill knows where it is dangerous (a run's working skill would start the whole run in an open chat);
the purpose knows what its run may drift onto. Including the preloads in the rail lets an evicted pinned root be
reloaded; without it the run deadlocks (sub-skills unrooted, root outside the rail).

**Rejected.** Only purpose-side rails: every open chat would have to exclude every run skill. Only skill-side markers:
a run could still load general skills that derail it.

**Would change if.** Purposes and skills were always shipped together by one owner; one direction would then suffice.
