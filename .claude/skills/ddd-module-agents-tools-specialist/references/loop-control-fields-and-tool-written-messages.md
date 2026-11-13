# Loop-control fields and tool-written messages

**Reader:** host developer and package maintainer

Contents
- Loop-control fields: when the tool steers the loop
- The fields
- A tool that writes into the conversation
- The return contract of a widget-writing tool
- The contract every meta tool shares (package maintainer)

## Loop-control fields: when the tool steers the loop

The loop holds no knowledge of any tool. A tool that knows the loop must do something — start sub-agents, run
platform work in the background, grant its flow more steps — says so in its result, as plain public properties of
its response DTO. The loop reads them from the result's `structuredContent`
(`ToolCall::getResultStructuredContent()`).

A markdown-rendered DTO keeps them: the executor copies every non-empty field of
`MCPToolExecutorService::LOOP_CONTROL_FIELD_NAMES` (a non-empty array, an integer, `true` or a non-empty string) into
a control-only `structuredContent` next to the markdown, and adds `applied: false` when the DTO says so. Document
each such property on the DTO as "loop control — nothing for you to act on": the model sees the result, and must not
imitate the field.

## The fields

| Field | Type | What the loop does | Owner of the mechanism |
|---|---|---|---|
| `turnMinimumSteps` | positive int | raises THIS turn's step budget to at least that many steps; the largest value declared in the turn wins, stamped with the turn and persisted on the working state (`AgentCompletedToolCallFollowUpsService::recordTurnMinimumStepsFromToolResult()`). For a tool that knows how long its own flow is — declare it from the first call of the flow | loop |
| `backgroundOperations` | list of objects `{operation, toolName?, toolArguments?, skillNames?}` | the platform's own background work: one sub-agent per item whose end does not resume the parent. `operation` must be `executeTool` (`BackgroundMechanicalOperationPurpose::OPERATION_EXECUTE_TOOL`) or a name the host's `AgentBackgroundOperationsService::getSupportedOperationNames()` returns (the package default returns none) — any other item, and a `toolName` that is not letters, digits and underscores, is skipped. Read by `AIConversationPurposeBackgroundWorkService`; not started inside a sub-agent; stripped from the wire the model reads | autonomy |
| `delegationTasks` | list; each item a non-blank string, an agent-task object `{task, skill_names?, label?}`, or a tool-run object `{toolName, toolArguments?, label?}` | one sub-agent per usable item (one synthetic `delegate` row each), read by `AgentDelegationRequestFromToolResult`. A string is an agent task with the shared skills; an agent-task object without `skill_names` gets the shared skills too; an object with a non-blank `toolName` is a tool run (`toolArguments` an object or a JSON string); a blank or unreadable item is left out | autonomy |
| `delegationSkillNames` | string[] | the skills of every agent task that names none of its own | autonomy |
| `delegationTaskLabels` | string[] | the label of each item BY POSITION in `delegationTasks` (a string task has no room for one); an object's own `label` wins | autonomy |
| `delegationMaxStepsPerTurn` | positive int | the agent tasks' step budget, clamped to `RunSettings::MAX_STEPS_PER_TURN_CEILING`; absent = inherited | autonomy |
| `delegationModelName` | non-blank string | the agent tasks' model; absent = inherited | autonomy |
| `delegationNextStepWhenAllSubAgentsEnded` | non-blank string | what the parent does once all of this call's sub-agents ended, told once with the last end | autonomy |
| `delegationAwaitedConversationIds` | int[] (positive, not the own conversation) | conversations that already run become sub-agents of the same request | autonomy |
| `delegationNotifiesParentWithoutResuming` | `true` | the ends ride the parent's next genuine turn instead of resuming it | autonomy |
| `outcome` | `"incomplete"` | `incomplete` (`ToolCallDecodedResult::OUTCOME_INCOMPLETE`): the call ran and saved nothing — it proves no plan item and a retry is no identical repeat | loop |
| `applied` | bool | `false`: refused before anything was stored (a missing consent); same meaning as `outcome: incomplete` | loop |

The sub-agent fields are explained by the autonomy skill (`ddd-module-agents-autonomy-specialist`); the step budget
and the task plan by the loop skill (`ddd-module-agents-loop-specialist`). The loop runs the follow-ups of a
COMPLETED call only (`AgentCompletedToolCallFollowUpsService::runFollowUpsOfCompletedToolCall()`): step floor first,
then background work, then the requested sub-agents.

`preservedFacts` is not a DTO field: the executor fills it on the response from `AgentToolResultFactsService` (default:
none; a host's same-path subclass extracts the numbers it wants to keep), it is stored with the result and stripped
from the wire. A tool that wants a derived number kept exposes it as a DTO property.

## A tool that writes into the conversation

A result is read by the model, which may or may not repeat it. For a fact the tool knows for certain ("the job
started", "the preview was rebuilt"), the tool writes a widget or a line into the chat itself, through
`AIConversationMessagesService`. The row is visible to the owner and never sent to the model.

The tool takes `AgentToolCallProvenance` as a parameter and the service from the container (a method parameter, or
`DDDService::instance()->getService(AIConversationMessagesService::class)`), writes its widget or a notice line through
`AIConversationMessagesService::appendWidget()` or `AIConversationMessagesService::appendToolText()`, and reads the returned `ConversationWidgetDelivery`. It lets
`AgentRunClaimLostException` and `MessageIndexLockUnavailableException` through. The writers, the delivery types and
reading the owner's answer are the widgets skill's (`ddd-module-agents-widgets-specialist`).

## The return contract of a widget-writing tool

- Write only facts the tool knows — never a guess, never prose that needs interpretation.
- When the delivery `ConversationWidgetDelivery::isWritten()`, the result tells the model what the widget already shows, so it does not repeat it.
- When it is not, the result carries `$conversationWidget->buildDictationNote($delivery, $spokenInstruction)` (an
  instance method of `ConversationWidget`; null when the row was written) — the one text that makes the model show
  the block itself (screen), speak it (phone line) or hand it up (sub-agent); `$spokenInstruction` is what to say on
  the phone.
- Never build a block from an array and never write `emittedBy` yourself.
- A widget the tool writes into its own conversation does not make it a WRITE (`MCPToolEffect`), but it bars
  `MCPToolParallelSafe` (code C). A READ that writes its widget again on every call declares
  `resultIsReusedWithinTurn: false`, or a repeat in the same turn is answered from the earlier result and writes
  nothing; a WRITE that changes no account data declares `keepsEarlierReadsReusable`.

## The contract every meta tool shares (package maintainer)

A meta tool is one of the loop's own tools (`AgentMetaTools::TOOL_CLASSES`: `load_skills`, `load_tool`,
`check_delegations`, `get_task_plans`, `update_task_plan`, `send_owner_report`, `delegate`, `set_task_status`,
`askOwner`, `wait_for_sub_agents`, `message_sub_agent`, `findWidgets`). It is answered by
`AgentToolCallDispatchService`, never by the executor, and its mechanism belongs to the skill that owns it (the
loop, context, skills-and-purposes, autonomy or widgets skill). Every meta tool:

1. implements `AgentMetaTool`: `getToolName()`, `toOpenAiToolSchema()` (its own wire schema), `afterStep()` and
   `workRequest()`;
2. declares the constants `NAME` and `DECLARED_EFFECT` (an `MCPToolEffect` type, or null for neither read nor write).
   They are not interface members, but `AgentMetaTools` and `MCPToolEffectCatalog` read them directly — a class
   without them breaks the catalog; a meta read is never reused within a turn; `KEEPS_EARLIER_READS_REUSABLE` when
   its call changes no account data;
3. answers its own call — each tool with its own `answerCall(…)` signature — returning `AgentMetaToolCallAnswer` (`completed()`, `failed()`,
   `withToolLessAnswerStep()`, `withTaskPlanCallOutcome()`) — OR returns an `AgentToolCallWorkRequest` from
   `workRequest()` when the work runs through its owning service;
4. asks for loop work after the step through `afterStep()` returning an `AgentAfterStepRequest` (persist the task plan,
   send the owner report, end the turn without text), which the loop executes outside the dispatch's catch so a lost
   claim reaches the loop's single exit;
5. never touches the loop: the dispatch stores the text, marks the call, raises the flags;
6. is listed in `AgentMetaTools::TOOL_CLASSES` and `AIConversationMessage::META_TOOL_NAMES`, and in each named list of
   `AgentMetaTools` whose rule applies (repeat-guard exemptions, forced-step exclusions, compaction counts) — the
   lists differ on purpose; a meta tool is never forked and never counts as an inline write;
7. refuses under a purpose-seeded plan only through `AgentSeededPlanRefusingMetaTool::findSeededPlanRefusal()`;
8. gets its own branch in `AgentToolCallDispatchService::dispatchToolCall()` that names it (or a work-request kind that
   branch already executes: a skill or tool load, a task-plan call) — without it the call falls through to the
   executor and fails as "not found";
9. is offered: its schema is added in `AgentOfferedTools::buildFor()`, under the conditions it is offered in.
