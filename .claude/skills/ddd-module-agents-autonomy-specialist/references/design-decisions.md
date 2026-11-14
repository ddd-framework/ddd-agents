# Design decisions of autonomy

**Reader:** package maintainer

Contents: a sub-agent's result arrives as a notification · one sub-agent per delegate call · exactly one notification per run end · one level of delegation · eligibility is its own gate · the state sweep stamps a reason · a scheduled run is a purposed conversation · one delivery path per run · the fire claim is a compare-and-set · the unit of a one-instruction mandate is the owner's request · a mandate is declared per tool · unattended runs are never granted a mandate · the supervisor is off by default · the tandem is the only writer · call first, then the report · a template report needs no model call

## A sub-agent's result arrives as a notification

**Rule.** A delegation child is a conversation of its own (the conversation tree is a decision of the loop skill
`ddd-module-agents-loop-specialist`). Its result never returns as the value of the parent's tool call: the call is
answered at the spawn, and the result comes back as a signal row in the parent when the child's run ends.

**Reason.** A child may outlive the parent's turn, wait for a free slot, be continued later or run on another worker.
A call that waited for its child would hold the parent's run, its worker and its claim for as long as the child runs,
and a crash of either would lose the other. A signal row is persisted, claimable and replayable.

**Rejected.** A synchronous call that returns the child's answer (blocks a worker per child, breaks once a child
spans turns). A batch that resumes the parent once all children of a turn ended (a wait policy the model cannot see;
it was the model before one sub-agent per call).

**Would change if.** Children were always short and inline, so that no child ever outlived the parent's step — then
the synchronous path, which exists for console and evaluation runs, could be the only one.

## One sub-agent per delegate call

**Rule.** One `delegate` call starts one sub-agent: an agent task or one tool run. Parallel work is several calls in
one response. The schema has no task list, no wait condition, no quorum and no timeout.

**Reason.** One call, one record, one number, one result: every state change is one guarded write on one run of one
record, and the model sees exactly what it started. The model, not a platform wait rule, decides what to do when a
result arrives.

**Rejected.** A task list per call with a wait condition (`ALL`, `ANY`, `QUORUM`) and children writing into one shared
map on the call row — two meanings of "done", N writers on one row, three numberings of children, and prompt texts
that contradicted the schema. Fixing those defects one by one was considered and rejected as the larger change.

**Would change if.** A model provider offered native parallel sub-agent calls with their own result channel.

## Exactly one notification per run end

**Rule.** Every run end writes one signal row into the parent, gated by a compare-and-set on the run's
`reportedToParentAt` under the parent's message-index lock. The text holds facts only. A run that notifies without
resuming (platform work) writes a different source and rides the parent's next turn. A run cancelled together with its
parent is stamped reported and writes nothing, because the parent is closed.

**Reason.** The cached prompt prefix must not change when a child ends, so the result cannot be patched into an earlier
tool result; a new row at the end keeps the prefix. The compare-and-set makes a redelivery, a timeout and the
lost-notification sweep safe to run concurrently. An instruction in the notification would compete with the owner's.

**Rejected.** Rewriting the call row's answer with the result (breaks the cache, races with the parent's writes). One
summary row per batch (a wait policy again).

**Would change if.** Run ends of one parent became so frequent that one row each crowded the context — then the short
wait that merges close run ends into one model call would grow into a real merge.

## One level of delegation

**Rule.** A child is never offered `delegate`; its own call is refused, a tool result inside a child starts no
sub-agent, and the platform's background work is never requested from a child.

**Reason.** Capacity, deadlines, mandates and run claims are counted per tree root; one level keeps the tree a star,
the capacity cap meaningful and a mandate's coverage (the root's request) unambiguous.

**Rejected.** Recursive delegation with a depth limit — it multiplies cost and makes a runaway tree possible from one
model mistake, for no task seen so far that needed it.

**Would change if.** A task class needed a sub-agent to split its own work again, with a budget model per subtree.

## Eligibility is its own gate

**Rule.** Whether automated work may run for an agent space or an account is one predicate over the host's local
states, asked by every autonomous spender at its own entry (fire, sweep, resume, tool call, workers, report delivery).
It is never part of the budget. Unknown states deny.

**Reason.** A budget can be lifted for a run (evaluations, console options, admin drives, maintenance jobs); a scope
that ended must not run anyway. A budget that "reads zero" for an ended scope stops nothing once lifted.

**Rejected.** Hanging the check on the budget (bypassed before the predicate runs). Asking a billing system at fire
time (slow, unavailable, not the host's local truth). A rollout switch for the gate (the predicate was measured before
enforcing; a switch would only add a way to run for ended scopes).

**Would change if.** The package gained a notion of accounts with states of its own; then the default would stop
being "always eligible".

## The state sweep stamps a reason

**Rule.** The state sweep pauses or cancels a schedule with a `stateReason`, and re-arms only rows it stamped. A row
the owner paused carries no reason and is never touched by the sweep while the scope is suspended or eligible.

**Reason.** Without a marker the sweep cannot tell a platform pause from an owner pause, and re-arming every paused row
when a scope recovers would undo the owner's decisions.

**Rejected.** A separate owner-pause flag (two columns that must agree). Cancelling schedules only on the host's
deletion paths (a host has many; the sweep is the path-independent net).

**Would change if.** The owner could pause for a reason of the platform's vocabulary.

## A scheduled run is a purposed conversation

**Rule.** Every firing of a schedule is spawned with the purpose `Schedule.Run`. The purpose carries the run's role
(execute the mission, never administer schedules, nobody present) and the delivery contract, selected by the run's
report channel through a variant dimension. The seed message is the owner's mission and the previous run's result line
only.

**Reason.** A role statement in a static segment and a delivery paragraph glued into the seed message could be
compacted away and gave no place for tool rules. A purpose is where the package already declares what a kind of
conversation may do.

**Rejected.** Two purposes, one per channel (a channel is a value of the same run). A mission hook in the system
prefix (only worth it once a run demonstrably loses its mission to compaction).

**Would change if.** Runs of one schedule needed different roles, not only different channels.

## One delivery path per run

**Rule.** The platform delivers a run's report over the schedule's channel. The purpose declares the host's
owner-messaging send tools as blocked: they are dropped from the wire for the run and its sub-agents, named to the model
with the reason, and refused by the executor.

**Reason.** With a second path on the wire the model mailed the report twice or, with the mail tool gone, fell back to
other channels. The loop holds no tool name; the rule is declared where the kind of conversation is declared.

**Rejected.** A list of tool names inside the loop. A tool attribute "not offered on report channel X" (ties a tool to a
conversation column; kept as the fallback for a conversation kind without a purpose). Blocking the tools for every
unattended run (other unattended purposes have their own rules).

**Would change if.** A channel needed the model to send itself.

## The fire claim is a compare-and-set

**Rule.** `claimFireOccurrence()` moves `nextRunAt` to the next occurrence with one update guarded by the expected
old value and the status; the run is spawned only after a won claim. A skipped occurrence is refunded by a relative
decrement.

**Reason.** Several fire processes, a manual "run now" and a concurrent pause can meet on one row; a guarded update is
the only lock that survives a crash between claim and spawn without a stuck lock row.

**Rejected.** A lock row per schedule (needs its own reaper). Reading and then writing (double fire).

**Would change if.** Firing moved into a queue that delivers each occurrence exactly once.

## The unit of a one-instruction mandate is the owner's request

**Rule.** A ONE_INSTRUCTION mandate authorizes every call that carries out the one owner request it was granted from,
through the tool it is first used with and the tools sharing its binding key. The check is a comparison of ids
(root conversation, owner message); nothing is consumed per call. The next owner request needs its own consent.

**Reason.** Per call, one owner request that touches many records (for example "delete these twelve items") would
need one grant per call and a confirmation for each. Per request, the owner's words are the evidence and a redelivered call passes again. The owner's next message has a new id, so coverage
ends by construction.

**Rejected.** Widening the boundary to N messages, N turns or N steps (a later, different order would ride an old
grant; N is invisible to the owner). A time lease after the grant (exists already as the explicit TIME_WINDOW scope the
owner chooses). A classifier that detects continuations (brings back the non-determinism the id check removed).

**Would change if.** Owners routinely gave one instruction across several messages that the approval check could not
read as one request.

## A mandate is declared per tool

**Rule.** The gate reads only the tool's mandate attribute. The tool's declared effect (write, outward) does not make
it gated, and the package states no rule that inward actions are free.

**Reason.** Which action needs a standing permission is a product decision of the host: an outward write may be
covered by a stronger check of its own (a confirmation link), an inward one may be consequential. A derived rule would
gate too much or too little for every host.

**Rejected.** Deriving the gate from the effect declaration (one rule for all hosts).

**Would change if.** Several hosts converged on the same rule; then a default derived from the effect could ship with
an opt-out.

## Unattended runs are never granted a mandate

**Rule.** Inside a scheduled run or a sub-agent the platform never grants a mandate from the dialogue. A scheduled run
needs a TIME_WINDOW or UNLIMITED mandate granted while the owner was present; a sub-agent rides the root's grant of the
current request.

**Reason.** Consent needs the owner's words for this action; an unattended run has none, and a seed message written
by the platform is no consent. The live gate on every call makes a revoke reach a running schedule at once.

**Rejected.** Letting a schedule carry the creating turn's one-instruction grant (a deferred order would outlive the
request it was given in).

**Would change if.** The owner could be asked and answer within a run (a channel to the owner during unattended work).

## The supervisor is off by default

**Rule.** Supervision has one switch, `enabled` in `supervisor.php`, false in the package; no environment variable
overrides it; a mistyped value means off. A conversation can be armed alone by a run-cap override.

**Reason.** A supervisor adds a second agent's model calls to every supervised conversation. Whether its steers improve
outcomes beyond what the deterministic gates already enforce depends on the host's purposes, and a steer can also add
noise, so its benefit has to be shown per host before it runs. A host turns it on knowingly, per purpose with a
cadence.

**Rejected.** On by default. An environment switch (a deployment detail deciding product behaviour).

**Would change if.** A host's measurements showed supervised runs ending better.

## The tandem is the only writer

**Rule.** The supervised conversation only wakes the tandem; the tandem infers the owed beat, writes its briefing and
decision, and the supervised conversation materializes the decisions at its own next turn.

**Reason.** Two writers on the tandem collided on message indexes and lost checkpoints. One writer per conversation is
the rule of the loop.

**Rejected.** The supervised loop writing the briefing into the tandem.

**Would change if.** Never by itself; only a change of the loop's write model would reopen it.

## Call first, then the report

**Rule.** An EMAIL run ends with exactly one `send_owner_report` call stating an outcome. With `REPORT`, the text the
model writes after the call is the report; text beside the call is never bound. With `NOTHING_TO_REPORT` nothing is
mailed. A run that ends without the call gets one forced step pinned to it; a report text still without an outcome is
mailed by the owed-reports sweep.

**Reason.** Models called the tool before any report existed and wrote announcements beside the call. Binding by
position after the call is deterministic; an explicit outcome ends "nothing to say" runs without a mail and without a
sentence about mails.

**Rejected.** "Call it when there is something, stay silent otherwise" (the call became a ritual with empty content).
Binding the text beside the call (announcements were mailed). Choosing the report by its wording.

**Would change if.** Models reliably wrote the report before the call; the binding could then accept both orders.

## A template report needs no model call

**Rule.** A report owed for an abnormal stop is composed from the conversation's stored state (stop reason, task plan)
without a model call.

**Reason.** The stop is often the budget or a provider error; a model call there fails again, and the owner still gets
an honest account.

**Rejected.** A model-written summary at every stop.

**Would change if.** A stop reason left the model reachable and a summary measurably helped the owner.
