# Stall and final text

**Reader:** package maintainer

Contents: 1. The order of the checks · 2. Provider errors come first · 3. Stall shapes · 4. The stall ladder · 5. Before a
final text is accepted · 6. Writing the final text · 7. Turns that end without a text · 8. The loop's memory between
steps · 9. Rules for a new check

The decision sites at these steps (the stall-recovery move, the turn-closing decision) belong to
`ddd-module-agents-decisions-and-guards-specialist`; the closed owner question and its forced tool-less answer step to
`ddd-module-agents-tools-specialist`. This file is the loop's side: where each check runs, what it reads, what it
writes, how often it may fire.

## 1. The order of the checks

After the model call of a step, in `runConversationLoopSteps()`:

1. a response written on a tool result the background screening removed → discard, run the step again (once per owner
   turn, only with a step left);
2. the budget closing step returned tool calls → the calls are dropped (no budget for their results);
3. provider error → fail fast;
4. stall classification (`AgentStallClassification::buildFor()`);
5. an empty response in a run whose owner report was delivered → the report is the answer
   (`AgentLoopStopReason::answered()`);
6. a stalled budget closing step → a loop signal carries the step's usage, `budgetClosingStalled()`;
7. any other stall → stall recovery (`continue` when a nudge was appended);
8. the checked stop (bounce once per chunk);
9. open tool-declared obligations (bounce once per chunk);
10. the freshness retry for owner rows that landed while the model worked (once per chunk);
11. accepted: retire a finished plan when due ([task-plan-and-long-tasks](task-plan-and-long-tasks.md) §10), the purpose's answer appendix, the budget closing step's footer;
12. `appendTurn()`.

Every bounce or retry DISCARDS the candidate text — the owner never sees answer-then-second-answer — and stamps the
step's usage on the signal it writes, so budgets and the cost sum stay complete.

## 2. Provider errors come first

`ModelResponse::$providerError` (an error body instead of a completion, or an unusable answer of the Responses API) is
checked before the stall: an empty response caused by a provider error must never be nudged as a stall.
`AIConversationOwnerReportDeliveryService::failFastOnProviderError()` writes the ERROR row and throws
`AIModelCallFailedException`; the release is `afterProviderError()` (FAILED, no retry). One exception: when the provider
refuses the carried reasoning items, `AIConversationModelCallsService::performModelCall()` switches the carry-over off for
the conversation and sends the same call once more.

## 3. Stall shapes

`AgentStallClassification::buildFor()` decides, for a response without a tool call, whether it is a stall and of which
shape. The shapes are tested in this order; a later shape fires only when no earlier one did. Every test is structural
(the response, the rows, typed facts), never a match on the wording. Terms used in the table:

- *awaiting widget*: the turn recorded an `askOwner` question, or a widget of the text or of the turn's tool and platform
  rows awaits the owner's input or ends the purpose (`AIConversation::hasCurrentTurnWidgetAwaitingTheOwnerOrEndingThePurpose()`;
  a widget class declares it with `ConversationWidget::AWAITS_OWNER_INPUT`);
- *writable block*: a closed, valid block of a widget with `ConversationWidget::IS_WRITABLE_WITHOUT_A_SKILL`;
- *one-time exemption*: the response right after a text move of the recovery or the budget closing step
  (`AgentLoopCorrectionState::$exemptProseStallOnce`).

| Shape | Fires when | Exempted by |
|---|---|---|
| EMPTY | neither a tool call nor visible prose (fenced blocks do not count as prose) | a writable block only — not `askOwner`, not an awaiting widget, not the one-time exemption |
| PROSE-AFTER-LOAD-SKILLS | prose, the turn loaded a skill or tool, no work tool ran (a refused or failed call counts as ran), work tools are offered; skills carried over from an earlier turn never fire it | an awaiting widget, a writable block, the one-time exemption |
| PRE-CALL | the prose carries the `AIConversationMessage::PRE_CALL_MESSAGE_MARKER` sentinel or a tool call leaked into the text | an awaiting widget; for a leaked call only a recorded `askOwner` question |
| DICTATED-BLOCK-MISSING | a work-tool result of the turn dictates a widget (`ToolCall::$dictatedWidgetType`), or the newest call is a `delegate` that started nothing, and the answer has no block at all | an awaiting widget, the one-time exemption, a delegation child, the nudge already spent this turn |
| DIRECT-WIDGET-MISSING | the turn start decided a direct widget and the answer lacks its closed, valid block | an awaiting widget, a work tool that ran, the one-time exemption, a delegation child, the nudge already spent |
| WIDGET-TREE-FINDINGS | the answer's composed-view block has findings that drop a node or a binding | the one-time exemption, a delegation child, the signal already sent this turn — never an awaiting widget |
| CODE-OR-FORMULA-IN-PROSE | a code fence or a formula in the text on a conversation taught the composed-view kit | an awaiting widget, the one-time exemption, the nudge already spent, an earlier answer-shape correction in the turn |

## 4. The stall ladder

`AgentStallRecoveryService::recoverStalledStep()` runs the ladder; `AIConversationMessages::countTrailingConsecutiveNudges()`
is its counter, so it is derived from rows and survives a chunk.

1. **Nudge first.** While fewer than `AgentStallRecoveryService::MAX_STALL_SIGNALS` (3) stall signals trail the turn, the
   step appends an informative `LOOP_NUDGE` signal (`AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE`, the step's usage on
   it) that names what the model holds and what went wrong, and the loop continues. A productive step in between
   resets the count — the trailing run of nudges ends.
2. **The decision site at the second stall.** With one nudge already trailing, the `stallRecovery` decision site may
   choose the move (load a capability, answer now, call a work tool) — once per owner turn, never on a turn that reads
   sub-agent results and never for an answer-shape stall. Its contract and modes:
   `ddd-module-agents-decisions-and-guards-specialist`. "Answer now" arms the one-time exemption.
3. **One forced re-drive on the last attempt.** With `MAX_STALL_SIGNALS` − 1 nudges trailing (or when the decision chose
   a work-tool call), one re-drive with `tool_choice` required over the work tools runs and its response replaces the
   stalled one; a decision-forced re-drive replaces the ladder's own for the rest of the turn. Never for an answer-shape
   stall or a turn that reads sub-agent results.
4. **Out of nudges.** With the budget spent no further signal is written: the response goes on as the step's final text
   (an empty one ends the loop as `EMPTY_FINAL_TEXT`).
5. **The give-up.** At the resume boundary, a turn that produced only loop steering while inbound is owed is released
   by `afterTurnWithoutRealProgress()`: under the attempt cap it re-fires with the count carried; over it the platform
   appends `AIConversationAgentService::STALL_GIVE_UP_REPLY` (through the run report, which names the plan state when a
   plan exists).

The answer-shape stalls (DICTATED-BLOCK-MISSING, DIRECT-WIDGET-MISSING, WIDGET-TREE-FINDINGS, CODE-OR-FORMULA-IN-PROSE)
get their own nudge text once per owner turn; WIDGET-TREE-FINDINGS writes `SIGNAL_SOURCE_WIDGET_TREE_FINDINGS` instead
of a nudge. The loop reads only the `AgentStallRecoveryOutcome`: a nudge was appended (`continue`), or the response
(replaced or not) goes on through the checks below.

## 5. Before a final text is accepted

**The checked stop** (`AgentFinalTextAcceptanceService::bounceCandidateTextIfTheCheckedStopAsks()`, the rule in
`AgentCheckedStopPolicy`): a plan touched in this turn or a work-signal turn, items still owed after the candidate text's
own evidence (`AIConversation::buildTaskPlanEvidence()` with the candidate text — the check only READS what the text
would close; the closings are written once at the turn end), and a text that hands nothing to the owner (no question, no
widget awaiting the owner or ending the purpose). Bounce with `SIGNAL_SOURCE_TASK_PLAN_OPEN_BOUNCE`, at most
`AgentCheckedStopPolicy::MAX_BOUNCES_PER_CHUNK` per chunk (`countTaskPlanOpenBouncesInCurrentChunk()`); the regenerated
text is accepted. The opener of a kickoff the owner has not answered yet is never bounced.
`AgentFinalTextClassification` gathers the facts the decision reads (no tool call, stall, recorded question, budget
closing step, autonomous purpose, widget types of the text and of the turn's tools, whether the text asks the owner,
whether a widget awaits the owner's input).

**Open tool obligations** (`AIConversationTurnSignalsService::bounceCandidateTextOnOpenToolObligations()`): a tool of the
turn reported work that is not done and no later result cleared it → bounce once per chunk with the exact obligation.

**The freshness retry** (`retryCandidateTextOnFreshOwnerInbound()`): owner rows above the loop's start mark that the loop
has not seen → the note quoting them (`SIGNAL_SOURCE_MID_TURN_OWNER_INBOUND`) is the bounce; once per chunk; never under
an autonomous purpose. A candidate carrying an owner-interaction widget is discarded too and regenerated with the
owner's words in context.

## 6. Writing the final text

`appendTurn()` writes the assistant TEXT row (`AIConversationMessage::ROLE_ASSISTANT`) with the request snapshot and the
step's usage: model citation markers stripped, product names localised, widget blocks kept exactly as the model wrote
them (the platform never removes a block). Then: an owner report written after its send call is delivered on an e-mail
run, widget offers are stamped for the plan, and a recorded `askOwner` question is rendered under the text
(`attachOwnerQuestionWidget()`). `AgentTurnOutcome::finalAnswer()` reports whether the text had content:
`AgentLoopStopReason::finalText()` maps it to `ANSWERED` or `EMPTY_FINAL_TEXT`. An answered turn closes the plan leaves
whose evidence is now in the transcript (`closePlanLeavesAtTurnEnd()`).

On an e-mail run that ends with a text and no `send_owner_report` call, one more step pinned to that tool runs when a
step is left (`armForcedOwnerReportCallIfOwed()`; `ddd-module-agents-autonomy-specialist`).

## 7. Turns that end without a text

- `wait_for_sub_agents` completed → `waitingForSubAgents()`: no text, no report, no plan closing.
- A tool result started sub-agents that resume the parent → the platform writes its own completed
  `wait_for_sub_agents` row (`appendPlatformWaitForSubAgentsRow()`), closes the evidence leaves, `parkedForDelegates()`.
- Steps, cost or a repeat abort ended the turn → the terminal ERROR row plus the template run report
  (`AgentRunTerminalReport::forLoopStopWithSavedWrites()`), which names the writes that landed.
- A refused identical write → the next step runs without tools (`AgentLoopCorrectionState::$forceToolLessAnswerStep`):
  the model writes the answer instead of repeating the call.

## 8. The loop's memory between steps

`AgentLoopCorrectionState` (one per invocation, never persisted): the owed tool-less answer step, the one-time prose
exemption, whether this step is the budget closing step, the candidate text's closings, and the stall recovery's
per-turn memory (judged once, last resort replaced, which once-per-turn nudges are spent). A resumed chunk reads the
persisted rows (nudge rows, signal rows, the working-state stamps) instead — everything that must survive a chunk is
derivable from rows.

## 9. Rules for a new check

- Put it in the order above where its input is complete, and make it a structural predicate on the response, the
  rows or a typed fact on a tool result — never a pattern over prose.
- Bound it: once per chunk or once per owner turn, counted from rows so a chunk resume does not reset it.
- Discard the candidate and carry its usage on the signal you write.
- Never let it fire on a delegation child's text (a verdict to the parent), on the budget closing step, or after a
  recorded owner question, unless that is its point.
