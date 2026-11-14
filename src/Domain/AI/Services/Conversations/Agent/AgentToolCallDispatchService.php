<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallAnswer;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerGateRefusal;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentSeededPlanPurposeFacts;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentSeededPlanRefusingMetaTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentSkillLoadOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentToolCallWorkRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentArguments;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\AgentSubAgentLiveActivities;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationStatusReport;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecords;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\Skills\AISkillLoadRefusals;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalog;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalogEntry;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\FoundWidgetReach;
use DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\FoundWidgetReaches;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkillOfferRestrictions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Tool-call dispatch: ONE tool call of a model step resolved in place — the repeat
 * guards, the meta tools' answers (`check_delegations`, `get_task_plans`, `ask_owner`, `send_owner_report`), the
 * conversation-scoped read and write dedup, the "load the skill first" self-correct, and the real execution under its
 * wall-clock ceiling. The work a meta tool's call asks for ({@see AgentToolCallWorkRequest}: `load_skills`, `load_tool`,
 * the plan tools) runs here — the loads through the gates of the load path, the plan application through
 * {@see AIConversationTaskPlanRecordService}; `delegate` runs its spawn through {@see AIConversationDelegationSpawnService}
 * with the loop as the child turn runner ({@see AgentChildTurnRunnerInterface}, a parameter — an inline child runs the loop's own
 * turn), the run the loop passes in. Parallel
 * execution of a step's calls stays in {@see AgentToolCallForkJoinService}.
 */
class AgentToolCallDispatchService extends Service
{
    use AITraceLogTrait;

    /**
     * Hard wall-clock ceiling (seconds) for ONE tool execution. A tool that exceeds it is aborted with a thrown
     * timeout — caught by {@see dispatchToolCall()}'s try/catch, which records FAILED + feeds "Tool error: …" back so
     * the LOOP RECOVERS instead of the turn hanging forever. Generous enough for a legitimately slow Argus read
     * (an aggregation over many records can take ~10–30s), tight enough that a stuck external call (an Argus HTTP
     * call runs with timeout=0 → curl would otherwise wait indefinitely) can no longer pin the whole agent turn.
     */
    public const int TOOL_EXECUTION_TIMEOUT_SECONDS = MCPToolExecutionTimeout::DEFAULT_SECONDS;

    /**
     * Resolve ONE tool call in place: pick the branch (repeated-call guard / delegate / check_delegations / load_skills
     * / turn-scoped read dedup / real tool execution), write its result + COMPLETED/FAILED status back onto the
     * call's {@see ToolCall} VO, and raise the two TURN-level signals via reference. $toolCall is the call attached at
     * $callMessage->toolCall (passed explicitly — the property is nullable-typed + lazy-loaded). A throwing
     * tool / sub-loop never aborts the whole loop — it is recorded as a FAILED result fed back to the model.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     * @throws MessageIndexLockUnavailableException a row a tool emitted could not take the message-index lock
     */
    public function dispatchToolCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $conversation,
        ToolCall $toolCall,
        AIConversationMessage $callMessage,
        bool $allowThreads,
        ?string $modelNameOverride,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $asyncDelegations
    ): AgentToolCallAnswer {
        // what this call asks of the loop — raised by the branches below, never lowered
        $repeatedToolCallAbort = false;
        $awaitingDelegates = false;
        $forceToolLessAnswerStep = false;
        // the answer a meta tool gave this call — its tool-less flag joins the loop's at the end
        $metaToolCallAnswer = null;
        $toolName = $toolCall->name ?? '';
        $arguments = $toolCall->arguments;
        $toolPlatformNoteForModel = null;

        $this->aiTrace('turn.toolCall', ['conversationId' => $conversation->id ?? null, 'name' => $toolName, 'argKeys' => array_keys($arguments)]);

        // Loop-guard: count IDENTICAL tool calls (same name + same arguments → same result) — DERIVED from this
        // conversation's persisted call messages (the current call is already appended, so the fold includes it).
        // A model that re-issues the exact same call instead of using its result will otherwise burn every step.
        $repeatedCallCheck = AgentRepeatedToolCallCheck::forToolCall($toolCall, $conversation);
        $repeatCount = $repeatedCallCheck->countIdenticalCallsInTurn();

        // `check_delegations` is a STATUS POLL with no arguments → every call is byte-identical, so the generic repeat
        // guard would (and did) abort the whole FOLD turn after a few legitimate polls. A status poll must
        // NEVER turn-kill: it always re-runs the (now truthful + "answer now" steering) report and stays COMPLETED; the
        // per-turn step budget is the only backstop against a runaway poll. Exempt it from BOTH guard branches below.
        // The PLAN tools are exempt for the same reason: a weak model re-asserting an UNCHANGED
        // full-replace plan / re-sending an identical tick is byte-identical and harmless — turn-killing it would
        // abort the whole run on a tic. Shared set, never a single name. A tool declaring #[MCPToolRefreshesState]
        // (a browser capture reporting changed state on identical args) is exempt DECLARATIVELY,
        // read from its ToolDefinition rather than named here.
        // A state-refreshing tool keeps its exemption only while its identical calls SUCCEED — an error refreshes
        // nothing, so MAX identical failures end the exemption (otherwise every identical failing call stays exempt
        // and each one is a wasted step).
        // A repeated `load_skills` never ABORTS the turn: the call executes nothing (a held-back act, an already
        // loaded skill answer deterministically and cost nothing), so the abort only produced a pause report the
        // owner had to answer with "continue" — and the model mirrored that word's language afterwards. The third
        // identical call still gets the corrective and the forced tool-less step — except the first step of a turn
        // whose repeated `load_skills` meets callable tools of loaded skills (the corrective branch below): it is
        // answered with those tools and followed by ONE step that keeps its tools; the next repeated `load_skills`
        // of the turn gets the tool-less step.
        $repeatGuardVerdict = $repeatedCallCheck->verdictForRepeatGuard();

        // the work this call asks for — resolved first, because the catch arms below ask it too: a plan-tool call that
        // threw is recorded as refused (both plan tools always return a request; parsing their arguments is pure)
        $agentToolCallWorkRequest = null;
        try {
            $agentToolCallWorkRequest = AgentMetaTools::findWorkRequest($toolCall);
            if ($repeatGuardVerdict === AgentRepeatedToolCallCheck::VERDICT_ABORT) {
                // The model ignored the corrective below and is STILL re-issuing the identical call → abort the
                // loop gracefully instead of spending the remaining steps on it.
                $resultContent = 'Aborted: this exact tool call was repeated without progress.';
                $toolCall->markFailed();
                $repeatedToolCallAbort = true;
            } elseif ($repeatGuardVerdict === AgentRepeatedToolCallCheck::VERDICT_CORRECTIVE) {
                // Identical call N times → do NOT execute again; feed back a corrective so the model uses the
                // result it already has (or says what it cannot determine) instead of looping. A call whose earlier run
                // saved nothing gets the notice that says so — "the work IS done" made the model tell the owner a
                // refused goal was recorded.
                $earlierUnsavedCall = $repeatedCallCheck->findEarlierUnsavedIdenticalCallInTurn();
                // A repeated `load_skills` while tools of loaded skills are callable: the answer names those skills and
                // tools, and the next step keeps its tools. "Answer from the data you already have" and a tool-less step
                // after it leave the model only the answer that the work cannot be done, although the tool for it is
                // loaded and has not run. Once per turn: after a step that got this answer, the next repeated
                // `load_skills` gets the repeat guard's own text and the tool-less step, so the turn still ends.
                $repeatedSkillLoadResult = $toolName === LoadSkillsTool::NAME && !$repeatedCallCheck->hasRepeatGuardAnsweredASkillLoadInAnEarlierStepOfTheTurn()
                    ? LoadSkillsTool::buildResultOfRepeatedCall(
                        $repeatCount,
                        $callMessage->getLoadedCapabilities()?->getLoadedSkillNames() ?? [],
                        $this->buildCallableToolNamesOfLoadedSkills($agentRun, $conversation, $callMessage)
                    )
                    : null;
                $resultContent = $repeatedSkillLoadResult ?? ($earlierUnsavedCall !== null ? $repeatedCallCheck->buildUnsavedRepeatCorrective($earlierUnsavedCall) : sprintf(
                    'You have already called `%s` with these exact arguments %d times and the result has '
                    . 'not changed. Do NOT call it again. If the call SUCCEEDED earlier, the work IS done — nothing '
                    . 'failed and nothing is unavailable, so NEVER tell the user you lack access: the platform closes '
                    . 'the matching plan item from the record; continue with the NEXT open item. Otherwise '
                    . 'answer from the data you already have, or tell the user plainly what you cannot determine '
                    . 'and why.',
                    $toolName,
                    $repeatCount
                ));
                $toolCall->markCompleted();
                $toolCall->wasRefusedAsDuplicate = true;
                // Third identical call of a tool: the notice demands "answer from the data you already have" —
                // force the tool-less step so that answer actually happens (measured: identical load_skills /
                // read loops sailed past the write-only breaker into the repeat-guard ABORT, killing mid-flow
                // rounds the same way the write perseveration killed the endgame). Not after the `load_skills` answer
                // that names callable tools: its next move is one of those tools.
                $forceToolLessAnswerStep = $repeatedSkillLoadResult === null;
            } elseif (($seededPlanRefusal = $this->findSeededPlanRefusal($toolCall, $conversation, $allowThreads)) !== null) {
                // `delegate` and `update_task_plan` under a purpose-seeded plan: the meta tool answers its refusal
                $metaToolCallAnswer = $seededPlanRefusal;
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($agentRun->stepToolRestriction?->withholdsTool($toolName) === true) {
                // append-only loading keeps a held-back tool on the wire (the cached `tools` never
                // change per step) — its call is refused here, with the next move; like a gate refusal it never counts as
                // an identical repeat, and a plan tool's call is recorded refused (the plan is unchanged)
                $resultContent = $agentRun->stepToolRestriction->buildRefusalText($toolName);
                $toolCall->markFailed();
                $toolCall->wasRefusedByGate = true;
                // the brake's own refusal is no rejected tick — counted, it re-armed the brake on every step
                $toolCall->wasWithheldOnStep = true;
                $this->recordRefusedTaskPlanCallOutcomeUnlessDecided($toolCall, $agentToolCallWorkRequest);
                // a plan tool the brake held back: the next step answers without tools, never another `auto` step that
                // offers the same tool on the wire again
                $forceToolLessAnswerStep = $forceToolLessAnswerStep || $agentRun->stepToolRestriction->answersWithheldCallWithToolLessStep($toolName);
            } elseif ($toolName === DelegateTool::NAME && $allowThreads) {
                // ONE call = ONE sub-agent. The answer is fixed now and the call's status final (COMPLETED
                // when it started or was queued, FAILED when refused); the turn does not park — the sub-agent's end
                // arrives as its own notification and the model decides what to do meanwhile. Sync: the sub-agent ran
                // inline already; its notification follows the step.
                $resultContent = $this->delegationSpawnService()->spawnSubAgentOfModelCall($agentRun, $agentChildTurnRunner, $conversation, $toolCall, $callMessage, $transientMessageIds, $modelNameOverride, $consoleStream, $asyncDelegations);
            } elseif ($toolName === CheckDelegationsTool::NAME) {
                $metaToolCallAnswer = CheckDelegationsTool::answerCall($this->buildDelegationStatusReport($conversation, CheckDelegationsTool::readSubAgentNumberFromArguments($arguments)));
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($toolName === MessageSubAgentTool::NAME) {
                // a running sub-agent reads the message at its next step, an ended one continues with it
                $metaToolCallAnswer = $this->answerMessageSubAgentCall($agentRun, $agentChildTurnRunner, $conversation, $toolCall, $allowThreads, $modelNameOverride, $transientMessageIds, $consoleStream, $asyncDelegations);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($toolName === WaitForSubAgentsTool::NAME) {
                // a completed call ends the turn after this step (the loop reads its after-step request)
                $metaToolCallAnswer = $this->answerWaitForSubAgentsCall($conversation);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($toolName === GetTaskPlansTool::NAME) {
                $metaToolCallAnswer = GetTaskPlansTool::answerCall($arguments, $conversation);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($agentToolCallWorkRequest !== null) {
                // load_skills, load_tool, update_task_plan, set_task_status: the work the meta tool's call asks for,
                // executed through its owning service
                if ($agentToolCallWorkRequest->appliesTaskStatusUpdates()) {
                    $metaToolCallAnswer = $this->taskPlanRecordService()->applySetTaskStatusCall($conversation, $toolCall, $agentToolCallWorkRequest, $conversation->buildTaskPlanEvidenceForStatusTick());
                    $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
                } elseif ($agentToolCallWorkRequest->appliesTaskPlanUpdate()) {
                    $resultContent = $this->taskPlanRecordService()->applyUpdateTaskPlanCall($conversation, $toolCall, $agentToolCallWorkRequest);
                } else {
                    $capabilityNamesBeforeTheLoad = $this->buildLoadedCapabilityNamesOfCallAndNewestMessage($conversation, $callMessage);
                    $resultContent = $this->loadCapabilitiesOfWorkRequest($agentRun, $agentToolCallWorkRequest, $conversation, $callMessage);
                    $toolCall->markCompleted();
                    // a `load_skills` call that changed no loaded capability is no skill load for the stall check
                    $toolCall->loadSkillsCallLoadedNothing = !$agentToolCallWorkRequest->loadsTools()
                        && $capabilityNamesBeforeTheLoad === $this->buildLoadedCapabilityNamesOfCallAndNewestMessage($conversation, $callMessage);
                }
            } elseif ($toolName === AskOwnerTool::NAME) {
                $metaToolCallAnswer = AskOwnerTool::answerCall($arguments, $conversation);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($toolName === FindWidgetsTool::NAME) {
                // the widget catalog search — the skill of the best reachable match loads through the gates
                $metaToolCallAnswer = $this->answerFindWidgetsCall($agentRun, $toolCall, $conversation, $callMessage);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif ($toolName === SendOwnerReportTool::NAME) {
                $metaToolCallAnswer = SendOwnerReportTool::answerCall($toolCall, $arguments);
                $resultContent = $this->takeMetaToolCallAnswer($toolCall, $metaToolCallAnswer);
            } elseif (($repeatedFailure = $repeatedCallCheck->findRepeatedFailedRead()) !== null) {
                // An identical READ that already FAILED twice in this conversation (and
                // did not work since, and no write changed the data since) is not run again — the same arguments
                // fail the same way (an unreadable page re-read on later owner turns). One retry stays
                // allowed for transient failures; the refusal repeats the last failure so the model continues from
                // the facts on file instead of another round-trip.
                $lastFailureText = trim($repeatedFailure->lastFailureResult);
                $resultContent = sprintf(
                    'NOT executed: `%s` with these exact arguments already FAILED %d times in this conversation — '
                    . 'an identical attempt fails the same way. Last failure: %s — Continue with the facts already '
                    . 'on file (never tell the owner the system is broken); only DIFFERENT arguments can succeed.',
                    $toolName,
                    $repeatedFailure->failedCount,
                    mb_substr($lastFailureText !== '' ? $lastFailureText : '(no failure text)', 0, 300)
                );
                $toolCall->markFailed();
                $toolCall->wasRefusedAsDuplicate = true;
            } elseif (
                // A tool declaring #[MCPToolRefreshesState] reports CHANGED state on identical arguments (a site
                // outline another conversation may have edited, a build status) — the conversation-scoped read
                // dedup must never answer it from an earlier result.
                ($reusablePriorReadCall = $repeatedCallCheck->findReusablePriorRead()) !== null
            ) {
                // Turn-scoped read dedup: this exact read already ran in the CURRENT turn and no
                // write changed the data since — skip the Argus round-trip and give the model the result it already
                // has. The next owner message reads fresh: the data behind a read moves outside the conversation too.
                // A refused read that FEEDS the plan's NEXT owed write (a read before
                // the save the next gated leaf requires) must not end the turn's tool work — the forced answer step
                // below made the turn answer WITHOUT persisting what the read was for (saved only after the
                // owner's next "ok"). The plan is the source and names the tool; only the NEXT gated leaf counts,
                // not any open gate in the tree (a purposed run owes some gate until its end — "any" would switch this
                // breaker off for the whole run). The corrective is conditional: the write runs now only when the
                // owner already gave what it needs (their nod, their answer) — never a premature save.
                $currentTaskPlanForDedup = $conversation->getTaskPlan();
                $nextOwedGateToolName = $conversation->findNextOwedPlanDoneGateToolName();
                // The repeat gets the SAME result again, the notice in front: a pointer to "the
                // result above" lost the model (it re-read once, then only apologised), the result itself
                // does not. No Argus round-trip runs — the earlier call's recorded result is what comes back.
                $priorResultText = trim($reusablePriorReadCall->getResultTextHead(PHP_INT_MAX));
                // The hint names the plan's NEXT OWED ITEM (its text), not only a later tool gate: a leaf with
                // a widget gate is skipped by the gate lookup, which then names a later tool — a model that had not
                // done the skipped leaf's work read "the next gate is <that tool>", did nothing and told the owner
                // it could not go on.
                $nextOwedItemTexts = $currentTaskPlanForDedup?->items?->getOwedOpenLeafTextsByNumberPath(1) ?? [];
                $nextOwedItemHint = '';
                foreach ($nextOwedItemTexts as $nextOwedItemKey => $nextOwedItemText) {
                    $nextOwedItemHint = sprintf(
                        ' The plan\'s next owed item is %s "%s" — do what it says now.',
                        $nextOwedItemKey,
                        mb_substr(trim((string)$nextOwedItemText), 0, 220)
                    );
                    break;
                }
                $resultContent = sprintf(
                    'YOU ALREADY CALLED `%s` WITH EXACTLY THESE ARGUMENTS A MOMENT AGO — here is the same result '
                    . 'again (nothing changed it; it was not fetched anew). Act on it now; do not call it a third '
                    . 'time. Only a write that changes this data makes a re-read worthwhile.%s%s',
                    $toolName,
                    $nextOwedItemHint,
                    $nextOwedGateToolName !== null
                        ? sprintf(
                            ' Its later gate is `%s`: run that write only once the owner has given what it needs'
                            . ' (their nod, their answer) — never before they did.',
                            $nextOwedGateToolName
                        )
                        : ''
                ) . ($priorResultText !== '' ? "\n\n" . $priorResultText : '');
                $toolCall->markCompleted();
                $toolCall->wasRefusedAsDuplicate = true;
                // The model keeps EVERY tool after a repeated read: with the result in hand it
                // walks to the write the owner asked for (the forced tool-less step took the publish away).
                // A model that still loops on the same read meets the per-turn repeat guard (third identical call)
                // above; the widget-gate and all-no-op-tick breakers are untouched.
            } elseif (($identicalWriteIndex = $repeatedCallCheck->findEarlierIdenticalWriteIndex()) !== null) {
                // Conversation-scoped WRITE repeat detection: a write with byte-identical normalized arguments as an
                // earlier COMPLETED write never does new work — it is a double-fire or content perseveration. Skipping
                // the re-execution prevents the duplicate persist AND withholds the result echo (the full old content
                // coming back as the tool result is what re-anchors a perseverating model each round); the notice
                // steers toward CHANGED content instead.
                $resultContent = sprintf(
                    'NOT executed: you already persisted exactly this with `%s` earlier in this conversation '
                    . '(message %d) — repeating a write with identical arguments does no new work. Nothing failed '
                    . 'and nothing is unavailable — the work is ALREADY DONE; NEVER tell the user you lack access '
                    . 'or could not do it. If the owner '
                    . 'asked for a CHANGE, this repeat did NOT apply it: make a fresh call whose arguments contain '
                    . 'the CHANGED content. If nothing was to change, the work IS done: the platform closes the '
                    . 'matching plan item from the record; '
                    . 'continue with the NEXT open item — or ask the owner if you are missing input for it.',
                    $toolName,
                    $identicalWriteIndex
                );
                $toolCall->markCompleted();
                $toolCall->wasRefusedAsDuplicate = true;
                // The refusal says "continue with the NEXT open item" — the tools must still be there for it. The
                // tool-less step is forced only when the loop already refused this call once in the turn (the
                // perseveration the breaker exists for); the first refusal keeps the tools, also when the write it
                // repeats ran in this same turn — counting that executed write as a repeat paused the tools on the
                // first refusal, and the model, left without the tool of the next open item, told the owner it could
                // not do the work. A plan-state proxy ("nothing owner-free owed") forced the step on a model-set
                // blocked item and on a parked judgement leaf, taking the tools away from the writes the plan still owed.
                if ($repeatedCallCheck->countRefusedRepeatsOfTheSameCallInTurn() >= 1) {
                    $forceToolLessAnswerStep = true;
                }
            } elseif (($earlierUnsavedCall = $repeatedCallCheck->findEarlierUnsavedIdenticalCallInTurn()) !== null) {
                // The same arguments already ran in this turn and saved NOTHING (the value not saved, the
                // statement not recorded): the same evidence gives the same answer. Not executed again — the notice
                // quotes the earlier result and its next move (observed: ten refused repeats, each `noCache` flip
                // slipping past the byte-exact guard). The first refusal keeps the tools for that move; a second one in
                // the turn forces the tool-less answer step (the identical-write branch's rule).
                $forceToolLessAnswerStep = $repeatedCallCheck->countRefusedRepeatsOfTheSameCallInTurn() >= 1;
                $resultContent = $repeatedCallCheck->buildUnsavedRepeatCorrective($earlierUnsavedCall);
                $toolCall->markCompleted();
                $toolCall->wasRefusedAsDuplicate = true;
            } elseif (($skillNamesProvidingTool = $callMessage->findSkillNamesProvidingUnloadedTool($toolName, $conversation)) !== []) {
                // The model called a CATALOG tool whose SKILL is NOT loaded — it knows the name (from a task-plan
                // gate like `doneRequiresToolCall: getBookings`, or from prior context) but the tool's schema is not in
                // the current domain, so the executor would only answer "Tool 'X' not found in this domain" — a dead
                // end that stalled the gated write (agent AND supervisor mis-read the bare failure as a
                // bad-argument retry). Turn it into a deterministic self-correct that NAMES the skill to load first
                // (same corrective-feedback pattern as the dedup / repeat guards), so the model load_skills() it and
                // retries — and the supervisor telemetry now shows a load-the-skill gap, not a phantom arg problem.
                $resultContent = $conversation->usesConversationAppend()
                    ? $this->buildRefusalOfListedToolWithoutItsSkill($toolName, $skillNamesProvidingTool, $conversation)
                    : sprintf(
                        'Tool `%s` is not available yet — it belongs to the skill %s, which is NOT loaded. Call '
                        . '`load_skills` with %s FIRST, then call `%s` again. Do NOT retry `%s` before loading the skill '
                        . '(without the skill the tool cannot run — this is NOT an argument-shape problem).',
                        $toolName,
                        implode(' or ', array_map(static fn (string $skillName): string => "`$skillName`", $skillNamesProvidingTool)),
                        '[' . implode(', ', array_map(static fn (string $skillName): string => "'$skillName'", $skillNamesProvidingTool)) . ']',
                        $toolName,
                        $toolName
                    );
                $toolCall->markFailed();
                // "Load the skill, then call again" is the sanctioned retry — like a gate refusal it must never count
                // as an identical repeat, or the valid call after the load is swallowed by the repeat guard.
                $toolCall->wasRefusedByGate = true;
            } else {
                // Real tool execution. The ToolCall runs ITSELF — executeToolCall() delegates to {@see AgentToolCallExecutionService::executeToolCall()},
                // which resolves the executor port + records its own COMPLETED/FAILED status (the executor logs any
                // failure). Routed through the service method so the dispatch is uniform with delegate()/loadSkills and
                // the unit-test doubles can stub tool results without a live executor / loaded packages.
                $toolResult = $this->executeToolCall($toolCall, $conversation);
                $resultContent = $toolResult->content;
                $toolPlatformNoteForModel = $toolResult->platformNoteForModel;
                // An OWNER-GATE refusal (a write before the owner's confirmation, a write before the draft's nod):
                // the only correct next move is TEXT — present / ask and end the turn. Force the tool-less step like
                // the refused-write breaker; without it the model re-issued the refused write again and again.
                if ($toolResult->isError && OwnerGateRefusal::isRefusalResult($resultContent)) {
                    $forceToolLessAnswerStep = true;
                }
                // A result that ENDS the purpose (the rendered closing widget) leaves ONE correct next move:
                // one closing line. Without the forced text step a model kept working after the widget (a write
                // with its own widget under the closing one, a re-tick of that item) — the turn now closes on it.
                if (!$toolResult->isError
                    && ($this->findPurposeOfConversationTree($conversation)?->doesToolResultEndThePurpose($toolName, $resultContent) ?? false)
                ) {
                    $forceToolLessAnswerStep = true;
                }
                // Delete-retry disambiguation: a FAILED call of a DELETE tool (`#[MCPToolEffect(DELETE)]`) whose
                // identical-arguments call already COMPLETED earlier in this conversation means the target is gone BECAUSE WE DELETED IT — the
                // backend's "not found" is proof of success, not an access problem. Override the error so the
                // model never reports broken access after its own successful deletion (a stale list re-read is
                // what sends it into the retry). Trigger = the persisted call history, never the error text.
                if ($toolResult->isError
                    && !$toolCall->wasRefusedByGate
                    && !OwnerGateRefusal::isRefusalResult($resultContent)
                    && $toolCall->isDelete()
                    && $conversation->messages->buildToolCallHistory()->hasEarlierCompletedCallWithIdenticalArguments($toolCall)
                ) {
                    $resultContent = sprintf(
                        'This `%s` already COMPLETED earlier in this conversation with exactly these arguments '
                        . '— the target is gone because YOU deleted it. Treat this as SUCCESS; NEVER report '
                        . 'missing access or a failed deletion. If a list read still shows the item, that list '
                        . 'is stale — do not retry the deletion.',
                        $toolName
                    );
                    $toolCall->markCompleted();
                }
                // What this call means for the plan is said by the loop AFTER the platform looked at the open items
                // ({@see AIConversationTaskPlanRecordService::closeLeavesProvenByCompletedToolCallAndAppendPlanNote()}): said here, before that look, the tick instruction
                // named items the platform closes a moment later.
                // Image carry: stash the transient resolved bytes on the conversation, keyed by
                // tool_call_id, so the NEXT model call's wire composition can embed them (resolveToolCallImages).
                // Only for a NON-error result (an error result carries no image to the wire) that actually
                // produced images; the bytes-free reference was already persisted on the ToolCall by execute().
                if (!$toolResult->isError && $toolResult->images !== [] && ($toolCall->toolCallId ?? '') !== '') {
                    $conversation->stashTransientToolCallImages($toolCall->toolCallId, $toolResult->images);
                }
                // Eviction telemetry — the call's use on the row's loaded capabilities
                $callMessage->recordCapabilityUseOf($toolCall, $conversation);
            }
        } catch (AiBudgetExceededException $aiBudgetExceeded) {
            // The tool's own AI operation hit the budget cap (e.g. an in-tool image generation). Feed the
            // STANDARDIZED result back — the stable code first so the model recognises the condition — and steer it
            // to INFORM the user rather than retry (the cap is deterministic; every retry burns a step for nothing).
            // If the loop's next model call is also over budget, the loop-level budget catch produces the
            // user-visible reply + FAILED settle anyway.
            $resultContent = AiBudgetExceededException::ERROR_CODE
                . ': the account\'s monthly AI budget is used up — this tool cannot run. Do NOT retry this or any '
                . 'other AI-consuming tool. Tell the user plainly that the monthly AI budget is reached and that it '
                . 'resets at the start of next month.';
            $toolCall->markFailed();
            $this->recordRefusedTaskPlanCallOutcomeUnlessDecided($toolCall, $agentToolCallWorkRequest);
            $this->logToolFailure($conversation, $toolName, $arguments, $resultContent, $aiBudgetExceeded, $callMessage->aiModelName ?? null);
        } catch (AIScopeNotEligibleException $scopeNotEligible) {
            // The tool's own AI operation was refused for the scope (the agent space or account no longer permits
            // automated work): deterministic like the budget — the stable code first, and no retry of any
            // AI-consuming tool. The loop's next model call is refused too and ends the turn with its structured stop.
            $resultContent = AIScopeNotEligibleException::ERROR_CODE
                . ': automated work is not permitted for this ' . AgentApplicationWording::current()->billingEntityName
                . ' or account right now — this tool cannot run.'
                . ' Do NOT retry this or any other AI-consuming tool.';
            $toolCall->markFailed();
            $this->recordRefusedTaskPlanCallOutcomeUnlessDecided($toolCall, $agentToolCallWorkRequest);
            $this->logToolFailure($conversation, $toolName, $arguments, $resultContent, $scopeNotEligible, $callMessage->aiModelName ?? null);
        } catch (MessageIndexLockUnavailableException $indexLockUnavailable) {
            // Lever E: a row a tool emitted (a tool message, a sync delegation child's row) could
            // not take the index lock — never a FAILED tool result the turn carries on from: the loop's loud exit.
            throw $indexLockUnavailable;
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $e) {
            // A throwing tool/sub-loop must not abort the whole loop — record the failure and feed it back.
            $resultContent = 'Tool error: ' . $e->getMessage();
            $toolCall->markFailed();
            $this->recordRefusedTaskPlanCallOutcomeUnlessDecided($toolCall, $agentToolCallWorkRequest);
            $this->logToolFailure($conversation, $toolName, $arguments, $resultContent, $e, $callMessage->aiModelName ?? null);
        }

        $this->aiTrace('turn.toolResult', ['conversationId' => $conversation->id ?? null, 'name' => $toolName, 'status' => $toolCall->status]);

        // Resolve the call in place: it IS the SAME ToolCall the model emitted this turn, now carrying its result
        // (status was set by the branch above). A `delegate` call stores its fixed answer like any call.
        $toolCall->result = $resultContent;
        $toolCall->platformNoteForModel = $toolPlatformNoteForModel;
        // a meta tool's answer raises the tool-less flag when it asks for it — never lowers it
        $forceToolLessAnswerStep = $forceToolLessAnswerStep || ($metaToolCallAnswer !== null && $metaToolCallAnswer->forcesToolLessAnswerStep);
        return AgentToolCallAnswer::withFlags($repeatedToolCallAbort, $awaitingDelegates, $forceToolLessAnswerStep);
    }

    /**
     * The refusal of a call whose tool is in the append-only offer while its skill is not loaded (an epoch
     * that carries the purpose's whole reachable tool set top-level lists every tool from the start). The skills named
     * are the ones this conversation can load (its reachable catalog), else every unloaded providing skill. The valid
     * next move: load the skill — its instructions say how the tool is used — then call the tool again.
     *
     * @param string[] $skillNamesProvidingTool
     */
    public function buildRefusalOfListedToolWithoutItsSkill(string $toolName, array $skillNamesProvidingTool, AIConversation $conversation): string
    {
        $reachableSkillNames = $conversation->buildReachableSkillCatalog()->getSkillNames();
        $loadableSkillNames = array_values(array_intersect($skillNamesProvidingTool, $reachableSkillNames));
        $namedSkillNames = $loadableSkillNames !== [] ? $loadableSkillNames : $skillNamesProvidingTool;
        return sprintf(
            'Not executed: `%s` is in your tool list, but its skill %s is not loaded yet — the skill\'s instructions say when '
            . 'and how to use the tool. Call `load_skills` with %s first, then call `%s` again with the same arguments '
            . '(nothing is wrong with them).',
            $toolName,
            implode(' or ', array_map(static fn (string $skillName): string => "`$skillName`", $namedSkillNames)),
            '[' . implode(', ', array_map(static fn (string $skillName): string => "'$skillName'", array_slice($namedSkillNames, 0, 1))) . ']',
            $toolName
        );
    }

    /**
     * The tools of the loaded skills the model can call at this call's row: the row's loaded tools without the tools
     * the conversation tree's purpose blocks. What a `load_skills` answer names as "already in your tool list".
     *
     * @return string[]
     */
    protected function buildCallableToolNamesOfLoadedSkills(AgentRun $agentRun, AIConversation $conversation, AIConversationMessage $callMessage): array
    {
        $loadedToolNames = $callMessage->getLoadedCapabilities()?->getLoadedToolNames() ?? [];
        if ($loadedToolNames === []) {
            return [];
        }
        return array_values(array_diff($loadedToolNames, $conversation->findBlockedToolNamesOfConversationTree($agentRun)));
    }

    /**
     * The loaded skill and tool names of the call row and of the conversation's newest row that carries a set — what a
     * `load_skills` call can change (its own row; a sub-skill preload promotes onto the newest row). Compared before and
     * after the call to tell a load from an "Already loaded" answer.
     *
     * @return array<string, string[]>
     */
    protected function buildLoadedCapabilityNamesOfCallAndNewestMessage(AIConversation $conversation, AIConversationMessage $callMessage): array
    {
        $callMessageCapabilities = $callMessage->getLoadedCapabilities();
        $newestCapabilities = $conversation->messages->getNewestLoadedCapabilities();
        return [
            'callMessageSkills' => $callMessageCapabilities?->getLoadedSkillNames() ?? [],
            'callMessageTools' => $callMessageCapabilities?->getLoadedToolNames() ?? [],
            'newestMessageSkills' => $newestCapabilities?->getLoadedSkillNames() ?? [],
            'newestMessageTools' => $newestCapabilities?->getLoadedToolNames() ?? [],
        ];
    }

    /**
     * The work of a `load_skills` or `load_tool` call. `load_skills`: every skill the model names loads
     * (the plan's skill anchoring RELIEVES the model — it preloads the acts, it never refuses a load). The act
     * read-ahead ({@see TaskPlanItems::collectSkillNamesDueNow()}) makes a load of an act skill rare; a load on suspicion
     * costs context, never a refused call, a repeat loop or an aborted turn. The skills this call loaded may list
     * sub-skills: the decision model is asked at once, so the chat model does not spend a second load on the sub-skill
     * its request falls into. `load_tool`: LAZY promotion of the loaded skills' "available" tools —
     * their full schemas enter the `tools` array next turn; a tool of a not-yet-loaded SUB-SKILL of a loaded root is
     * loadable the same way, its sub-skill loaded first through the gates of `load_skills`, so the model reaches the
     * tool in ONE call without knowing the skill tree. Returns the result text.
     */
    protected function loadCapabilitiesOfWorkRequest(
        AgentRun $agentRun,
        AgentToolCallWorkRequest $agentToolCallWorkRequest,
        AIConversation $conversation,
        AIConversationMessage $callMessage
    ): string {
        if ($agentToolCallWorkRequest->loadsTools()) {
            return $this->loadToolsIncludingSubSkillTools($agentRun, $agentToolCallWorkRequest->toolArguments, $conversation, $callMessage);
        }
        $toolArguments = $agentToolCallWorkRequest->toolArguments;
        $agentSkillLoadOutcome = $this->loadSkillsThroughGates($agentRun, $toolArguments, $conversation, $callMessage);
        // a call that named no skill hands the decision model the names it sent
        $skillNamesForTheSubSkillPreload = $agentSkillLoadOutcome->admittedSkillNames
            ?? (is_array($toolArguments['skill_names'] ?? null) ? $toolArguments['skill_names'] : []);
        /** @var AgentSkillPreloadDecisionsService $agentSkillPreloadDecisionsService */
        $agentSkillPreloadDecisionsService = DDDService::instance()->getService(AgentSkillPreloadDecisionsService::class);
        return $agentSkillLoadOutcome->resultText . $agentSkillPreloadDecisionsService->preloadSubSkillsOfSkillsLoadedByTheChatModel(
            $conversation,
            array_values(array_map('strval', $skillNamesForTheSubSkillPreload)),
            $agentRun
        );
    }

    /**
     * A `load_skills` request through every gate of the load path — entitlement, account rights, vision, purpose and
     * initiator binding, the purpose rail, the sub-skill gate ({@see AISkillLoadRefusals}) — then the load itself.
     * Answers the tool result (what was loaded and, for every refused name, why and what to do instead) and the names
     * that passed the gates. The call's own arguments are never narrowed. The restrictions read the run's purpose memo.
     *
     * @param array<array-key, mixed> $toolArguments
     */
    protected function loadSkillsThroughGates(AgentRun $agentRun, array $toolArguments, AIConversation $conversation, AIConversationMessage $callMessage): AgentSkillLoadOutcome
    {
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = DDDService::instance()->getService(AISkillsService::class);
        $requestedSkillNames = is_array($toolArguments['skill_names'] ?? null) ? $toolArguments['skill_names'] : [];
        $loadedSkillNames = $callMessage->getLoadedCapabilities()?->getLoadedSkillNames() ?? [];
        // under append-only loading the enum offers every sub-skill — a sub-skill asked for without its root
        // loads the root with it in the same call (structural, no refusal)
        if ($conversation->usesConversationAppend()) {
            $requestedSkillNames = $this->addRootsOfUnrootedSubSkills($aiSkillsService, $requestedSkillNames, $loadedSkillNames);
            $toolArguments['skill_names'] = $requestedSkillNames;
        }
        $requestedAISkills = $aiSkillsService->resolveRequestedSkills($requestedSkillNames, $loadedSkillNames);
        if (!$requestedAISkills->namesAnySkill()) {
            // a call that names nothing loads nothing; answering it like a load reads as success and is repeated. While
            // tools of loaded skills are callable the answer names them: the next move is one of them, not a name
            return AgentSkillLoadOutcome::withoutSkillNames(LoadSkillsTool::buildResultOfCallNamingNoSkill(
                $loadedSkillNames,
                $this->buildCallableToolNamesOfLoadedSkills($agentRun, $conversation, $callMessage)
            ));
        }
        $aISkillLoadRefusals = AISkillLoadRefusals::buildFor($requestedAISkills, $conversation->buildAISkillOfferRestrictions($agentRun));
        $toolArguments['skill_names'] = $aISkillLoadRefusals->loadableSkillNames;
        $refusalSentences = $aISkillLoadRefusals->renderCorrectiveSentences();
        return AgentSkillLoadOutcome::afterTheGates(
            $callMessage->loadSkillsFromToolArguments($toolArguments, $conversation->findBlockedToolNamesOfConversationTree($agentRun)) . $refusalSentences,
            $aISkillLoadRefusals->loadableSkillNames,
            $refusalSentences
        );
    }

    /**
     * `$requestedSkillNames` with, before each requested sub-skill whose root is neither loaded nor requested, that root
     * (the catalog skill that lists it) — the append-only load of a sub-skill.
     *
     * @param array<int, mixed> $requestedSkillNames
     * @param string[] $loadedSkillNames
     * @return string[]
     */
    protected function addRootsOfUnrootedSubSkills(AISkillsService $aiSkillsService, array $requestedSkillNames, array $loadedSkillNames): array
    {
        $requestedSkillNames = array_values(array_map('strval', $requestedSkillNames));
        $skillNamesWithRoots = [];
        foreach ($requestedSkillNames as $requestedSkillName) {
            $requestedSkill = $aiSkillsService->getAISkillByName($requestedSkillName);
            if ($requestedSkill !== null && $requestedSkill->isSubSkill) {
                foreach ($aiSkillsService->getCatalog()->getElements() as $candidateRootSkill) {
                    if (in_array($requestedSkillName, $candidateRootSkill->subSkillNames, true)) {
                        $rootSkillName = (string)$candidateRootSkill->name;
                        if (!in_array($rootSkillName, $loadedSkillNames, true) && !in_array($rootSkillName, $requestedSkillNames, true) && !in_array($rootSkillName, $skillNamesWithRoots, true)) {
                            $skillNamesWithRoots[] = $rootSkillName;
                        }
                        break;
                    }
                }
            }
            if (!in_array($requestedSkillName, $skillNamesWithRoots, true)) {
                $skillNamesWithRoots[] = $requestedSkillName;
            }
        }
        return $skillNamesWithRoots;
    }

    /**
     * A `load_tool` request: tools of loaded skills are promoted as before; a tool of a not-yet-loaded sub-skill
     * loads its sub-skill first ({@see loadSkillsThroughGates()}), then the tool. The result names the sub-skill that
     * came with the tool, because its instructions are new in the context.
     *
     * @param array<string, mixed> $toolArguments
     */
    protected function loadToolsIncludingSubSkillTools(AgentRun $agentRun, array $toolArguments, AIConversation $conversation, AIConversationMessage $callMessage): string
    {
        $requestedToolNames = array_map('strval', is_array($toolArguments['tool_names'] ?? null) ? $toolArguments['tool_names'] : []);
        $loadableSubSkillTools = $conversation->buildLoadableSubSkillTools(
            $conversation->buildOfferedSkillCatalog($callMessage, [], $agentRun),
            $callMessage->getLoadedCapabilities()
        );
        $subSkillNamesToLoad = [];
        foreach ($requestedToolNames as $requestedToolName) {
            $loadableSubSkillTool = $loadableSubSkillTools->getToolNamed($requestedToolName);
            if ($loadableSubSkillTool !== null) {
                $subSkillNamesToLoad[] = $loadableSubSkillTool->subSkillName;
            }
        }
        $subSkillNamesToLoad = array_values(array_unique($subSkillNamesToLoad));
        if ($subSkillNamesToLoad === []) {
            return $callMessage->loadToolsFromToolArguments($toolArguments);
        }
        $agentSkillLoadOutcome = $this->loadSkillsThroughGates($agentRun, ['skill_names' => $subSkillNamesToLoad], $conversation, $callMessage);
        $loadedSubSkillNames = array_map('strval', $agentSkillLoadOutcome->admittedSkillNames ?? $subSkillNamesToLoad);
        // the tools of the loaded skills are promoted whatever happened to the sub-skills
        $loadToolsResult = $callMessage->loadToolsFromToolArguments($toolArguments);
        // why a sub-skill was refused stays in the result: the gates' sentences, as the load path rendered them
        $refusalSentences = $agentSkillLoadOutcome->refusalSentences;
        if ($loadedSubSkillNames === []) {
            return $loadToolsResult . $refusalSentences;
        }
        $residentToolNames = $callMessage->getLoadedCapabilities()?->getLoadedToolNames() ?? [];
        $loadedRequestedToolNames = array_values(array_intersect($requestedToolNames, $residentToolNames));
        return 'Loaded the sub-skill ' . implode(', ', $loadedSubSkillNames) . ': its instructions are in your context now and '
            . 'its tools are in your tool list from the next step'
            . ($loadedRequestedToolNames !== [] ? ' (' . implode(', ', $loadedRequestedToolNames) . ')' : '')
            . '. Read the instructions, then call the tool.' . $refusalSentences;
    }

    /**
     * The `findWidgets` answer: the query searched in the complete widget catalog, then — in rank order —
     * how each match is reached here ({@see buildFoundWidgetReach()}). The first match that is usable now (written
     * directly, by a meta tool, by a loaded skill) ends the loading; the first match before it whose skill passes the
     * gates and that the query matches strongly enough
     * ({@see \DDD\Domain\AI\Entities\Conversations\Widgets\Catalog\ConversationWidgetCatalogEntry::isMatchedStronglyEnoughToLoadItsSkill()})
     * gets that skill loaded through {@see loadSkillsThroughGates()} (root before sub-skill), the later matches are
     * judged against the skills loaded since. A match whose skills are gated away is reported unavailable, never loaded;
     * a match the query names exactly decides the loading alone.
     */
    protected function answerFindWidgetsCall(AgentRun $agentRun, ToolCall $toolCall, AIConversation $conversation, AIConversationMessage $callMessage): AgentMetaToolCallAnswer
    {
        $query = FindWidgetsTool::readQueryFromArguments($toolCall->arguments);
        if ($query === '') {
            return FindWidgetsTool::answerWithoutQuery();
        }
        if ($conversation->isOwnerOnThePhoneLine()) {
            return FindWidgetsTool::answerOnThePhoneLine();
        }
        $conversationWidgetCatalog = ConversationWidgetCatalog::getBuiltCatalog();
        $matchingEntries = $conversationWidgetCatalog->findEntriesMatchingQuery($query, FindWidgetsTool::MAX_LISTED_WIDGETS);
        if ($matchingEntries->getElements() === []) {
            return FindWidgetsTool::answerWithoutMatch($query, $conversationWidgetCatalog->countEntries());
        }
        // the skills this conversation may load a widget's skill from — filtered like the widget-triggered preload
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = DDDService::instance()->getService(AISkillsService::class);
        $conversationSkillCatalog = $aiSkillsService->getCatalog()
            ->filterByPurposeName($conversation->purposeName ?? null)
            ->filterByInitiatorType($conversation->initiatorType ?? null);
        $aISkillOfferRestrictions = $conversation->buildAISkillOfferRestrictions($agentRun);
        $foundWidgetReaches = new FoundWidgetReaches();
        $loadingIsDecided = false;
        foreach ($matchingEntries->getElements() as $matchingEntry) {
            $loadedSkillNames = $callMessage->getLoadedCapabilities()?->getLoadedSkillNames() ?? [];
            $foundWidgetReach = $this->buildFoundWidgetReach($matchingEntry, $conversationSkillCatalog, $aISkillOfferRestrictions, $loadedSkillNames);
            if (!$loadingIsDecided && $foundWidgetReach->isUsableNow()) {
                $loadingIsDecided = true;
            } elseif (!$loadingIsDecided && $foundWidgetReach->type === FoundWidgetReach::TYPE_SKILL_TO_LOAD
                && $matchingEntry->isMatchedStronglyEnoughToLoadItsSkill($query)
            ) {
                $loadingIsDecided = true;
                $foundWidgetReach = $this->loadSkillsOfFoundWidget($agentRun, $foundWidgetReach, $conversation, $callMessage);
                if ($foundWidgetReach->type === FoundWidgetReach::TYPE_SKILL_LOADED_NOW) {
                    // the call is a skill load: the stall discriminator and the load counters read it from the call
                    $toolCall->skillNamesLoadedByTheCall = $foundWidgetReach->skillNames;
                }
            }
            $foundWidgetReaches->add($foundWidgetReach);
            // a query that names a widget exactly asks for that widget alone: no other match's skill is loaded for it
            if ($matchingEntry->isNamedExactlyBy($query)) {
                $loadingIsDecided = true;
            }
        }
        return FindWidgetsTool::answerCall($query, $conversationWidgetCatalog->countEntries(), $matchingEntries, $foundWidgetReaches);
    }

    /**
     * How the model reaches `$matchingEntry` here, without loading anything: directly, through a meta tool, through a
     * loaded providing skill, through the first providing skill whose load (root before sub-skill) passes every gate of
     * the load path, or not at all — then with the gates' sentences of the first providing skill.
     *
     * @param string[] $loadedSkillNames
     */
    protected function buildFoundWidgetReach(ConversationWidgetCatalogEntry $matchingEntry, AISkills $conversationSkillCatalog, AISkillOfferRestrictions $aISkillOfferRestrictions, array $loadedSkillNames): FoundWidgetReach
    {
        if ($matchingEntry->isWritableWithoutASkill) {
            return FoundWidgetReach::writeDirectly($matchingEntry->widgetType);
        }
        if ($matchingEntry->writingMetaToolNames !== []) {
            return FoundWidgetReach::throughMetaTool($matchingEntry->widgetType, $matchingEntry->writingMetaToolNames[0]);
        }
        $loadedProvidingSkillNames = array_values(array_intersect($matchingEntry->providingSkillNames, $loadedSkillNames));
        if ($loadedProvidingSkillNames !== []) {
            return FoundWidgetReach::throughSkills(FoundWidgetReach::TYPE_SKILL_ALREADY_LOADED, $matchingEntry->widgetType, [$loadedProvidingSkillNames[0]]);
        }
        if ($matchingEntry->providingSkillNames === []) {
            return FoundWidgetReach::withoutATeachingSkill($matchingEntry->widgetType);
        }
        /** @var AISkillsService $aiSkillsService */
        $aiSkillsService = DDDService::instance()->getService(AISkillsService::class);
        $firstUnavailableReason = '';
        // root skills before sub-skills, each group by name (the entry holds them sorted by name)
        $providingSkills = [];
        foreach ($matchingEntry->providingSkillNames as $providingSkillName) {
            $providingSkill = $conversationSkillCatalog->findByName($providingSkillName);
            if ($providingSkill === null) {
                $firstUnavailableReason = $firstUnavailableReason !== '' ? $firstUnavailableReason : 'its skill ' . $providingSkillName . ' is not offered in this conversation.';
                continue;
            }
            $providingSkills[] = $providingSkill;
        }
        usort($providingSkills, static fn (AISkill $left, AISkill $right): int => [$left->isSubSkill, $left->name] <=> [$right->isSubSkill, $right->name]);
        foreach ($providingSkills as $providingSkill) {
            $skillNamesToLoad = FoundWidgetReach::buildSkillNamesToLoad($providingSkill, $conversationSkillCatalog, $loadedSkillNames);
            if ($skillNamesToLoad === null) {
                continue;
            }
            $aISkillLoadRefusals = AISkillLoadRefusals::buildFor($aiSkillsService->resolveRequestedSkills($skillNamesToLoad, $loadedSkillNames), $aISkillOfferRestrictions);
            if (array_diff($skillNamesToLoad, $aISkillLoadRefusals->loadableSkillNames) === []) {
                return FoundWidgetReach::throughSkills(FoundWidgetReach::TYPE_SKILL_TO_LOAD, $matchingEntry->widgetType, $skillNamesToLoad);
            }
            $firstUnavailableReason = $firstUnavailableReason !== '' ? $firstUnavailableReason : $aISkillLoadRefusals->renderCorrectiveSentences();
        }
        return FoundWidgetReach::unavailable($matchingEntry->widgetType, $matchingEntry->providingSkillNames, $firstUnavailableReason);
    }

    /**
     * The load of a found widget's skill: the names (root before sub-skill) through {@see loadSkillsThroughGates()} — the
     * same gates as a `load_skills` call. Loaded now when the providing skill (the last name) passed; unavailable with
     * the gates' sentences otherwise.
     */
    protected function loadSkillsOfFoundWidget(AgentRun $agentRun, FoundWidgetReach $foundWidgetReach, AIConversation $conversation, AIConversationMessage $callMessage): FoundWidgetReach
    {
        $agentSkillLoadOutcome = $this->loadSkillsThroughGates($agentRun, ['skill_names' => $foundWidgetReach->skillNames], $conversation, $callMessage);
        $admittedSkillNames = $agentSkillLoadOutcome->admittedSkillNames ?? [];
        $providingSkillName = $foundWidgetReach->skillNames[count($foundWidgetReach->skillNames) - 1] ?? '';
        if (in_array($providingSkillName, $admittedSkillNames, true)) {
            return FoundWidgetReach::throughSkills(FoundWidgetReach::TYPE_SKILL_LOADED_NOW, $foundWidgetReach->widgetType, $foundWidgetReach->skillNames);
        }
        return FoundWidgetReach::unavailable($foundWidgetReach->widgetType, $foundWidgetReach->skillNames, $agentSkillLoadOutcome->refusalSentences);
    }

    /**
     * The refusal of a call whose meta tool refuses under a purpose-seeded task plan ({@see AgentSeededPlanRefusingMetaTool});
     * null for every other call. The purpose and the plan are read only for such a tool.
     */
    protected function findSeededPlanRefusal(ToolCall $toolCall, AIConversation $conversation, bool $allowThreads): ?AgentMetaToolCallAnswer
    {
        $seededPlanRefusingToolClass = AgentMetaTools::findSeededPlanRefusingToolClass($toolCall);
        if ($seededPlanRefusingToolClass === null) {
            return null;
        }
        return $seededPlanRefusingToolClass::findSeededPlanRefusal($this->buildSeededPlanPurposeFacts($conversation, $allowThreads));
    }

    /** What the seeded-plan refusals read of $conversation; the plan facts only when its purpose seeds the plan. */
    protected function buildSeededPlanPurposeFacts(AIConversation $conversation, bool $allowThreads): AgentSeededPlanPurposeFacts
    {
        if (!$this->purposeRunsService()->conversationPurposeSeedsItsTaskPlan($conversation)) {
            return AgentSeededPlanPurposeFacts::withoutSeededPlan($allowThreads);
        }
        return AgentSeededPlanPurposeFacts::withSeededPlan($conversation->getTaskPlan() !== null, $conversation->isNextPlanItemInThePurposesClosingAct(), $allowThreads);
    }

    /**
     * A plan-tool call that threw is `refused` — unless the application already recorded its outcome
     * before the throw: a plan that was replaced or ticked in memory is persisted after the dispatch all the same. A
     * plan-tool call is one whose work request applies a call to the plan.
     */
    protected function recordRefusedTaskPlanCallOutcomeUnlessDecided(ToolCall $toolCall, ?AgentToolCallWorkRequest $agentToolCallWorkRequest): void
    {
        if ($agentToolCallWorkRequest?->appliesTaskPlanCall() ?? false) {
            $toolCall->taskPlanCallOutcome ??= ToolCall::TASK_PLAN_CALL_OUTCOME_REFUSED;
        }
    }

    /**
     * The `message_sub_agent` answer: the call's arguments read and checked (a number and a message), a
     * sub-agent conversation refused (it has no sub-agents — the tool is never offered to it), the same message to the
     * same sub-agent earlier in this turn refused (the repeat check over the turn's calls), then the spawn's delivery or
     * continuation ({@see AIConversationDelegationSpawnService::messageSubAgent()}).
     *
     * @throws AgentRunClaimLostException
     * @throws MessageIndexLockUnavailableException
     * @throws Throwable
     */
    protected function answerMessageSubAgentCall(
        AgentRun $agentRun,
        AgentChildTurnRunnerInterface $agentChildTurnRunner,
        AIConversation $conversation,
        ToolCall $toolCall,
        bool $allowThreads,
        ?string $modelNameOverride,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $asyncDelegations
    ): AgentMetaToolCallAnswer {
        if (!$allowThreads || $conversation->isDelegation()) {
            return AgentMetaToolCallAnswer::failed('Not sent: this conversation is itself a sub-agent — it has no sub-agents to message.');
        }
        $messageSubAgentArguments = MessageSubAgentArguments::fromToolCallArguments($toolCall->arguments);
        $argumentsCorrective = $messageSubAgentArguments->buildArgumentsCorrective();
        if ($argumentsCorrective !== null) {
            return AgentMetaToolCallAnswer::failed($argumentsCorrective);
        }
        if (MessageSubAgentTool::wasSentEarlierInTurn($toolCall, $messageSubAgentArguments, $conversation->messages->buildCurrentTurn()->buildToolCallHistory()->getToolCallRows())) {
            return MessageSubAgentTool::answerRepeatedInTurn((int)$messageSubAgentArguments->subAgentNumber);
        }
        return $this->delegationSpawnService()->messageSubAgent(
            $agentRun,
            $agentChildTurnRunner,
            $conversation,
            $messageSubAgentArguments,
            $transientMessageIds,
            $modelNameOverride,
            $consoleStream,
            $asyncDelegations
        );
    }

    /**
     * The `wait_for_sub_agents` answer: the gatherer of the dispatch — whether notifications alone opened
     * this turn, an owner row that landed while it ran (the freshness probe), whether a sub-agent whose end resumes the
     * conversation still runs (the child rows), the unfinished records for their numbers — then the tool's pure answer. No poll: the call ends the turn; the next run end's notification resumes it.
     */
    protected function answerWaitForSubAgentsCall(AIConversation $conversation): AgentMetaToolCallAnswer
    {
        $turnOpenedBySubAgentNotificationsOnly = !$conversation->isDelegation()
            && $conversation->messages->buildCurrentTurn()->isOpenedBySubAgentNotificationsOnly();
        $hasUnfinishedSubAgentRun = false;
        $unfinishedSubAgentRecords = new DelegationSubAgentRecords();
        $freshOwnerInboundNoteText = null;
        if ($turnOpenedBySubAgentNotificationsOnly && isset($conversation->id)) {
            // an owner row that landed while this turn ran (its resume met the held claim) is in
            // no loaded set — read the persisted rows above the turn's newest initiator the loop does not hold
            /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
            $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
            $agentFreshOwnerInbound = $aIConversationTurnSignalsService->probeFreshOwnerInboundBeforeFinalText($conversation, $conversation->messages->getNewestInitiatorMessageIndex() ?? -1);
            if ($agentFreshOwnerInbound->hasFreshOwnerInbound() && $agentFreshOwnerInbound->freshMessages !== null) {
                $freshOwnerInboundNoteText = AIConversationMessage::buildMidTurnOwnerInboundNoteText($agentFreshOwnerInbound->freshMessages, $agentFreshOwnerInbound->ownerInboundIndexes);
            }
            /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
            $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
            $hasUnfinishedSubAgentRun = $aIConversationRunClaimsService->hasUnfinishedSubAgentRunThatResumesParent($conversation);
            $subAgentRecords = $this->findSubAgentRecordsOfConversation($conversation);
            $unfinishedSubAgentRecords = $subAgentRecords->getUnfinished();
            $declaredNextStep = $subAgentRecords->findNewestDeclaredNextStep();
        }
        return WaitForSubAgentsTool::answerCall($turnOpenedBySubAgentNotificationsOnly, $hasUnfinishedSubAgentRun, $unfinishedSubAgentRecords, $freshOwnerInboundNoteText, $declaredNextStep ?? null);
    }

    /**
     * The `check_delegations` report: the gatherer of the dispatch — the sub-agent records, the live look
     * into the running children, then the pure builder.
     */
    protected function buildDelegationStatusReport(AIConversation $conversation, ?int $subAgentNumber): DelegationStatusReport
    {
        $delegationSubAgentRecords = $this->findSubAgentRecordsOfConversation($conversation);
        $agentSubAgentLiveActivities = new AgentSubAgentLiveActivities();
        if (isset($conversation->id)) {
            /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
            $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
            $agentSubAgentLiveActivities = $aIConversationRunClaimsService->findSubAgentLiveActivities($delegationSubAgentRecords);
        }
        return DelegationStatusReport::buildFor($delegationSubAgentRecords, $agentSubAgentLiveActivities, new DateTime(), $subAgentNumber);
    }

    /**
     * The sub-agent records of the conversation: read from the rows for a persisted conversation (past the entity
     * registry — the children and the notifier wrote them), from its loaded rows otherwise.
     */
    protected function findSubAgentRecordsOfConversation(AIConversation $conversation): DelegationSubAgentRecords
    {
        if (!isset($conversation->id)) {
            return $this->buildSubAgentRecordsOfLoadedRows($conversation);
        }
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService->findSubAgentRecordsOfConversation($conversation);
    }

    /** The sub-agent records of the conversation's loaded `delegate` rows (legacy rows read as legacy records). */
    protected function buildSubAgentRecordsOfLoadedRows(AIConversation $conversation): DelegationSubAgentRecords
    {
        $loadedRecords = new DelegationSubAgentRecords();
        foreach ($conversation->messages->buildToolCallHistory()->getDelegateCallRows() as $delegateCallRow) {
            $loadedDelegationResults = isset($delegateCallRow->delegationResults) ? $delegateCallRow->delegationResults : new DelegationResults();
            foreach ($loadedDelegationResults->buildSubAgentRecords($delegateCallRow)->getElements() as $loadedRecord) {
                $loadedRecords->add($loadedRecord);
            }
        }
        return $loadedRecords;
    }

    /** Delegation spawn — resolved inline (DI rule); a test double replaces it. */
    protected function delegationSpawnService(): AIConversationDelegationSpawnService
    {
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        return $aIConversationDelegationSpawnService;
    }

    protected function toolCallExecutionService(): AgentToolCallExecutionService
    {
        /** @var AgentToolCallExecutionService $agentToolCallExecutionService */
        $agentToolCallExecutionService = DDDService::instance()->getService(AgentToolCallExecutionService::class);
        return $agentToolCallExecutionService;
    }

    /**
     * The "real tool execution" branch of {@see dispatchToolCall()}: runs the call through
     * {@see AgentToolCallExecutionService::executeToolCall()} (which records its COMPLETED/FAILED status). A one-line
     * indirection so the dispatch is uniform with the other service-method branches (delegate / loadSkills) AND the
     * unit-test doubles have a single point to return canned results without a live executor or loaded packages.
     *
     * BOUNDED by {@see TOOL_EXECUTION_TIMEOUT_SECONDS}: in a worker/CLI context (pcntl available) a SIGALRM aborts a
     * tool that runs too long — the thrown timeout propagates to dispatchToolCall()'s catch, which turns it into a
     * FAILED tool result fed back to the model, so the loop recovers. Without pcntl (e.g. FPM) the call runs unbounded,
     * as before — the hang only manifests on the async-worker turn path, where pcntl IS available.
     */
    protected function executeToolCall(ToolCall $toolCall, AIConversation $conversation): ToolResult
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_alarm')) {
            return $this->toolCallExecutionService()->executeToolCall($toolCall, $conversation);
        }
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousAlarmHandler = pcntl_signal_get_handler(SIGALRM);
        $toolName = $toolCall->name ?? '?';
        // A tool with an honest remote duration (the Argus rebuild) declares its own ceiling — #[MCPToolExecutionTimeout];
        // aborting it mid-build leaves the build running at the executor and the retry collides with it.
        $timeoutSeconds = $this->toolExecutionTimeoutSeconds((string)($toolCall->name ?? ''), $conversation);
        pcntl_signal(SIGALRM, static function () use ($toolName, $timeoutSeconds): void {
            throw new AgentToolExecutionTimeoutException($toolName, $timeoutSeconds);
        });
        pcntl_alarm($timeoutSeconds);
        try {
            return $this->toolCallExecutionService()->executeToolCall($toolCall, $conversation);
        } finally {
            pcntl_alarm(0); // clear the pending alarm
            pcntl_signal(SIGALRM, $previousAlarmHandler); // pcntl_signal_get_handler returns callable|int — always restorable
            pcntl_async_signals($previousAsyncSignals);
        }
    }

    /**
     * The execution ceiling for $toolName: its own #[MCPToolExecutionTimeout] when the ToolDefinition in the
     * conversation's loaded packages declares one, else {@see TOOL_EXECUTION_TIMEOUT_SECONDS}.
     */
    public function toolExecutionTimeoutSeconds(string $toolName, AIConversation $conversation): int
    {
        if ($toolName === '') {
            return self::TOOL_EXECUTION_TIMEOUT_SECONDS;
        }
        try {
            $toolDefinition = $conversation->buildLoadedToolDefinitions()->getByName($toolName);
        } catch (Throwable) {
            return self::TOOL_EXECUTION_TIMEOUT_SECONDS;
        }
        $declared = $toolDefinition?->executionTimeoutSeconds;
        return $declared !== null && $declared > 0 ? $declared : self::TOOL_EXECUTION_TIMEOUT_SECONDS;
    }

    /**
     * Take a meta tool's answer onto its call: the call is marked COMPLETED or FAILED, the plan outcome it carries is
     * recorded on the call; the text the model reads is returned. The answer's tool-less flag is read by
     * {@see dispatchToolCall()} from the answer itself.
     */
    protected function takeMetaToolCallAnswer(ToolCall $toolCall, AgentMetaToolCallAnswer $metaToolCallAnswer): string
    {
        if ($metaToolCallAnswer->failed) {
            $toolCall->markFailed();
        } else {
            $toolCall->markCompleted();
        }
        if ($metaToolCallAnswer->taskPlanCallOutcome !== null) {
            $toolCall->taskPlanCallOutcome = $metaToolCallAnswer->taskPlanCallOutcome;
        }
        return $metaToolCallAnswer->text;
    }

    /**
     * Append a LOOP-LEVEL failure to the tool-failure log with full detail (the failing step, its args, error, exception
     * class + stack) so the agent loop is debuggable across interactive / eval / production runs — independent of
     * whether the conversation trace is persisted. Tool-EXECUTION failures are logged by the executor itself
     * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService::logToolFailure()}, the universal tool chokepoint); this
     * method covers the two failures that never reach the executor: an orchestration meta-tool (delegate / load_skills /
     * check_delegations) that THROWS in the dispatch catch, and the persistMessage concurrent-drive backstop. It writes
     * through the executor's log, so both land in the one directory the application names
     * (`MCPToolExecutorService::getToolFailureLogDirectory()`; none = no file). Best-effort: a logging failure must never
     * break the agent loop.
     *
     * @param array<string, mixed> $arguments
     */
    public function logToolFailure(
        AIConversation $conversation,
        string $toolName,
        array $arguments,
        string $error,
        ?Throwable $exception,
        ?string $modelName
    ): void {
        try {
            /** @var MCPToolExecutorService $mCPToolExecutorService */
            $mCPToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
            $mCPToolExecutorService->logToolFailure($toolName, $arguments, $error, $exception, [
                'conversationId' => $conversation->id ?? null,
                'isDelegation' => $conversation->isDelegation(),
                'model' => $modelName,
            ]);
        } catch (Throwable) {
            // best-effort: never break the agent loop on a logging error.
        }
    }

    /**
     * The purpose of the conversation's delegation tree (its own, else its root's) — a delegation child of a purposed
     * conversation calls the purpose's tools too.
     */
    protected function findPurposeOfConversationTree(AIConversation $conversation): ?AIConversationPurpose
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        return $aIConversationPurposesService->findPurposeOfConversationTree($conversation);
    }

    protected function purposeRunsService(): AIConversationPurposeRunsService
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return $aIConversationPurposeRunsService;
    }

    protected function taskPlanRecordService(): AIConversationTaskPlanRecordService
    {
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = DDDService::instance()->getService(AIConversationTaskPlanRecordService::class);
        return $aIConversationTaskPlanRecordService;
    }
}
