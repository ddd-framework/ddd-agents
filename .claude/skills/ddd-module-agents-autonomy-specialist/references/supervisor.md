# Supervisor

**Reader:** host developer and package maintainer

Contents: what it is · on or off · which conversations · the beats · the tandem turn · the briefing · the decision · how a decision reaches the agent · what a host provides · traps

## What it is

A second agent that watches one owner conversation and may steer or correct it. It runs in its own conversation, the
**tandem** (purpose `Supervisor.Checkpoint`, `SupervisorCheckpointPurpose`), one per supervised conversation. The
supervised conversation never writes into the tandem; it only wakes it. The tandem is the only writer of its own rows
and the supervised conversation reads the decisions at its own next turn.

## On or off

**Off by default.** The package's `supervisor.php` sets `enabled` to false. The switch is read by
`SupervisorCheckpointService::isSupervisionEnabledGlobally()` through `SupervisorSettings::resolveEnabled()`: a bool
counts as itself, otherwise only the words of `SupervisorSettings::SWITCH_ON_WORDS` mean on — a mistyped value means
off. There is no environment variable. A host turns it on by its own `config/app/AI/supervisor.php`, which replaces the
package file whole — keys are not merged: a key the host's file omits takes the `SupervisorSettings` default, so a
missing `modelName` means the catalog's orchestrator default, not the package's model. The same file sets
`reasoningEffort` (the settled beat), `stepReasoningEffort` and optionally `policyClassForConversationsWithoutAPurpose`.
Each process reads the file once and keeps it: restart the workers after a change.

The run cap, `AIConversation::resolveSupervisorRunCap()`:

| `orchestratorRunCapOverride` of the conversation | Global switch | Cap |
|---|---|---|
| set (any value) | either | that value — above 0 arms one conversation while the switch is off, 0 switches it off |
| not set | off | 0 |
| not set | on | the purpose's `supervisorRunCap`, or `AIConversation::PURPOSELESS_SUPERVISOR_RUN_CAP` |

`orchestratorModelNameOverride` sets the model per conversation.

## Which conversations

`SupervisorCheckpointRunsService::supervisionAppliesTo()` needs all of:

- `AIConversation::canBeSupervised()`: stored, no delegation child, no tandem, and an owner-present run — **scheduled
  runs, admin tasks and external-agent conversations are never supervised**;
- a run cap above 0;
- no purpose, or a purpose that declares `supervisorCadence` above 0 (`AIConversationPurpose::isSupervisedByDeclaration()`).

A per-conversation override does not arm a purpose without a cadence.

## The beats

Two kinds (`SupervisorCheckpointBriefingHeader::BEAT_STEP`, `BEAT_SETTLED`):

- **step** — after a persisted tool batch that wrote something, failed, was refused or showed a widget
  (`SupervisorCheckpointTrigger::buildStepBeatReason()`); at most `SupervisorCheckpointTrigger::MAX_STEP_BEATS_PER_TURN`
  per owner turn;
- **settled** — at the end of a turn that rests on the agent's final text; counted against the run cap.

The loop only calls `wakeSupervisorTandem()`: it finds or creates the tandem, copies account, space, budget bypass and
both overrides, and resumes it asynchronously. Which beat is owed the tandem decides itself
(`AIConversation::getSupervisorBeat()`, `SupervisorCheckpointTrigger::buildOwed()`). The synchronous paths (console,
evaluation) run the settled beat inline after the turn
(`SupervisorCheckpointRunsService::runSupervisorSettledBeatAfterSyncTurn()`).

**Drive supervised owner turns on the worker path.** An owner turn driven inline (synchronously) holds no run claim.
A step beat during it wakes the tandem as usual, and an accepted intervention resumes the supervised conversation
asynchronously — a worker then claims it and runs a second agent run beside the inline one. A host's evaluation or
console path that supervises dispatches the owner turns to the workers and waits for them.

## The tandem turn

`SupervisorCheckpointRunsService::runSupervisorCheckpointTurn()`, up to four rounds: reload the supervised
conversation, compute the owed beat, write the briefing as a user row, decide (`runOneSupervisorCheckpoint()`). An
accepted intervention resumes the supervised conversation. A settled beat without intervention may close the purpose
when its goal is reached. After its claim is released the tandem re-fires when a beat is still owed. Errors are traced
and swallowed except a lost run claim.

## The briefing

`SupervisorCheckpointService::buildBriefing()` renders `SupervisorCheckpointBriefing`: a header (beat, purpose, covered
index, trigger), once the static part (the purpose's seeded plan, pitfalls, done criteria, a digest of the tools, result
markers, widgets), then the delta since the last checkpoint (owner rows, the agent's newest text, failed calls, owner
questions, widgets, the write ledger, the task plan, platform signals). A step beat adds the facts section; a settled
beat adds all owner rows, outward actions, the agent space's state and the purpose's audit criteria. The facts, the
state and the record kinds come from the purpose's `SupervisorPolicy` (`AIConversationPurpose::buildSupervisorPolicy()`).

The briefing reads the loaded window of the supervised conversation. For the earlier range that context reduction
replaced it reads only what the cover nodes keep: their counts of completed tool calls, closing the briefing as its
totals (`SupervisorCheckpointService` over `AIConversationCompactionCover::buildToolTelemetryBefore()`). An early write
that was reduced away therefore appears only as a count there, never with its arguments or result; a policy fact that
needs more must read it from stored state, not from the window (context reduction: context skill
`ddd-module-agents-context-specialist`).

## The decision

`SupervisorTools`, required tool choice, at most `SupervisorTools::MAX_MODEL_CALLS_PER_CHECKPOINT` calls:

| Tool | Meaning |
|---|---|
| `silentApprove` | nothing to do |
| `steer` | an instruction to the agent (`instruction`, `evidence`, `topic`, optionally the expected tool and the violated rule) |
| `correct` | the agent must correct something it told the owner; on a step beat it is converted to a steer |

`SupervisorDecisionAcceptance::judgeToolCall()` accepts or refuses: the evidence must name a covered row index or a
record kind of the policy; a step-beat steer must pin a tool the policy allows and rest on a facts line (with the base
policy every step-beat steer is refused); a finding already intervened on is a duplicate; a second correction of the
same record is downgraded to a steer. At most `SupervisorTools::MAX_INTERVENTIONS_PER_SETTLED_BEAT` per settled beat.
`SupervisorTools::LOOKUP_TOOL_NAMES` (`readToolResult`, `readMessage`, `readRecord`, `loadSkillContract`) are the
lookups of an earlier version: `SupervisorTools::toOpenAiToolSchemas()` no longer offers them; the names stay only so
stored tandem rows that carry them can be read.

## How a decision reaches the agent

At the start of the supervised conversation's own turn, `materializeUnconsumedSupervisorSteers()` appends each
unconsumed decision as a signal row (`SIGNAL_SOURCE_SUPERVISOR_STEERING` or `SIGNAL_SOURCE_SUPERVISOR_CORRECTION`) with
the text of `SupervisorCheckpointDecision::buildSignalTextForTerminalCall()`; a decision overtaken by newer rows becomes
a resolved note; a pinned tool that is not loaded gets the skills that provide it. A purpose does not close while
supervisor work is pending.

## What a host provides

Full class names of what a host extends or calls here: `DDD\Domain\AI\Entities\Conversations\Supervisor\SupervisorPolicy`.

- Its `supervisor.php` with `enabled` true, or an override per conversation.
- `supervisorCadence` (and optionally `supervisorRunCap`) in the frontmatter of each purpose to supervise
  (skills-and-purposes skill `ddd-module-agents-skills-and-purposes-specialist`).
- A `SupervisorPolicy` subclass for the purpose: facts, tool digest skills, agent-space state and its headline, record
  kinds, which step-beat steers are allowed. Without it a supervised purpose gets briefings but no step-beat steer.
- Optionally audit criteria per purpose (`AI.Prompts.AI.Supervisor.Criteria.` + purpose name) and its own operating
  prompt.

## Traps

- Turning the switch on and expecting scheduled runs to be watched: unattended runs are never supervised.
- Editing `supervisor.php` without a worker restart.
- The operating prompt and the purpose file still describe a JSON verdict in their last lines; the live path decides
  only by the three tools.
