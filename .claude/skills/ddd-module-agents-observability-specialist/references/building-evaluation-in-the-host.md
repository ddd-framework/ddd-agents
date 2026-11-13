# What the host must build for evaluation

**Reader:** host developer

Contents: what ships, what does not · the parts of a runner · driving one case · reading the outcome · the judge · the suite · throwaway spaces and cleanup · checklist · full class names

The package ships the safety layers, the recorded runs and the read models an evaluation needs — no runner, no
judge, no suite, no case format. A host that evaluates its agent builds them as console commands of its own. This
reference names what each part calls in the package.

## What ships, what does not

| Ships | Does not ship |
|---|---|
| the eval marker and the eval switches on `RunSettings` | a case file format and a case loader |
| the eval tool block and the outbound recipient guard ([eval-safety](eval-safety.md)) | a runner command, parallel lanes, retries |
| `AgentEvalResult` (a verdict value object) | assertions over a run (expected tool, skill, status, arguments) |
| `AIConversationAuditRenderService` (the deterministic audit blocks a judge reads) | a judge prompt and a judge model call |
| request snapshots, turn events, metrics, the integrity report | a simulated owner for multi-turn cases |
| record and replay with the comparison ([record-and-replay](record-and-replay.md)) | a results folder, a summary, a baseline comparison |
| `AgentEvalOutboundGuardHooksService`, `AgentEvalAccountsService` as override points | test accounts, test spaces and their cleanup |

## The parts of a runner

1. **Cases.** One file per area: the owner's message (or several turns), the expected skill and tool, the forbidden
   tools, the blocked tools and their simulated errors, a read-only switch, set-up and restore steps.
2. **A scope per case.** A test agent space whose data the case may change; a throwaway space for a case that writes
   (`AgentEvalOutboundGuardHooksService::isThrowawayScope()` must answer true for it).
3. **A driver** that starts a marked conversation and runs the owner turns (below).
4. **Assertions** over the stored rows; the outcome as `AgentEvalResult`.
5. **A judge** for what assertions cannot see (optional).
6. **A suite** that runs cases in lanes, stores each outcome and sums them up.
7. **Cleanup** of throwaway spaces and their rows.

## Driving one case

| Step | Package call |
|---|---|
| mark the run | a `RunSettings` with `evalRun = true`, the block from `setEvalToolBlock()`, `storeRequestSnapshot = true` to keep every payload, `fixedReasoningEffort` to measure at one effort, `evalStopAfterToolName` for staged cases. A decision-site field set on that `RunSettings` (an arm such as `decisionModelSkillPreload`) is stored with it as given, because `driveConversation()` writes the whole run-settings column of a new conversation — without the value check and the set-by and set-at stamps of `AIConversationsService::applyAdminRunSettingsOverride()`, which runs right after and is the writer for an existing conversation |
| start and run turn 1 | `AIConversationAgentService::driveConversation()` with the run settings and the case's space, scope `AIConversationDriveScope::ADMIN` for a synchronous turn (a `CUSTOMER` drive is always async); or create the conversation with those run settings and call `generateAgentReply()` inside a turn envelope |
| next owner turns | the same, by conversation id; the run settings are stamped only when the conversation is created — run settings passed on a continue are ignored. A simulated owner is a model call of the host between turns |
| the worker path | drive with `async: true` and run the turn transport in this process (`sync://`), or wait for the run state to come back to `IDLE`. **Mandatory for a run with the supervisor on:** an inline (synchronous) owner turn holds no run claim, so when the supervisor resumes the supervised conversation (`AIConversationAgentService::resumeConversationIfReady()` with `async: true`) that resume claims it and runs a second agent run beside the inline one. On the worker path the turn holds the claim and a concurrent resume is dropped. The supervisor's side: `ddd-module-agents-autonomy-specialist` |
| budget | a runner process may lift the budget for itself (`grantBudgetBypassForTheProcess()` with `AIBudgetBypassGrant::REASON_CONSOLE_OPTION`); the spend is still booked |
| seeded history | a conversation the case seeds as the owner's past gets `evalSeededHistory = true` beside `evalRun` |

## Reading the outcome

Load the conversation with every row (`findMessagesInOrder()`), then:

- called tools, their status and arguments: the tool-call rows (`AIConversationMessages::buildToolCallHistory()`);
- loaded skills: `AIConversationMessages::getLoadedSkillNames()` — an empty list on a case that expects a tool is a
  routing failure (the model never loaded the skill that offers it), not an execution failure;
- the final text: the newest assistant text row;
- time and failures: [metrics-and-cost](metrics-and-cost.md);
- what the model saw at a wrong step: the request snapshot of that row ([debugging-a-turn](debugging-a-turn.md)).

Wrap the verdict in `AgentEvalResult($passed, $failures, $matchedTool, $conversation)`; the conversation it carries is
the trace for the report.

## The judge

A judge model reads what the owner saw and what the agent did. `AIConversationAuditRenderService` renders the
deterministic part, so the judge does not have to infer it from prose: `renderWriteLedger()`,
`renderOutwardActions()`, `renderWidgetInventory()`, `renderTaskPlanWithProvenance()`, `renderAgentSpaceState()`. A
host's same-path subclass names removed outward tools of old conversations
(`getRemovedOutwardActionToolNames()`). The judge prompt, its model and its envelope are the host's. A verdict names
what went wrong, not why: read the conversation and the request snapshot of the step it blames.

## The suite

Lanes in parallel processes, one case per process at a time (the recorders, the eval holds and the budget lift are
process state). Cases that write to the same space run in one lane. Keep every outcome (case, model, verdict, loaded
skills, called tools, metrics, cost) so a run can be compared with the last one. Exclude the eval accounts from the
host's own statistics through `AgentEvalAccountsService`.

## Throwaway spaces and cleanup

A throwaway space is the host's entity: create it per case or per lane, mark it for deletion, and delete it with its
rows after the run. The guard treats a send for it as eval context even outside an eval tool call.

## Checklist

- [ ] `RunSettings::$evalRun` set before the first drive, on every conversation the runner creates
- [ ] write tools to third parties blocked by default, an opt-in only with a restore
- [ ] `evalOutboundRecipients.php` lists the test sinks; every host sender calls `EvalOutboundRecipientsService`
- [ ] `AgentEvalOutboundGuardHooksService` and `AgentEvalAccountsService` overridden
- [ ] full request snapshots on, so a failure can be read
- [ ] each verdict checked against the stored rows before a fix

## Full class names

The classes this reference names, with their namespace (for the `use` lines of a host's code):
`DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAgentService` ·
`DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService` ·
`DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope` ·
`DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages` ·
`DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService` ·
`DDD\Domain\AI\Services\Eval\AgentEvalOutboundGuardHooksService` ·
`DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult` ·
`DDD\Domain\AI\Services\Eval\EvalOutboundRecipientsService` ·
`DDD\Domain\AI\Entities\Conversations\Messages\RunSettings`
