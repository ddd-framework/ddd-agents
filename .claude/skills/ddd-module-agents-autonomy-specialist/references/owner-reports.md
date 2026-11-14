# Owner reports

**Reader:** host developer and package maintainer

Contents: when a run owes a report · send_owner_report · when the model does not report · template reports · late delivery · the mail · earlier reports of a task · ineligible scopes · what a host provides · traps

## When a run owes a report

An unattended run owes the owner an account of what it did. The obligation is two columns of the conversation,
`reportOwedAt` and `reportDeliveredAt` (`AIConversation::getReportObligation()` → `AIConversationReportObligation`).
`AIConversationOwnerReportDeliveryService::stampReportOwed()` stamps it:

- at the start of a run with `reportChannel` EMAIL and an initiator type (a scheduled run with an email report);
- at an abnormal end of any run: stall give-up, the AI budget, a step, cost or repeat stop, a provider error, a refused
  wire configuration, and the budget closing step.

`rearmReportObligationForNewRun()` arms it again when a new message arrives in an existing EMAIL conversation.
`reportDeliveredAt` is the exactly-once claim: every deliverer takes it with a guarded update before it appends or
mails, and gives it back on failure.

A normal end of an attended turn owes nothing: the answer is the delivery. A CHAT scheduled run's final answer is its
report; it is read in the host's task list.

## send_owner_report

`SendOwnerReportTool` (`send_owner_report`), offered only when the conversation's report channel is EMAIL, an initiator
is stamped and the report is not delivered yet.

| `outcome` | Arguments | What happens |
|---|---|---|
| `REPORT` | `subject` required (one line) | the call is accepted; the next step runs without tools and the text the model writes then is bound to the call, marked as the owner report (`SIGNAL_SOURCE_OWNER_REPORT`) and mailed |
| `NOTHING_TO_REPORT` | no subject | the claim is consumed, nothing is appended, nothing is mailed |

The order is fixed: **call first, then the report.** The text written beside the call is never bound — "I'll send the
report now." would otherwise become the mail. Execution is at loop level
(`executeOwnerReportSendAtLoopLevel()`, `deliverOwnerReportWrittenAfterTheCall()`, `deliverOwnerReportMessage()`); a
stale claim without content is taken back (`revertStaleReportDeliveryClaim()`). The `Schedule.Run` purpose teaches the
model the contract per report channel; the purpose file belongs to the skills-and-purposes skill
`ddd-module-agents-skills-and-purposes-specialist`. The run never sends a mail itself and never says it did or did not.

- **What is bound.** `SendOwnerReportTool::bindReportSourceToCalls()` binds the text at once only on the forced closing
  step (its call follows the final text it was forced after); every other call binds the text written after it, and the
  call's answer says that text beside the call is not sent.
- **The subject.** A report without a subject gets the schedule's label and the run day
  (`AIConversationSchedule::composeReportEmailSubjectForRun()`).
- **One delivery path.** The send tools a purpose blocks are dropped from the offer, named in the purpose's directive,
  refused by the executor, and never named as callable in the answer of `load_skills` or in a stall signal
  (`AIConversation::findBlockedToolNamesOfConversationTree()`, the root's purpose for a sub-agent).
- **The claim is not proof of content.** `claimReportDelivery()` is a conditional update and the report a separate
  insert, so a process killed between the two leaves the claim taken and no report. A taken claim counts as delivered
  only when `AIConversationMessages::getNewestOwnerReportWithContentForRun()` finds a marked report with content; the
  run's boundary is both the newest owner message and `reportOwedAt`, and every marked row is inspected. Every writer
  of `reportOwedAt` uses the PHP clock, never the database's. A claim without content is taken back only when older
  than `OWNER_REPORT_CLAIM_STALE_AFTER_SECONDS`; the same content check runs before `composeAndAppendOwedReport()`
  composes and again right before its append.

## When the model does not report

`armForcedOwnerReportCallIfOwed()`: an unattended EMAIL run whose own turn ends on a final text without the call, with
a step left, runs one more step pinned to `send_owner_report`, preceded by one signal
(`AIConversationOwnerReportDeliveryService::FORCED_OWNER_REPORT_CALL_NOTE`). The note is never the report; the binding
stays on the text. The note asks for the outcome and names NOTHING_TO_REPORT first: a final text that says the check
found nothing is closed without an email; a problem that kept the task from being done is REPORT.

## Template reports

An abnormal stop delivers a report built **without a model call** (a model call at a budget stop would fail again):
`AgentRunTerminalReport` (`forLoopStopWithSavedWrites()`, `forOwnerFacingStopSentence()`, `forStallGiveUp()`) and
`AgentRunReport::composeFromConversation()`: the stop reason (`AgentRunReport` `STOP_REASON_*`), the done, open and
blocked items of the task plan, "will be retried" where it applies. The lines are translation keys; the words for the
billed entity come from `agentApplicationWording.php`.

## Late delivery

A run that died before it delivered still owes. A console command of the host (the host duty "deliver owed reports",
template in the entry skill `ddd-module-agents-specialist`) runs every few minutes:

1. `AIConversationsService::findConversationIdsOwingReports()` — owed, not delivered, in no live run state (not
   RUNNING, AWAITING_DELEGATES or QUEUED; IDLE, an empty state and the terminal states qualify), not part of an
   evaluation run's tree, this environment, oldest first (`OWED_CONVERSATIONS_BATCH_LIMIT` per pass);
2. per conversation, as its account: `AIConversationAgentService::deliverOwedReportLate()` — claims, then
   `composeAndAppendOwedReport()`: a pending REPORT call is bound to the final text; a run that ended its own turn
   without an outcome has its report text mailed, chosen by its place, never its wording
   (`AIConversationMessages::getReportTextOfTurnWithoutOutcome()`: the turn's final text, or, when the forced step was
   answered with text again, the text before the `SIGNAL_SOURCE_OWNER_REPORT_FORCED_CALL` note — so a final text that
   only says "nothing new" without the call is mailed); otherwise the template
   report with the stop reason of the newest error row. Then the purpose closes when its goal is reached;
3. on an error, `deferOwedReport()`.

A late template delivery also records the run as failed on its schedule (`recordRunTerminalOfConversation()` with a
failed run): the failure streak, the backoff and the owner notice follow.

## The mail

`composeAndSendReportEmail()` is the single entry: nothing happens unless the channel is EMAIL, the run is no
evaluation and the scope is eligible. Asynchronously it dispatches `AIConversationReportEmailSendMessage` (the
conversation id only; `AIConversationReportEmailSendHandler`, on the transport the entry skill lists for it), which
calls back synchronously; that calls `AgentOwnerReportEmailsService::sendReportEmail()`.

**The package default sends nothing and returns false.** A host overrides `AgentOwnerReportEmailsService` at the same
path to send the mail (and, if it wants, to render widgets of the report as images — that is application code). A
failed send gives the claim back and defers; after `REPORT_EMAIL_GIVE_UP_AFTER_SECONDS` from the run's start it gives
up and keeps the claim. `composeOwnerReportOfRun()` builds the content (`AIConversationOwnerReport`: subject, body from
the marked report, else the final text, else the template — `BODY_SOURCE_*`).

## Earlier reports of a task

`AgentTaskRunReportsMCPPackage` (skill `Assistant.TaskRunReports`, only under `Schedule.Run`, preloaded by it):
`getPreviousTaskRunReports` returns the reports the earlier runs of the same schedule wrote — model-written ones only
(`findModelWrittenOwnerReportOfRun()`), newest first, each with its widget blocks and whether the task changed since
(`AIConversationEarlierRunReport`; the comparison of the run's seed with the current task is
`AIConversationSchedule::isCurrentTaskOfRunSeed()`). The run keeps their structure and states what changed. Nothing is stored or
injected into the seed.

## Ineligible scopes

`handleReportObligationForIneligibleScope()`: an ended or erased scope closes the obligation (`suppressionReason`,
`suppressedAt`, and the delivered stamp); a suspended scope defers it up to
`REPORT_SUPPRESSION_DEFER_CEILING_SECONDS` from the run's start, then closes it. Cancelling a schedule for an ended
scope also closes the owed reports of its runs (`cancelRunsAndCloseReportObligationsOfSchedule()`).

## What a host provides

Full class names of what a host extends or calls here: `DDD\Domain\AI\Services\Conversations\Agent\AgentOwnerReportEmailsService`, `DDD\Domain\AI\Services\Conversations\AIConversationsService`, `DDD\Domain\AI\Services\Conversations\AIConversationAgentService`.

- `AgentOwnerReportEmailsService` (same path) — without it no report mail ever leaves.
- The owed-reports console command and its cron line.
- The translation keys of the template report lines, and the wording keys of `agentApplicationWording.php`.
- `reportChannel` and `initiatorType` on the runs it starts itself.

## Traps

- No mail override: `sendReportEmail()` answers false, the claim is given back and the report deferred, so every EMAIL
  run's report stays owed. The owed-reports command retries the mail on every pass for
  `REPORT_EMAIL_GIVE_UP_AFTER_SECONDS` from the run's start, then gives up and keeps the claim. Expect a day of retries
  per run and no mail.
- Treating `reportDeliveredAt` as proof of a mail: it is the claim of the platform, not the acceptance of a provider.
- The tool's description carries example situations; a host whose runs report on other things should keep that in mind
  when it reads the model's choice of outcome.
