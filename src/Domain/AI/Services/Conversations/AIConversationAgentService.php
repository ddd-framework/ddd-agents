<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Listeners\Conversations\AIConversationTurnStartListener;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolChoice;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStepToolRestriction;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopCorrectionState;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentFinalTextClassification;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallFork;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveScope;
use DDD\Domain\AI\Entities\Conversations\AIConversationDriveRequest;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\EntryPoints\AIConversationEntryPointStart;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Conversations\AgentToolCallForkJoinService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentStallRecoveryService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentPlanDynamicsDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartDecisionBatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentFinalTextAcceptanceService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTaskPlanRecordService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationOwnerReportDeliveryService;
use DDD\Domain\AI\Services\Conversations\Agent\SupervisorCheckpointRunsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnEndFollowUpsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentCompletedToolCallFollowUpsService;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopStopReason;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationResultsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationNotificationsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeBackgroundWorkService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationToolRunService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentModelCallRunnerInterface;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunTerminalReport;
use DDD\Domain\AI\Entities\Conversations\A2A\AIConversationTaskPushNotificationConfigMemo;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunRelease;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkerJobReceipt;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\AI\Entities\Conversations\Agent\AIConversationTurnBudget;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AIConversationTurnOptions;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRun;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentReply;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentTurnOutcome;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Purposes\AgentPurposedConversationStart;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Services\Mandates\AgentMandatesService;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\UsageLogs\AIBudgetBypassGrant;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Exceptions\AIModelCallTransportFailedException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\MessageHandlers\AIConversationResumeMessage;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The synchronous agent loop. Provider-portable via the Argus OpenRouter passthrough.
 *
 * Per turn it offers the model a `load_skills` meta-tool (enum = the skill catalog) plus the tools of any
 * already-loaded skills. The model either loads more skills, calls a loaded tool, or returns final text.
 * Skill loading is soft + iterative (callable every step, additive); `load_skills` is itself a tool, so the loaded
 * skills land first-class (FULLTEXT-queryable) on its `MESSAGE_TOOL_CALL` message. Every step is logged onto the
 * conversation (`MESSAGE_TOOL_CALL` carrying call + result / `MESSAGE_TEXT`). Tools execute in-process via the
 * {@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}, under the request's auth context.
 */
class AIConversationAgentService extends Service implements AgentModelCallRunnerInterface, AgentChildTurnRunnerInterface
{
    use AITraceLogTrait;

    /** User-facing reply when a resume stalls past MAX_RESUME_ATTEMPTS (model emitted only LOOP_NUDGE turns) — the
     * graceful terminal instead of a silent unanswered question ({@see appendStallGiveUpReply}). */
    public const string STALL_GIVE_UP_REPLY = "I wasn't able to produce a response to that just now. Could you rephrase it or add a little more detail?";

    /**
     * User-facing reply persisted when a turn dies on the AI-budget cap ({@see AiBudgetExceededException}) — the
     * customer must never be left with a silent failure. Deliberately neutral on HOW to lift the limit (upgrade
     * offers are partner-dependent); the frontend budget widget carries the upsell when one is available, keyed on
     * the structured {@see AiBudgetExceededException::ERROR_CODE} of the accompanying ERROR message.
     */
    public const string AI_BUDGET_REACHED_REPLY = "I can't work on this right now — the monthly AI budget of your plan is used up. It resets at the start of next month.";

    /**
     * Append + persist a user-facing ASSISTANT reply telling the customer we couldn't produce an answer — the graceful
     * terminal for a resume that stalled past {@see MAX_RESUME_ATTEMPTS} (a model that emitted only LOOP_NUDGE turns).
     * Mirrors the in-loop step-limit fallback ({@see runConversationLoop}); without it the customer is left with a
     * SILENT unanswered question (the trailing LOOP_NUDGE is user-INVISIBLE). Called at the resume boundary (post-loop,
     * no transient-id counter in scope): the conversation is persisted, so the row build ignores the throwaway
     * transient id and the real DB id is assigned on persist. No console stream here (the worker has no console);
     * `$emitRealtimeSignal` ticks the frontend so the reply lands live.
     */
    protected function appendStallGiveUpReply(AgentRun $agentRun, AIConversation $conversation, bool $emitRealtimeSignal): void
    {
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        // the stall give-up is a report-owing terminal. With a task plan the report names the actual
        // state (done/open/blocked paths) instead of the generic rephrase prompt; without one the friendly
        // interactive text stays. Owed stamped BEFORE the append (crash-safe), delivered after.
        $throwawayTransientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
        $aIConversationOwnerReportDeliveryService->deliverRunReportNow(
            $agentRun,
            $conversation,
            $throwawayTransientMessageIds,
            AgentRunTerminalReport::forStallGiveUp(self::STALL_GIVE_UP_REPLY),
            null,
            $emitRealtimeSignal
        );
    }

    /**
     * LATE report delivery (the owed-reports recovery pass): the terminal that stamped `reportOwedAt` could not
     * deliver inline (worker crash between stamp and append, or the reaper's set-based expiry). CLAIMS the delivery
     * ATOMICALLY first ({@see AIConversationOwnerReportDeliveryService::claimReportDelivery()} — one conditional UPDATE, so two
     * overlapping recovery runs never both append), then loads the message tail,
     * composes the template-only {@see AgentRunReport} (stop reason mapped from the newest ERROR's structured
     * code), appends it as the terminal chat message, and ticks the frontend. A FAILED append reverts the claim
     * so a later pass retries — the obligation is never silently swallowed.
     */
    public function deliverOwedReportLate(AIConversation $conversation): void
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        if (!isset($conversation->id) || isset($conversation->reportDeliveredAt)) {
            return;
        }
        if (!$aIConversationOwnerReportDeliveryService->claimReportDelivery($conversation)) {
            return; // a concurrent recovery run won the claim — it delivers.
        }
        try {
            $aIConversationOwnerReportDeliveryService->composeAndAppendOwedReport($this->buildRunOutsideARun(), $conversation);
        } catch (Throwable $owedReportDeliveryError) {
            $aIConversationOwnerReportDeliveryService->revertReportDeliveryClaim($conversation);
            throw $owedReportDeliveryError;
        }
        // The late delivery settles the obligation a settled turn would have settled: a purposed run whose purpose
        // closes on the delivered report (Schedule.Run) closes here too, so the scorecard reads `completed`, not
        // `abandoned`, for a run the sweep finished. Best-effort like the settled-turn close.
        try {
            $aIConversationPurposeRunsService->closeConversationIfPurposeGoalReached($this->buildRunOutsideARun(), $conversation);
        } catch (Throwable) {
            // swallow — the delivery is done; the reaper's settle sweep re-evaluates the close
        }
    }

    /**
     * Run the loop until the model returns a final text answer (or MAX_STEPS is hit). Mutates and returns
     * the conversation with the appended messages.
     *
     * When $output is given (CLI), every message is streamed to it live as the loop produces it — instead of the
     * caller having to render the whole transcript after the turn completes. HTTP/Messenger pass null (no streaming).
     *
     * The loop-control counters are DERIVED from the conversation's messages each iteration (pure folds, see
     * runConversationLoop) — the former thread-partition `AgentRun` is gone. What one invocation carries besides is its
     * {@see AgentRun} (the side-work options and the timings, passed as a parameter, never stored on
     * this service — the worker resume hands in its own), the transient-id tree counter (seeded above the existing ids
     * so a resumed ephemeral multi-turn chat never reuses one), the model override, and the console stream.
     */
    public function generateAgentReply(
        AIConversation $conversation,
        ?OutputInterface $output = null,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?string $aiModelName = null,
        bool $timings = false,
        bool $emitRealtimeSignal = false,
        bool $asyncDelegations = false,
        ?string $runToken = null,
        bool $compactionEnabled = true,
        bool $compactAsync = false,
        bool $sideWorkEnabled = true,
        ?AgentRun $agentRun = null
    ): AIConversation {
        // What the reply costs outside its loop, its message rows and its tool calls — the skill-preload decisions, the
        // SYNC compaction and summary of the CLI/eval path — is booked on the conversation; the loop books its own
        // ({@see runConversationLoop()}), the worker path's async jobs book through their middleware.
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIUsageEnvelopesService = $this->aIUsageEnvelopesService();
        $replyOverheadBookedCosts = $aIUsageEnvelopesService->startRecordingBookedCosts();
        try {
            /** @var AgentSkillPreloadDecisionsService $agentSkillPreloadDecisionsService */
            $agentSkillPreloadDecisionsService = DDDService::instance()->getService(AgentSkillPreloadDecisionsService::class);
            // The run this drive belongs to: the worker resume passes its own (with the turn-start stamps), every sync
            // entry starts one here.
            $agentRun ??= AgentRun::forTurnEntry();
            // Embedding follows the SAME sync/async split as compaction: the CLI/eval path ($compactAsync=false) embeds
            // INLINE (no Messenger consumer in the console), HTTP/worker ($compactAsync=true) embeds async via the worker.
            $agentRun->turnOptions->embedMessagesAsync = $compactAsync;
            // $sideWorkEnabled=false (CLI `--no-side-work` latency benchmark): skip the per-message recall embedding AND the
            // display-summary generation — both are ASYNC worker jobs in production, never on the response path, so a CLI
            // wall-clock run must not count them. Compaction is gated separately by $compactionEnabled (the CLI folds
            // --no-side-work into it). Always TRUE on the HTTP/worker path, so a real turn is unaffected.
            $agentRun->turnOptions->embedMessagesEnabled = $sideWorkEnabled;
            // The entry's options also stay on this service for the rows
            // persisted OUTSIDE a run — sticky for the life of the instance (in a CLI process the rows between turns embed
            // inline after the first turn, async before it).
            $this->turnOptionsOutsideARun()->embedMessagesAsync = $compactAsync;
            $this->turnOptionsOutsideARun()->embedMessagesEnabled = $sideWorkEnabled;
            /** @var AgentMessagePrinterInterface $agentMessagePrinterInterface */
            $agentMessagePrinterInterface = DDDService::instance()->getService(AgentMessagePrinterInterface::class);
            $consoleStream = $output !== null
                ? new AgentConsoleStream($output, $agentMessagePrinterInterface, $full, $debug, $collapseThreads, $timings)
                : null;
            // What became true while nobody looked and this turn must read — the EXPIRED marker of an in-app ring on this
            // conversation that passed its deadline unanswered — is persisted now, before the first model call.
            $this->persistRowsOwedBeforeTheTurnStarts($conversation);
            $transientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
            // Main thread may spawn sub-agent delegations. $emitRealtimeSignal = the per-message realtime tick (worker only;
            // CLI/eval/HTTP-sync pass false). $asyncDelegations (worker only) = each delegate runs in its OWN
            // worker (dispatched, the parent parks AWAITING_DELEGATES) instead of inline; CLI/eval/sync stay inline.
            // $runToken (worker only) = the active run-claim's fencing token; the loop heartbeats it each step so a
            // long turn is not mis-reaped, and stops if it ever loses the claim. Null on every sync path (no claim held).
            // A widget the customer COMPOSED in the pending inbound message (a marked page element, …) preloads the
            // skill(s) that declared themselves its handler — HERE, at the entry every path shares (HTTP sync, worker resume,
            // CLI, eval), so the routing never depends on the model recognising a JSON block as a request.
            $agentSkillPreloadDecisionsService->preloadSkillsTriggeredByWidgetFences($conversation);
            // A CALL conversation preloads the skills bound to its initiator — what a call needs is live from
            // the first delegated turn, without the model having to discover it from a spoken sentence.
            $agentSkillPreloadDecisionsService->preloadSkillsBoundToInitiatorType($conversation);
            // A DELEGATION child never spawns children — the inline child path already ran without
            // threads, the async path offered `delegate`; one level of delegation everywhere (grandchildren escaped the
            // parent batch's timeout / cancellation and every run-associated activity count).
            $invocationReply = $this->runConversationLoop($agentRun, $conversation, !$conversation->isDelegation(), $transientMessageIds, $aiModelName, $consoleStream, $emitRealtimeSignal, $asyncDelegations, $runToken);
            // the release of a worker turn reads why the loop stopped (a turn that ended waiting for its sub-agents)
            $agentRun->lastLoopStopReason = $invocationReply->loopStopReason;
            // After the turn, compact if the conversation crossed its (per-conversation or global) threshold.
            // SYNC inline by default ($compactAsync=false) — the CLI/eval path, incl. `--ephemeral` (no id → the in-memory
            // anchor stamp feeds the NEXT turn's backward walk). The HTTP/admin path passes $compactAsync=true to dispatch.
            // Never on a delegation child (only the root dialog grows). Best-effort: a compaction failure must NEVER break
            // the reply — it only ever shortens a FUTURE context, never the current turn.
            if ($compactionEnabled && !$conversation->isDelegation()) {
                try {
                    /** @var AIConversationCompactionService $aIConversationCompactionService */
                    $aIConversationCompactionService = DDDService::instance()->getService(AIConversationCompactionService::class);
                    $aIConversationCompactionService->compactConversationIfDue($conversation, async: $compactAsync);
                } catch (\Throwable $compactionError) {
                    // Best-effort: the reply survives — but LOG loudly: a silently failing
                    // compaction leaves the conversation on the raw full-window path FOREVER with zero signal in the
                    // logs; the error level lands in the runtime-errors pipeline where it is actually seen.
                    \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                        'AIConversationAgentService: compaction failed for conversation '
                        . ($conversation->id ?? 'transient') . ' — conversation stays on the raw full-window path: '
                        . $compactionError->getMessage(),
                        ['exception' => $compactionError]
                    );
                }
            }
            // REFRESH the display summary at EVERY settle when it is STALE — no summary yet (first turn), or
            // the newest message index grew past the summary's coveredUpToMessageIndex marker. The refresh itself (the
            // incremental Argus invocation + the marker stamp + the manuallyEdited latch) lives in
            // generateAndPersistSummary; this outer staleness gate only avoids pointless async dispatches. isDisplaySummary-
            // Stale uses SINGLE-level `isset` internally (lazy-safe — never fires the Argus generator; the NESTED form
            // `isset(->summary->title)` WOULD, the bug we must not reintroduce). Async on the HTTP/admin path, sync on
            // CLI/eval. Never on a delegation child.
            if ($sideWorkEnabled && !$invocationReply->yielded && !$conversation->isDelegation() && $conversation->isDisplaySummaryStale()) {
                try {
                    /** @var AIConversationsService $aIConversationsService */
                    $aIConversationsService = AIConversation::getService();
                    $aIConversationsService->generateAndPersistSummary($conversation, async: $compactAsync);
                } catch (\Throwable) {
                    // best-effort: a missing display summary never breaks the reply
                }
            }
            return $conversation;
        } finally {
            $aIUsageEnvelopesService->stopRecordingBookedCosts($replyOverheadBookedCosts);
            if (isset($conversation->id)) {
                $aIConversationsService->addOverheadCostsToConversation((int)$conversation->id, $replyOverheadBookedCosts);
            }
        }
    }

    /**
     * The turn-start listeners ({@see AIConversationTurnStartListener}) persist what this turn must read before its first
     * model call; when one of them wrote a row, the conversation's rows are reloaded (the bounded context tail) so the
     * row is in this turn's context. A transient conversation has no persisted rows to add to. Fail-soft: the turn runs
     * without the row when the reload fails.
     */
    protected function persistRowsOwedBeforeTheTurnStarts(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        /** @var AIConversationTurnStartListenersService $aIConversationTurnStartListenersService */
        $aIConversationTurnStartListenersService = DDDService::instance()->getService(AIConversationTurnStartListenersService::class);
        if (!$aIConversationTurnStartListenersService->notifyListenersOfTurnStart($conversation)) {
            return;
        }
        try {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
            $this->aiTrace('turn.start.listenerRowsReloaded', ['conversationId' => $conversation->id]);
        } catch (Throwable $reloadError) {
            $this->aiTrace('turn.start.listenerRowsReloadFailed', ['conversationId' => $conversation->id, 'error' => $reloadError->getMessage()]);
        }
    }

    /**
     * The `bool $async` seam: drive ONE turn of an already-prepared conversation (its USER message
     * already appended + persisted). `async:true` → dispatch {@see AIConversationResumeMessage} for a Messenger worker
     * and return immediately; `async:false` → run the UNCHANGED synchronous loop inline. The worker
     * ({@see \DDD\Domain\AI\MessageHandlers\AIConversationResumeHandler}) calls this with `async:false`. The CLI/eval
     * never touch this seam — they call {@see generateAgentReply} directly and stay fully synchronous.
     *
     * Inline (`$async=false`) it (a) attaches the relations of the conversation's space when they are missing — the
     * worker loads the conversation fresh and the prompt build reads them — and (b) applies the conversation's persisted
     * {@see AIConversation::$budgetBypass} for THIS turn only, as a grant on the caller's envelope (restored in
     * `finally`). It deliberately does NOT open a usage envelope: the caller owns it — the resume handler, the only
     * inline caller, runs inside the worker's `AIEnvelopeMiddleware` envelope or opens its own fallback one.
     *
     * `$ownerRowSavedWallUs` (async only): the API's wall clock right after the owner's row was saved — it rides the
     * dispatched message into the turn-start stamps.
     */
    public function resumeConversationIfReady(AIConversation $conversation, bool $async = false, ?AgentWorkerJobReceipt $agentWorkerJobReceipt = null, ?float $ownerRowSavedWallUs = null): void
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        /** @var AIConversationDelegationToolRunService $aIConversationDelegationToolRunService */
        $aIConversationDelegationToolRunService = DDDService::instance()->getService(AIConversationDelegationToolRunService::class);
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        /** @var AIConversationDelegationResultsService $aIConversationDelegationResultsService */
        $aIConversationDelegationResultsService = DDDService::instance()->getService(AIConversationDelegationResultsService::class);
        /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
        $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        // 0. The seam head: enqueue one id and return — never run the claim inline on the async path.
        if ($async) {
            $aIConversationResumeMessage = new AIConversationResumeMessage((int)$conversation->id);
            $aIConversationResumeMessage->ownerRowSavedWallUs = $ownerRowSavedWallUs;
            $aIConversationResumeMessage->dispatch();
            return;
        }

        // 1. Take the run-claim FIRST — the exactly-once gate against the four duplicate-resume sources
        //    (concurrent sibling delegations, RabbitMQ redelivery, reaper, recover-on-interaction). affectedRows=0 ⇒ a
        //    live worker holds it ⇒ clean no-op.
        // turn-start stamps: the phases between the owner's message and the first model call, measured on THIS run.
        // A resume the handler did not start (a sync transport job, an eval
        // inline drive) reads the receipt of the LAST job the handler received in this process.
        $agentRun = AgentRun::forWorkerResume($agentWorkerJobReceipt ?? self::$lastWorkerJobReceipt);
        // The resume's rows (the ones persisted before the drive too —
        // steers, finished-children notes, tandem and mechanical turns, the releases) read the options this instance
        // keeps for rows outside a run — the SAME object, which the drive then sets for its entry.
        $agentRun->turnOptions = $this->turnOptionsOutsideARun();
        $agentRun->stampTurnStartPhase('handlerLoad');
        $runToken = $aIConversationRunClaimsService->mintRunToken();
        if (!$aIConversationRunClaimsService->claimRun($conversation, $runToken)) {
            $this->aiTrace('turn.claim.lost', ['conversationId' => $conversation->id ?? null, 'runState' => $conversation->runState]);
            return;
        }
        $this->aiTrace('turn.claim.won', ['conversationId' => $conversation->id ?? null, 'runState' => $conversation->runState]);
        if ($this->releaseRunOfIneligibleScope($agentRun, $conversation, $runToken)) {
            return;
        }
        // the CHUNK row — the only durable link between this invocation's usage envelope and the turn.
        $aIConversationTurnEventsService->recordChunkEventOfConversation($conversation, $runToken);
        $agentRun->stampTurnStartPhase('claim');

        // Never-stuck: the load of the space's relations + derive run AFTER the claim but BEFORE the drive's try/catch —
        // if any of them throws (e.g. a repo/prompt error, or a malformed message), the claim must STILL be released or
        // the conversation is wedged RUNNING until the reaper. Release + rethrow on any pre-drive throw.
        try {
            $conversation->loadAgentSpaceRelationsIfMissing();
            $agentRun->stampTurnStartPhase('agentSpaceLoad');

            // Load the recency-graded COVER nodes FIRST: the ~log_K(N) staircase, NOT the whole node
            // set (which grows O(N) on an endless conversation). The cover is a disjoint contiguous tiling, so the
            // in-memory cover/summary methods reproduce the identical result. PERSISTED only — never reset an ephemeral
            // conversation's in-memory tree (the sync round maintains it).
            if (isset($conversation->id)) {
                $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
            }
            // Then load the messages the context build needs, BOUNDED: the raw tail past the cover edge
            // when compacted (older messages are already in the cover nodes — loading them is pure O(N) waste), else the
            // full ordered window. See {@see loadMessagesForDerive} — the SAME bounded load the post-turn re-derives use.
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
            $agentRun->stampTurnStartPhase('messagesLoad');

            // Supervisor: materialize any not-yet-applied supervisor verdicts as
            // SUPERVISOR_STEERING signals NOW — under this run-claim, so the supervised conversation's own drive worker
            // is the SOLE appender of its rail (no cross-writer race with the supervisor worker). Appended before the
            // derive below so a fresh steer counts as owed inbound work AND rides this turn's context. No-op for a
            // non-supervised conversation; best-effort (never wedges the turn). {@see materializeUnconsumedSupervisorSteers}
            $supervisorCheckpointRunsService->materializeUnconsumedSupervisorSteers($conversation);

            // 2. Derive on the fresh-claimed state — TWO INDEPENDENT drive triggers (do NOT conflate):
            //    (A) an unanswered INBOUND message — a USER message (incl. a delegation child's seed task) OR an
            //        injected SIGNAL (e.g. a MEMORY_CONFLICT clarify request) → drive NOW, even mid-batch;
            //    (B) a batch THIS conversation forked is SETTLED-but-unfolded → drive a turn to fold its verdicts.
            $hasUnansweredInbound = $conversation->hasUnansweredInboundMessage();
            $settledUnfoldedBatchId = isset($conversation->id)
                ? $aIConversationDelegationBatchesService->findOldestBatchIdWithUnreadChildResultsForParent((int)$conversation->id)
                : null;
        } catch (Throwable $deriveError) {
            // Pre-drive throw: the claim has already CLEARED agentTurnOwedAt, the handler
            // swallows what is rethrown here, and a plain IDLE release left the row with an unanswered inbound that no
            // sweep owned — the "user turn lost, no error widget" shape. ONE token-guarded release re-stamps the owed
            // marker (PHP clock) and counts the attempt in the same row write, so the owed-turn recovery pass
            // re-drives durably after its quiet window; over MAX_RESUME_ATTEMPTS the release is FAILED (the customer
            // sees the widget and can retry the persisted message) instead of retrying forever. Deliberately NO ERROR
            // bookkeeping row: the re-driven worker's derive must find the USER message trailing.
            // Under the cap the release re-stamps the owed marker the claim cleared; over the cap it leaves it — claimRun
            // already NULLed it and a FAILED row must not sit in the owed-turn candidate set. The customer-facing
            // status: over the cap the widget ("failed"); under the cap the turn is still OWED and will be re-driven by
            // the recovery pass — "running", never "idle" (an IDLE read with the customer's message unanswered IS the
            // swallowed-message symptom this cluster removes).
            $runRelease = AgentRunRelease::afterPreDriveFailure(
                $conversation->resumeAttempts,
                $conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null,
                new DateTime()
            );
            $this->aiTrace('turn.preDrive.failed', [
                'conversationId' => $conversation->id ?? null,
                'exceptionClass' => $deriveError::class,
                'message' => $deriveError->getMessage(),
                'attempt' => $runRelease->resumeAttempts,
                'overCap' => $runRelease->overCap,
            ]);
            $yieldedInsteadOfFailing = false;
            if ($runRelease->finishesIntoDelegationBatch) {
                // A delegation CHILD that exhausted its retries fail-folds into its batch (the settle owns its terminal).
                // The fold reads the child's newest assistant text, so give it the derive tail (the throw may have
                // happened before the load) — and never let a failing fold swallow the rethrow below.
                try {
                    $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
                    $yieldedInsteadOfFailing = $this->finishDelegationChild($conversation, $runToken, AIConversationRunState::FAILED, $aIConversationDelegationBatchesService, $agentRun);
                } catch (Throwable $foldError) {
                    $this->aiTrace('turn.preDrive.failFoldFailed', ['conversationId' => $conversation->id ?? null, 'message' => $foldError->getMessage()]);
                    $runRelease = $runRelease->afterDelegationChildTerminalThrew();
                }
            }
            $this->applyRunRelease($agentRun, $conversation, $runToken, $runRelease, $yieldedInsteadOfFailing);
            throw $deriveError;
        }
        // SUPERVISOR TANDEM (single writer): the supervised side only WAKES the tandem — no briefing row is
        // waiting, so the tandem runs BEFORE the inbound derive: it reads the supervised rows itself, writes its briefing
        // and decision, and settles straight back to IDLE (never a delegation, never parks). A wake that met this claim
        // was dropped — after the release a still-owed beat re-fires the tandem once.
        if ($conversation->isSupervisorTandem()) {
            $supervisorCheckpointRunsService->runSupervisorCheckpointTurn($agentRun, $this, $conversation, $runToken);
            $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterSupervisorTandemTurn());
            try {
                $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
                $supervisorCheckpointRunsService->resumeTandemIfABeatIsStillOwed($conversation);
            } catch (\Throwable) {
                // best-effort — the next wake catches up
            }
            return;
        }
        if (!$hasUnansweredInbound && $settledUnfoldedBatchId === null) {
            $this->aiTrace('turn.claim.noWork.reDerive', ['conversationId' => $conversation->id ?? null]);
            // Neither trigger at the first derive — BUT a child may have settled its batch DURING this derive, and that
            // settle's parent-wake could have been dropped on a claim collision (the fan-in race). So do NOT silently
            // re-park: reload the PERSISTED state and re-derive. If a fold is now owed (a settled-unfolded batch) or a
            // user message arrived mid-derive, RE-FIRE a resume — this closes the dropped-wake race without waiting for
            // the reaper. Converges: it re-fires only while work is owed; once the fold sets foldedAt the next derive
            // finds nothing owed and stops.
            $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation); // bounded tail-load; only the newest message is read below
            $reloadedNewestIndex = $conversation->messages->getNextIndex() - 1;
            $hasUnsettledBatch = isset($conversation->id) && $aIConversationDelegationBatchesService->hasUnfinishedBatchForParent((int)$conversation->id);
            $freshSettledUnfoldedBatchId = isset($conversation->id)
                ? $aIConversationDelegationBatchesService->findOldestBatchIdWithUnreadChildResultsForParent((int)$conversation->id)
                : null;
            $workOwedNow = $conversation->hasUnansweredInboundMessage() || $freshSettledUnfoldedBatchId !== null;
            // Delegations are NON-BLOCKING: an unsettled batch does not park the parent — it releases IDLE and
            // stays fully conversational (the settle wake / the reaper's re-fire sweep deliver the fold as its
            // own turn). Nothing owed = the turn was answered by an earlier chunk (or a steal landed after its
            // persist) — the terminal is recorded late unless it already exists. A parent re-parking
            // with an unsettled batch also re-checks its queue (level-triggered promotion).
            $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterNoWork($workOwedNow, $hasUnsettledBatch, $reloadedNewestIndex));
            // A resume the supervisor's intervention re-fired can find nothing to do (the intervention was stale and
            // its materialization wrote a resolved note): no answered turn, no follow-ups — so the purpose close is
            // re-evaluated here, or the reached goal waits for the reaper.
            if (!$workOwedNow && ($conversation->purposeName ?? null) !== null && !$conversation->isDelegation()) {
                try {
                    $this->settlePurposeConversationIfGoalReached($conversation);
                } catch (\Throwable) {
                    // best-effort — the reaper's settle sweep re-evaluates
                }
            }
            return;
        }

        // A turn that sub-agent notifications alone opened waits — claim held — up to
        // 2 s of quiet and at most 8 s while other sub-agents still run, so run ends close together share this turn.
        $this->waitForCloseRunEndsBeforeTheFirstModelCall($conversation, $runToken);

        // The highest message INDEX before this turn — so the success re-fire can tell a
        // turn that made REAL progress (appended ≥1 non-LOOP_NUDGE message → reset attempts) from one that produced only
        // a LOOP_NUDGE / nothing (counts toward MAX_RESUME_ATTEMPTS). A stalling model whose every turn appends only a
        // trailing LOOP_NUDGE would otherwise re-fire forever (the nudge reads as owed inbound work) while resetting the
        // counter each cycle — this index anchors the "real progress" test ({@see AIConversationMessages::hasNonNudgeMessageAfterIndex}).
        // INDEX not count: the post-turn set may be a bounded/compacted tail reload, where a raw count delta is wrong.
        $highestIndexBeforeTurn = $conversation->messages->getNextIndex() - 1;

        // TOOL-RUN child: a delegation child that runs ONE tool call executes its seed's named
        // operation with ZERO model calls and settles straight into its BACKGROUND batch — none of the model-drive
        // machinery below applies. Errors are caught inside the turn method (a truthful FAILED verdict), so the claim
        // always releases via the settle. The BACKGROUND settle never parks/resumes the parent; the completion note
        // rides the parent's next genuine turn ({@see AIConversationDelegationResultsService::injectBackgroundWorkFinishedNote()}).
        if ($conversation->isToolRunDelegationChild()) {
            $terminalRunState = $aIConversationDelegationToolRunService->runToolRunChildTurn($agentRun, $conversation);
            $yielded = $this->finishDelegationChild($conversation, $runToken, $terminalRunState, $aIConversationDelegationBatchesService, $agentRun);
            $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterDelegationChildVerdict(), $yielded);
            return;
        }

        // The conversation's persisted bypass lifts the cap for THIS turn: granted on the turn worker's envelope (opened by
        // AIEnvelopeMiddleware, or by the resume handler's own fallback envelope — the only caller of this inline path),
        // so every envelope a tool or Argus call opens inside the turn inherits it; restored in the `finally`.
        $turnBudgetBypassGrantRestore = $conversation->budgetBypass
            ? $this->aIUsageEnvelopesService()->grantBudgetBypassUntilRestored(
                AIBudgetBypassGrant::forReason(AIBudgetBypassGrant::REASON_CONVERSATION_BYPASS)
            )
            : null;
        $priorSupportOverrideNote = $this->activateEligibilitySupportOverrideIfSet($conversation);
        try {
            // 3. Drive one turn, with async delegations: a delegate spawns its own worker and
            //    the loop PARKS instead of running the child inline. emitRealtimeSignal ticks the frontend per message.
            // compactAsync: this is the background turn-worker (off the request thread). Compaction is dispatched as
            // its OWN ai_conversation_compaction job rather than run inline in the turn-worker — only the CLI
            // (AgentChatCommand) and the in-process eval harness run compaction sync inline.
            $this->aiTrace('turn.modelCall', ['conversationId' => $conversation->id ?? null, 'model' => $conversation->aiModelNameOverride ?? null, 'hasUnansweredInbound' => $hasUnansweredInbound, 'settledBatchId' => $settledUnfoldedBatchId]);
            // FOLD made EXPLICIT: a finished-but-unread batch is reported to the orchestrator as a
            // DELEGATIONS_SETTLED signal BEFORE the turn, so the model answers from the verdicts instead of polling
            // check_delegations until the repeat guard aborts. Injected here, in the parent's own claimed
            // drive — not cross-conversation from the child settle — and idempotent across a retried fold.
            if ($settledUnfoldedBatchId !== null) {
                $aIConversationDelegationResultsService->injectChildrenFinishedSignal($conversation, $settledUnfoldedBatchId, $aIConversationDelegationBatchesService);
            }
            // BACKGROUND batches: finished background work rides THIS genuine turn
            // as one compact note (never an auto-drive of its own) and is marked read immediately — the results
            // already sit on the delegate-call messages in context.
            $aIConversationDelegationResultsService->injectBackgroundWorkFinishedNote($conversation, $aIConversationDelegationBatchesService);
            $aIConversationDelegationResultsService->repairDelegateCallsOfSettledBatches($conversation, $aIConversationDelegationBatchesService);
            // The notifications of this parent's sub-agent runs that ended without one (a crash between
            // the run end and its notifier) are written now; the turn's first model call takes them.
            $aIConversationDelegationNotificationsService->deliverLostNotificationsOfParent($conversation);
            $this->generateAgentReply($conversation, emitRealtimeSignal: true, asyncDelegations: true, runToken: $runToken, aiModelName: $conversation->aiModelNameOverride ?? null, compactAsync: true, agentRun: $agentRun);

            // Folded a settled batch's verdicts this turn → mark it FOLDED so it never re-triggers a fold.
            if ($settledUnfoldedBatchId !== null) {
                $aIConversationDelegationBatchesService->markChildResultsReadByParent($settledUnfoldedBatchId);
            }

            // A purposed run that another conversation began to AWAIT while this turn ran (the awaiting run joins it
            // to its batch, see joinAwaitedConversationsToBatch) must finish as a batch MEMBER —
            // this worker loaded the row before the stamp, so its object still reads as a root. Re-read the anchor
            // columns now: without it the awaited run completed as a root, nobody finished the batch,
            // and the awaiting run waited for the reaper's timeout (minutes) instead of the child's own end (seconds).
            $aIConversationDelegationResultsService->adoptDelegationAnchorJoinedMidTurn($conversation);

            // 4. Branch on what the turn produced, resetting resumeAttempts on successful progress.
            $hasUnsettledForkedBatch = isset($conversation->id) && $aIConversationDelegationBatchesService->hasUnfinishedBatchForParent((int)$conversation->id);
            if ($agentRun->lastLoopStopReason?->isWaitingForSubAgents() === true) {
                // The turn sub-agent notifications started ended with `wait_for_sub_agents` — no answer
                // was given, so none of the answered branch's follow-ups run; RUNNING while the sub-agents work, and a run
                // end that landed after the last model call re-fires through the probe.
                // An owner message that landed during the turn (its resume met the held claim)
                // sits BEFORE the wait call, so no owed-work walk sees it — the same reload and owner-inbound check the
                // answered branch runs: a trailing inbound or a mid-turn owner row (quoted by a note) re-fires the turn.
                $ownerInputArrivedDuringTheTurn = $this->reloadAndFindOwnerInputArrivedDuringTheTurn($agentRun, $conversation, $highestIndexBeforeTurn);
                $this->aiTrace('turn.released.waitingForSubAgents', ['conversationId' => $conversation->id ?? null, 'ownerInputArrived' => $ownerInputArrivedDuringTheTurn]);
                $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterWaitingForSubAgents($highestIndexBeforeTurn, $ownerInputArrivedDuringTheTurn));
            } elseif ($hasUnsettledForkedBatch) {
                // The turn forked async delegation children (an UNSETTLED batch) → release IDLE: the conversation
                // stays fully conversational while the children run (a user message mid-batch drives normally; the
                // settle wake / the reaper's re-fire sweep deliver the fold as its OWN later turn, whose answer
                // arrives as a new chat message). The realtime signal stays RUNNING so the frontend keeps its
                // activity indicator until the fold lands.
                // Release-time re-derive (the dropped-wake guard): a fast child may have SETTLED the batch during the
                // dispatch→here window, and that settle's parent-wake could have been DISCARDED (its claimRun returned 0
                // while we still held the claim). Reload + re-derive; if a fold is owed now, re-fire instead of releasing
                // blind (which would strand the fold until the reaper).
                $this->aiTrace('turn.released.delegationsRunning', ['conversationId' => $conversation->id ?? null]);
                $freshSettledUnfoldedBatchId = isset($conversation->id)
                    ? $aIConversationDelegationBatchesService->findOldestBatchIdWithUnreadChildResultsForParent((int)$conversation->id)
                    : null;
                // Lost-wakeup guard: a signal that landed after this turn's last model call is still waiting (its
                // injection saw the live claim and dispatched no resume) — the next turn's first model call reads it.
                $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterDelegationsStarted($freshSettledUnfoldedBatchId !== null, $highestIndexBeforeTurn));
            } elseif ($conversation->isDelegation()) {
                // Chunk yield: a mid-task child that YIELDED left a trailing TURN_CONTINUATION signal —
                // it has NOT produced its verdict yet. Release + re-fire the continuation instead of settling a
                // partial verdict into the batch. (Non-yield child invocations always end on a final/fallback
                // assistant text, so their reloaded tail never reads as owed work.) Registry-bypassed reload — the
                // in-memory set is this invocation's view; the derive needs the persisted trailing message.
                $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
                // a turn that ended with an answer owes the child another turn for an orchestrator's message
                // no model call read; a turn cut by a cap (cost, steps, an aborted repeat) would stop at the cap again
                // before any model call — it ends the run as before (COMPLETED with its report), the unread message named
                // in its end, and never yields for it (never a yield loop)
                $turnAnswered = $agentRun->lastLoopStopReason?->isAnswered() !== false;
                if ($turnAnswered ? $conversation->hasUnansweredInboundMessage() : $conversation->messages->hasUnansweredInboundRowAtTheTail()) {
                    $this->aiTrace('turn.yielded.child', ['conversationId' => $conversation->id ?? null]);
                    $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterChildYield());
                    return;
                }
                $this->aiTrace('turn.settled', ['conversationId' => $conversation->id ?? null, 'runState' => AIConversationRunState::COMPLETED, 'isDelegation' => true]);
                // This conversation IS a delegation CHILD that produced its verdict → settle into its parent's batch:
                // write the verdict onto the spawning delegate-call (the fold + check_delegations channel), transition
                // this child terminal COMPLETED, and claim the single parent resume exactly once if this completion first
                // satisfies the resume condition.
                $yielded = $this->finishDelegationChild($conversation, $runToken, AIConversationRunState::COMPLETED, $aIConversationDelegationBatchesService, $agentRun);
                $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterDelegationChildVerdict(), $yielded);
            } else {
                // A root dialog that answered → release IDLE. Recover-on-interaction (REQUIREMENT: the user can
                // send a message AT ANY TIME, even while a turn runs): a USER message that arrived DURING this turn had
                // its own resume no-op'd (claimRun found us RUNNING) and is now orphaned — no further resume is coming.
                // Reload the persisted tail (bounded); if a new unanswered user message landed mid-turn, re-fire a resume
                // so it gets driven (mirrors the AWAITING_DELEGATES release-time re-derive). loadMessagesForDerive bypasses
                // the entity-registry cache (else it returns the stale pre-turn tail and misses the new message).
                // An owner message or click that landed MID-TURN (index above the pre-turn
                // mark, not the trailing row) is not owed by the trailing-walk — the agent's later question covers it;
                // the reload quotes the owner's words in a trailing note so the next turn acknowledges them.
                $newInboundArrivedMidTurn = $this->reloadAndFindOwnerInputArrivedDuringTheTurn($agentRun, $conversation, $highestIndexBeforeTurn);

                // BOUND the trailing-LOOP_NUDGE re-fire: a turn that produced NO real progress — only a LOOP_NUDGE
                // (or nothing: a claim-lost silent abort that had persisted a nudge) — leaves that nudge as the last
                // message, which hasUnansweredInboundMessage() reads as owed work. Re-firing then re-drives a stalling
                // model that appends another nudge, forever, while resetting resumeAttempts each cycle. So a non-progress
                // re-fire counts toward MAX_RESUME_ATTEMPTS instead of resetting it; over the cap we give up gracefully
                // with a user-facing reply instead of re-firing. A genuine answer / tool call / fresh USER-or-signal
                // inbound IS real progress → the counter resets and the drive continues freely (signals stay answerable).
                $turnMadeRealProgress = $conversation->messages->hasNonNudgeMessageAfterIndex($highestIndexBeforeTurn);
                // A work signal that landed while the turn ran is structurally orphaned (the
                // owed-work walk sees the assistant's text as the tail, the owner-inbound walk keeps owner rows only).
                // Re-append it with its ORIGINAL text under its own source so the tail owes a turn again, and re-fire
                // once through the same path an owner inbound takes. Computed AFTER the progress flag on purpose: the
                // replay row must not reset the no-progress counter.
                // A signal the running loop handed to the model at its next model call is
                // followed by the loop's replay row and is never found here — this replay covers only a signal that
                // landed after the turn's last model call (its injection dispatched no resume while the claim lived).
                if (!$newInboundArrivedMidTurn) {
                    $newInboundArrivedMidTurn = $aIConversationTurnSignalsService->replaySignalsThatArrivedAfterTheLastModelCall($agentRun, $conversation, $runToken, $highestIndexBeforeTurn);
                }
                // No real progress while inbound is owed: under the cap carry the incremented count (do NOT reset)
                // and re-fire one more attempt; over it give up — the user-facing reply is appended BEFORE the release
                // (the trailing nudge is user-INVISIBLE; the reply is now the last message, so nothing re-fires for it)
                // and the attempts reset, the conversation is settled. A root dialog that answered releases IDLE; a
                // signal injected while the claim was held (no resume dispatched) that landed after the reload above is
                // still waiting — re-fire once for it, exactly like a mid-turn inbound.
                $resumeAttemptsBeforeTurn = $conversation->resumeAttempts;
                $runRelease = $newInboundArrivedMidTurn && !$turnMadeRealProgress
                    ? AgentRunRelease::afterTurnWithoutRealProgress($resumeAttemptsBeforeTurn, $highestIndexBeforeTurn)
                    : AgentRunRelease::afterAnswered($newInboundArrivedMidTurn, $highestIndexBeforeTurn);
                $reFired = $this->applyRunRelease($agentRun, $conversation, $runToken, $runRelease);
                if ($runRelease->appendsStallGiveUpReply) {
                    $this->aiTrace('turn.stall.gaveUp', ['conversationId' => $conversation->id ?? null, 'attempts' => $resumeAttemptsBeforeTurn + 1]);
                }
                if (!$reFired && $runRelease->runsSettledTurnFollowUps) {
                    /** @var AIConversationTurnEndFollowUpsService $aIConversationTurnEndFollowUpsService */
                    $aIConversationTurnEndFollowUpsService = DDDService::instance()->getService(AIConversationTurnEndFollowUpsService::class);
                    $aIConversationTurnEndFollowUpsService->runFollowUpsOfAnsweredTurn($agentRun, $conversation);
                }
            }
        } catch (AiBudgetExceededException $aiBudgetExceeded) {
            // The account's AI budget is exhausted — deterministic until the budget resets or the plan
            // upgrades, so: NO retry (resumeAttempts), NO Messenger redelivery, NO rethrow. The loop already
            // persisted the AI_BUDGET_REACHED-coded ERROR message + the user-facing reply (its budget catch), so the
            // customer sees a plain answer and the FE budget widget keys on the structured code. A delegation CHILD
            // fail-folds into its batch so the parent is not stranded (same shape as the provider-error catch below).
            // A FAILED schedule run arms the fire backoff.
            $this->aiTrace('turn.failed.aiBudgetReached', ['conversationId' => $conversation->id ?? null]);
            $runRelease = AgentRunRelease::afterBudgetExhausted($conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null);
            $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
        } catch (AIScopeNotEligibleException $scopeNotEligible) {
            // The space or account stopped permitting automated work while the turn ran (a space removed mid-turn,
            // an account locked): deterministic until the scope changes, so no retry and no redelivery.
            $this->aiTrace('turn.failed.scopeNotEligible', ['conversationId' => $conversation->id ?? null, 'message' => $scopeNotEligible->getMessage()]);
            $this->releaseScopeNotEligibleOrFail($agentRun, $conversation, $runToken);
        } catch (AIModelCallFailedException $modelCallFailed) {
            // The model call returned a PROVIDER error (e.g. a Google 400 schema rejection) — NOT a stall, NOT a lost
            // claim. The MESSAGE_ERROR carrying the body is already persisted inside the loop (admin/inspect-visible).
            // Fail FAST: FAILED + a STATUS_FAILED realtime signal (the customer FE renders "there was an error" from
            // runState=FAILED), and DO NOT rethrow — a deterministic provider error must not retry (resumeAttempts) or
            // redeliver (Messenger). A delegation CHILD fail-folds into its batch so the parent isn't stranded.
            $this->aiTrace('turn.failed.providerError', ['conversationId' => $conversation->id ?? null, 'message' => $modelCallFailed->getMessage()]);
            $runRelease = AgentRunRelease::afterProviderError($conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null);
            $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
        } catch (MessageIndexLockUnavailableException $indexLockUnavailable) {
            // The message-index lock stayed unavailable through the retry — the loop logged it loudly and
            // wrote no row. Lock contention is transient, so the turn is handed to the owed-turn recovery pass:
            // released IDLE WITH the handover marker (the pass selects `IDLE AND agentTurnOwedAt IS NOT NULL`),
            // counting one attempt; only an exhausted cap ends it FAILED (the customer's Retry re-arms). No rethrow —
            // a Messenger redelivery would race the recovery pass. Never the silent claim-lost exit.
            $runRelease = AgentRunRelease::afterIndexLockUnavailable(
                $conversation->resumeAttempts,
                $conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null,
                new DateTime()
            );
            $this->aiTrace('turn.indexLockUnavailable', [
                'conversationId' => $conversation->id ?? null,
                'message' => $indexLockUnavailable->getMessage(),
                'attempt' => $runRelease->resumeAttempts,
                'overCap' => $runRelease->overCap,
            ]);
            $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
        } catch (AIModelCallTransportFailedException $modelCallTransportFailed) {
            // The model call's HTTP transfer failed (a connect or read timeout, a dropped connection, a Guzzle response
            // exception — an upstream stall at the provider ends in exactly this). Transient like the index lock: one counted attempt, released IDLE WITH the
            // handover marker so the owed-turn recovery pass re-drives it; only an exhausted cap ends it FAILED. No
            // rethrow (the resume handler would only log it), so it is logged here for the runtime-error index.
            $runRelease = AgentRunRelease::afterModelCallTransportFailure(
                $conversation->resumeAttempts,
                $conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null,
                new DateTime()
            );
            $this->aiTrace('turn.modelCallTransportFailed', [
                'conversationId' => $conversation->id ?? null,
                'message' => $modelCallTransportFailed->getMessage(),
                'attempt' => $runRelease->resumeAttempts,
                'overCap' => $runRelease->overCap,
            ]);
            \DDD\Infrastructure\Services\DDDService::instance()->getLogger()->error(
                'AIConversationAgentService: model call transport failed for conversation ' . ($conversation->id ?? 'transient')
                . ' (attempt ' . $runRelease->resumeAttempts . ($runRelease->overCap ? ', over the cap — FAILED' : ', re-driven by the owed-turn recovery') . '): '
                . $modelCallTransportFailed->getMessage(),
                ['exception' => $modelCallTransportFailed]
            );
            $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
        } catch (Throwable $turnError) {
            $this->aiTrace('turn.error', ['conversationId' => $conversation->id ?? null, 'message' => $turnError->getMessage()]);
            // Exception mid-run, after the turn's first heartbeat. Every such error counts one
            // attempt — also after real progress: the release leaves the turn OWED (IDLE + the handover marker, status
            // RUNNING) and the owed-turn recovery pass re-drives it, so a reset would re-drive a turn that progresses and
            // then throws the same way forever. Over the cap → FAILED (terminal, the customer's failed widget + Retry —
            // never silently re-run). (A claim lost inside the loop ends there silently; one lost in a step after the
            // drive — the answered path's replay write — lands here and is rethrown like any turn error, its
            // token-guarded release a no-op.)
            // A delegation CHILD that exhausted its retries fail-folds into its batch — finishDelegationChild
            // transitions this child terminal FAILED (its own step) + may settle the batch (the ALL barrier completes
            // counting the failure / a QUORUM fail-fasts), so the parent reports the failure (check_delegations
            // surfaces FAILED) instead of waiting forever. The settle OWNS the child's terminal transition — no
            // separate release. A FAILED terminal only arms the schedule backoff — an IDLE retry release is not a run terminal.
            $runRelease = AgentRunRelease::afterTurnError(
                $conversation->resumeAttempts,
                $conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null,
                new DateTime()
            );
            $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
            throw $turnError;
        } finally {
            $this->aIUsageEnvelopesService()->restoreBudgetBypassGrant($turnBudgetBypassGrantRestore);
            $this->agentAutomationEligibilityService()->restoreSupportOverride($priorSupportOverrideNote);
        }
    }

    /**
     * The resume's eligibility check: a claimed run whose space or account no longer permits
     * automated work (deleted, cancelled, locked) is released before anything is loaded — no model call, no retry
     * cycle, no failed widget for a space that is gone. The audited support override drives as before. Returns whether
     * the run was released. A failed read drives the turn: the model-call gate still refuses an ineligible scope.
     */
    protected function releaseRunOfIneligibleScope(AgentRun $agentRun, AIConversation $conversation, string $runToken): bool
    {
        if (isset($conversation->runSettings) && ($conversation->runSettings?->supportOverride ?? false)) {
            return false;
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        try {
            // the cached read on every turn start (written through by the status writers); a refusal is confirmed uncached
            if ($aIConversationsService->findAutomationEligibilityForConversation($conversation)->isEligible()) {
                return false;
            }
            $eligibility = $aIConversationsService->findAutomationEligibilityForConversation($conversation, useCache: false);
        } catch (Throwable $eligibilityReadError) {
            $this->aiTrace('turn.eligibility.readFailed', ['conversationId' => $conversation->id ?? null, 'error' => $eligibilityReadError->getMessage()]);
            return false;
        }
        if ($eligibility->isEligible()) {
            return false;
        }
        $this->aiTrace('turn.skipped.scopeNotEligible', ['conversationId' => $conversation->id ?? null, 'reason' => $eligibility->getAutomationStateReason()]);
        try {
            $this->releaseScopeNotEligible($agentRun, $conversation, $runToken, $eligibility->isScopeGoneForGood(), $eligibility->getAutomationStateReason());
        } catch (AgentRunClaimLostException $runClaimLost) {
            throw $runClaimLost;
        } catch (Throwable $releaseError) {
            // the claim stays ours: the turn is driven and the model-call gate refuses the scope inside the guarded drive
            $this->aiTrace('turn.skipped.scopeNotEligible.releaseFailed', ['conversationId' => $conversation->id ?? null, 'error' => $releaseError->getMessage()]);
            return false;
        }
        return true;
    }

    /**
     * The mid-turn release of {@see releaseScopeNotEligible()}, guarded: when that release throws (the batch fold, the
     * run release), the plain token-guarded FAILED release still ends the run — never RUNNING until the reaper; a batch
     * child then settles through the batch deadline. A lost claim is rethrown.
     *
     * @throws AgentRunClaimLostException
     */
    protected function releaseScopeNotEligibleOrFail(AgentRun $agentRun, AIConversation $conversation, string $runToken): void
    {
        try {
            $this->releaseScopeNotEligible($agentRun, $conversation, $runToken);
        } catch (AgentRunClaimLostException $runClaimLost) {
            throw $runClaimLost;
        } catch (Throwable $releaseError) {
            $this->aiTrace('turn.failed.scopeNotEligible.releaseFailed', ['conversationId' => $conversation->id ?? null, 'error' => $releaseError->getMessage()]);
            $this->applyRunRelease($agentRun, $conversation, $runToken, AgentRunRelease::afterScopeNotEligible(false, false));
        }
    }

    /**
     * Release a run whose scope is not eligible ({@see AgentRunRelease::afterScopeNotEligible()}); a scope gone for good
     * also gets its reason stamped. `$scopeGoneForGood` / `$suppressionReason` come from a read the caller already made;
     * null reads the scope uncached here (the mid-turn stop).
     */
    protected function releaseScopeNotEligible(AgentRun $agentRun, AIConversation $conversation, string $runToken, ?bool $scopeGoneForGood = null, ?string $suppressionReason = null): void
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        try {
            if ($scopeGoneForGood === null) {
                $eligibility = $aIConversationsService->findAutomationEligibilityForConversation($conversation, useCache: false);
                $scopeGoneForGood = $eligibility->isScopeGoneForGood();
                $suppressionReason = $eligibility->getAutomationStateReason();
            }
            // only a scope gone for good is stamped: the stamp is first-wins, a lifted lock would keep it forever
            if ($scopeGoneForGood && $suppressionReason !== null) {
                $aIConversationsService->recordSuppressionOfConversation($conversation, $suppressionReason);
            }
        } catch (Throwable $eligibilityReadError) {
            // the release itself never depends on the stamp
            $this->aiTrace('turn.eligibility.readFailed', ['conversationId' => $conversation->id ?? null, 'error' => $eligibilityReadError->getMessage()]);
            $scopeGoneForGood = false;
        }
        $runRelease = AgentRunRelease::afterScopeNotEligible($scopeGoneForGood, $conversation->isDelegation() && ($conversation->delegationBatchId ?? null) !== null);
        $this->releaseAfterTurnFailure($agentRun, $conversation, $runToken, $runRelease);
    }

    /**
     * The release of a worker turn that failed inside the drive (the budget, provider-error, index-lock and generic turn
     * catches of {@see resumeConversationIfReady()}): a delegation CHILD whose release finishes into its batch first
     * writes its FAILED terminal ({@see finishDelegationChild()} — the run end owns the child's terminal transition, so
     * the parent reports the failure instead of waiting forever), then the release is applied with what that terminal
     * answered (the child yielded to an input accepted after its last look). Every other conversation: the release alone.
     */
    protected function releaseAfterTurnFailure(AgentRun $agentRun, AIConversation $conversation, string $runToken, AgentRunRelease $runRelease): void
    {
        /** @var AIConversationDelegationBatchesService $aIConversationDelegationBatchesService */
        $aIConversationDelegationBatchesService = DDDService::instance()->getService(AIConversationDelegationBatchesService::class);
        $yieldedInsteadOfFailing = $runRelease->finishesIntoDelegationBatch
            && $this->finishDelegationChild($conversation, $runToken, AIConversationRunState::FAILED, $aIConversationDelegationBatchesService, $agentRun);
        $this->applyRunRelease($agentRun, $conversation, $runToken, $runRelease, $yieldedInsteadOfFailing);
    }

    /**
     * After a turn that ended with `wait_for_sub_agents`: the persisted tail is reloaded and the
     * owner input that landed while the turn ran is looked for — the same check the answered branch runs. A trailing
     * inbound is owed already; an owner row that sits before the wait call (its resume met the held claim, and a
     * completed wait call reads as the turn's end to every owed-work walk) gets the mid-turn note quoting it, which owes
     * the next turn. Returns whether owner input arrived — the release then re-fires the turn at once.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    protected function reloadAndFindOwnerInputArrivedDuringTheTurn(AgentRun $agentRun, AIConversation $conversation, int $highestIndexBeforeTurn): bool
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
        if ($conversation->hasUnansweredInboundMessage()) {
            return true;
        }
        $midTurnOwnerInboundIndexes = $conversation->messages->findOwnerInboundArrivedMidTurnIndexes($highestIndexBeforeTurn);
        if ($midTurnOwnerInboundIndexes === []) {
            return false;
        }
        $aIConversationTurnSignalsService->appendMidTurnOwnerInboundNote($agentRun, $conversation, $midTurnOwnerInboundIndexes, true);
        return true;
    }

    /**
     * The short wait at the head of a notification-started turn: only for a root conversation whose
     * newest stretch sub-agent notifications alone opened and which made no model call since (never an owner's turn,
     * never a chunk continuation). The wait itself — the polls under the held claim — is
     * {@see AIConversationDelegationNotificationsService::waitForCloseRunEndsBeforeTheFirstModelCall()}; after any wait
     * the rows are reloaded here (run ends, an owner row that arrived meanwhile), so the first model call reads them. Fail-soft: a failed wait drives the turn
     * with what it has.
     */
    protected function waitForCloseRunEndsBeforeTheFirstModelCall(AIConversation $conversation, string $runToken): void
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        if (!isset($conversation->id) || $conversation->isDelegation()) {
            return;
        }
        $currentTurn = $conversation->messages->buildCurrentTurn();
        if (!$currentTurn->isOpenedBySubAgentNotificationsOnly() || $currentTurn->hasModelCallSinceTheNewestTurnEnd()) {
            return;
        }
        try {
            $agentSubAgentRunEndQuietWait = $aIConversationDelegationNotificationsService->waitForCloseRunEndsBeforeTheFirstModelCall($conversation, $runToken);
            // any wait reloads: run ends arrived, or an owner row did (its resume met the held claim) — the first model
            // call and the turn's questions must see it
            if ($agentSubAgentRunEndQuietWait->hasRunEndsArrivedDuringWait() || $agentSubAgentRunEndQuietWait->getWaitedMilliseconds() > 0) {
                $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
            }
        } catch (Throwable $quietWaitError) {
            $this->aiTrace('turn.subAgents.quietWaitFailed', ['conversationId' => $conversation->id, 'error' => $quietWaitError->getMessage()]);
        }
    }

    /**
     * Activate the audited SUPPORT OVERRIDE of the model-call eligibility gate for this turn when the conversation's
     * run settings carry it (an admin-initiated conversation flag, never the budget lift).
     * Returns the prior override note for the restore in the caller's `finally`. No-op (returns the current note)
     * when the flag is not set.
     */
    protected function activateEligibilitySupportOverrideIfSet(AIConversation $conversation): ?string
    {
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : null;
        if (!($runSettings?->supportOverride ?? false)) {
            return $this->agentAutomationEligibilityService()->getActiveSupportOverrideNote();
        }
        return $this->agentAutomationEligibilityService()->activateSupportOverride(
            'conversation ' . ($conversation->id ?? 'new') . ' (' . ($conversation->initiatorType ?? 'unknown initiator') . ')'
        );
    }

    /**
     * GET-OR-CREATE the purposed conversation for (space, purposeName) — the singleton entry point.
     * Returns the newest STILL-ACTIVE purposed conversation of this space and purpose if one
     * exists (so a second open RESUMES it, never spawns a duplicate — parallel runs of one purpose would write their
     * rows twice and contradict each other), else STARTS a fresh one: stamps `purposeName`, applies the purpose's
     * RunSettings caps, and drives turn 1 off the purpose's kickoff SIGNAL (the agent speaks first; the customer
     * types nothing to start). A COMPLETED/CANCELLED conversation does NOT count — a new call then starts a fresh one
     * (a redo). The purpose name is validated against the closed registry; whether the account may use the
     * space is checked where the new conversation is created ({@see AIConversationsService::findConversationToDrive()}).
     *
     * The singleton of a purpose is kept PER SPACE, so this entry takes a space. A purposed conversation at account
     * level (no space) keeps no singleton: its caller drives it with {@see driveConversation()} and `purposeName`.
     *
     * The purpose's `requiredFeatureFlags` are enforced for CUSTOMER starts (a purpose outside the plan is
     * rejected); ADMIN starts stay ungated so the console can drive any purpose against any account.
     *
     * `$kickoffSignalAppendix`: text the
     * caller appends to the purpose's kickoff SIGNAL of a FRESH start — the customer's request (what the owner asked
     * for, in the owner's words) reaches turn 1 as part of the one start signal, persisted before the first dispatch.
     * Ignored when the singleton is RESUMED — the conversation alone; a caller that passes an appendix asks
     * {@see startOrResumePurposedConversation()}, which tells it whether the appendix was delivered.
     *
     * @throws BadRequestException on an unknown/unregistered purpose, and every refusal of {@see driveConversation()}.
     */
    public function getOrStartPurposedConversation(
        string $scope,
        AgentSpace $agentSpace,
        string $purposeName,
        bool $async = true,
        ?string $aiModelName = null,
        ?string $kickoffSignalAppendix = null,
        ?RunSettings $runSettings = null
    ): AIConversation {
        return $this->startOrResumePurposedConversation($scope, $agentSpace, $purposeName, $async, $aiModelName, $kickoffSignalAppendix, $runSettings)->conversation;
    }

    /**
     * {@see getOrStartPurposedConversation()} with what it did: when the singleton is RESUMED, the result says so
     * ({@see AgentPurposedConversationStart::$resumedExisting}) and the caller's kickoff appendix was NOT delivered —
     * the caller delivers it into the existing conversation via {@see AIConversationTurnSignalsService::injectSignal()} instead (that path decides
     * IDLE-ness itself).
     *
     * @throws BadRequestException on an unknown/unregistered purpose, and every refusal of {@see driveConversation()}.
     */
    public function startOrResumePurposedConversation(
        string $scope,
        AgentSpace $agentSpace,
        string $purposeName,
        bool $async = true,
        ?string $aiModelName = null,
        ?string $kickoffSignalAppendix = null,
        ?RunSettings $runSettings = null
    ): AgentPurposedConversationStart {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        $aIConversationPurpose = $aIConversationPurposeRunsService->findStartablePurposeForAgentSpace($scope, $agentSpace, $purposeName);
        $existingPurposedConversation = $aIConversationPurposeRunsService->resumeSingletonPurposedConversation($agentSpace, $purposeName, $aIConversationPurpose);
        if ($existingPurposedConversation instanceof AIConversation) {
            return AgentPurposedConversationStart::resumedExisting($existingPurposedConversation);
        }

        $aIConversationPurpose->afterPurposedStart($agentSpace);
        // Model-per-role: an explicit caller model wins; otherwise the
        // purpose's preferred model (a purpose whose sub-agent must read pictures prefers a cheap MULTIMODAL model).
        $purposedConversationModelName = $aiModelName ?? $aIConversationPurpose->preferredModelName;
        // the purpose's run-settings defaults and retention map — its agent wire profile as a
        // whole, or (a model that cannot run it) none of it; the creation below stamps and validates the result
        $purposeStartSettings = $aIConversationPurposeRunsService->buildStartSettingsForNewPurposedConversation($aIConversationPurpose, $purposedConversationModelName, $runSettings);
        // None active → start a fresh purposed conversation, driven by the kickoff signal (signal-only start: the
        // agent speaks first). purposeName is stamped at creation → the PURPOSE_DIRECTIVE segment activates.
        return AgentPurposedConversationStart::started($this->driveConversation(
            $scope,
            null,
            $agentSpace,
            '',
            aiModelName: $purposedConversationModelName,
            async: $async,
            startSignalText: ($aIConversationPurpose->kickoffSignalText ?? 'Begin this purposed conversation. Greet the owner first.')
                . ($kickoffSignalAppendix !== null && trim($kickoffSignalAppendix) !== '' ? "\n\n" . trim($kickoffSignalAppendix) : ''),
            startSignalSource: AIConversationMessage::SIGNAL_SOURCE_PURPOSE_KICKOFF,
            maxStepsPerTurn: $aIConversationPurpose->maxStepsPerTurn,
            purposeName: $purposeName,
            contextRetentionSettings: $purposeStartSettings->contextRetentionSettings,
            runSettings: $purposeStartSettings->runSettings
        ));
    }

    /**
     * Start a CUSTOMER conversation from a product entry point, or reopen the one it opened recently — in one call. The
     * key ({@see AIConversation::$entryPointKey}, e.g. `workshop.start.bookings`) is opaque to the platform: the client
     * names where the start came from, the message stays plain content. When the auth account has a conversation of
     * `$agentSpace` (null: one of its conversations at account level, never one of a space) with the same key that is
     * still recent ({@see AIConversationsService::findReopenableConversationForEntryPoint()}), that conversation is
     * returned as it is — no message appended, no turn started. Otherwise a new conversation is driven exactly like
     * {@see driveConversation()} (customer scope, async) and stamped with the key at creation.
     *
     * Two starts with the same key at the same moment (a double click, two tabs) run one after the other under the
     * entry point's start lock ({@see AIConversationsService::lockEntryPointStart()}): the look-up and the creation of
     * one start finish before the other start looks, so the second finds the conversation the first created.
     *
     * @throws BadRequestException a key that is empty, too long or holds other characters than
     *                             {@see AIConversation::ENTRY_POINT_KEY_PATTERN}; no authenticated account; and every
     *                             refusal of {@see driveConversation()}
     */
    public function startOrReopenConversationAtEntryPoint(
        string $entryPointKey,
        ?AgentSpace $agentSpace,
        string $message,
        ?string $aiModelName = null,
        ?bool $budgetBypass = null,
        ?string $startSignalText = null,
        ?string $startSignalSource = null,
        ?RunSettingsOverride $runSettingsOverride = null,
        ?OwnerDevice $ownerDevice = null
    ): AIConversationEntryPointStart {
        $entryPointKey = trim($entryPointKey);
        if ($entryPointKey === '' || mb_strlen($entryPointKey) > 255 || preg_match(AIConversation::ENTRY_POINT_KEY_PATTERN, $entryPointKey) !== 1) {
            throw new BadRequestException('An entryPointKey is 1 to 255 characters of letters, digits and . _ : -');
        }
        $account = AuthService::instance()->getAccount();
        if (!$account instanceof Account || !isset($account->id)) {
            throw new BadRequestException('Authentication is required to drive a conversation.');
        }
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        $aIConversationEntryPointStartLock = $aIConversationsService->lockEntryPointStart($account, $agentSpace, $entryPointKey);
        try {
            $reopenableConversation = $aIConversationsService->findReopenableConversationForEntryPoint($account, $agentSpace, $entryPointKey, new DateTime());
            if ($reopenableConversation instanceof AIConversation) {
                $this->aiTrace('conversation.entryPoint.reopened', ['conversationId' => $reopenableConversation->id, 'entryPointKey' => $entryPointKey]);
                return AIConversationEntryPointStart::reopened($reopenableConversation);
            }
            return AIConversationEntryPointStart::started($this->driveConversation(
                AIConversationDriveScope::CUSTOMER,
                null,
                $agentSpace,
                $message,
                $aiModelName,
                $budgetBypass,
                startSignalText: $startSignalText,
                startSignalSource: $startSignalSource,
                runSettingsOverride: $runSettingsOverride,
                ownerDevice: $ownerDevice,
                entryPointKey: $entryPointKey
            ));
        } finally {
            $aIConversationsService->unlockEntryPointStart($aIConversationEntryPointStartLock);
        }
    }

    /**
     * Generic drive harness for an {@see AIConversation} turn, parametrized by {@see AIConversationDriveScope}. Both
     * scopes share the SAME core: get/create the persisted conversation with its space attached (none: a
     * conversation at account level), append the USER message, and run the agent loop (sync inline or async via the
     * ai_conversation_turn worker). This is NOT a {@see generateAgentReply} passthrough — it replicates the CLI/eval
     * harness (the host's agent chat command) for HTTP.
     *
     * The scope gates the admin-only seams (all NO-OPs for {@see AIConversationDriveScope::CUSTOMER}):
     * - **ADMIN**: the HTTP caller is an admin, so IMPERSONATE the account the drive runs as — the existing
     *   conversation's, else `$runAsAccount`, else the owner of the space (without it the
     *   in-process MCP tools would read/write the WRONG account's data); MAY lift the budget CHECK (`$budgetBypass`,
     *   a drive-scoped grant on the open envelope, conversation-global via {@see AIConversation::$budgetBypass}); pick the
     *   model; truncate/branch; and run synchronously on an IDLE conversation.
     * - **CUSTOMER**: the caller IS the account ({@see AuthService::getAccount()}) — NO impersonation, NO
     *   rights-bypass (the rights layer confines the customer to its own conversations), budget ALWAYS enforced,
     *   model pick only from the curated customer whitelist ({@see AIConversationModelCallsService::getCustomerSelectableAgentModels()},
     *   fail-closed; omitted = agent default), no branch, and ALWAYS async (a customer never blocks the HTTP request).
     *
     * Spend counts toward the account's budget/billing like a normal turn (`ENVELOPE_AI_CONVERSATION_TURN`).
     *
     * @param string $scope {@see AIConversationDriveScope}::ADMIN | ::CUSTOMER
     * @param int|null $aiConversationId existing conversation to continue, or null to start a new one
     * @param AgentSpace|null $agentSpace the space a NEW conversation works for; null = a conversation at account
     *                                    level (whether such a start is allowed, and for which account, decides
     *                                    {@see AIConversationsService::findConversationToDrive()}). Ignored when
     *                                    continuing — an existing conversation keeps its own space, or none
     * @param string $message the USER message to append and drive
     * @param string|null $aiModelName explicit model-name override (else the agent default / tier pick). ADMIN: any
     *                                  agent-eligible model; CUSTOMER: validated fail-closed against the customer
     *                                  picker whitelist ({@see AIConversationModelCallsService::getCustomerSelectableAgentModels()})
     * @param bool|null $budgetBypass ADMIN-only opt-in to lift the AI-budget CHECK (ignored for CUSTOMER)
     * @param int|null $truncateFromIndex ADMIN-only edit/branch; ignored for CUSTOMER
     * @param bool $async dispatch to the worker (CUSTOMER is forced async; ADMIN is forced async when not IDLE)
     * @param string|null $startSignalText invisible START context: appended as a model-visible,
     *                                      user-invisible SIGNAL message BEFORE the user message, so the FIRST turn
     *                                      already has it — a dashboard widget hands over its data/state this way.
     *                                      Sanitized + capped to {@see AIConversationDriveRequest::MAX_START_SIGNAL_TEXT_CHARS}. With a
     *                                      non-empty signal the visible $message MAY be empty (signal-only start:
     *                                      the agent speaks first; the trailing SIGNAL counts as owed work via
     *                                      {@see AIConversation::hasUnansweredInboundMessage})
     * @param string|null $startSignalSource the signal provenance; defaults to
     *                                        {@see AIConversationMessage::SIGNAL_SOURCE_DASHBOARD_WIDGET_CONTEXT}
     * @param string|null $entryPointKey the product entry point a NEW conversation is opened from
     *                                    ({@see AIConversation::$entryPointKey}), stamped at creation; ignored when
     *                                    continuing. The reopen of a recent one is
     *                                    {@see startOrReopenConversationAtEntryPoint()}
     * @return AIConversation the driven conversation (messages expanded)
     * @throws BadRequestException when the account the drive runs as cannot be resolved, or a start without a space
     *                             is not allowed
     */
    public function driveConversation(
        string $scope,
        ?int $aiConversationId,
        ?AgentSpace $agentSpace,
        string $message,
        ?string $aiModelName = null,
        ?bool $budgetBypass = null,
        ?int $truncateFromIndex = null,
        bool $async = false,
        ?CompactionSettings $compactionSettings = null,
        ?ContextRetentionSettings $contextRetentionSettings = null,
        ?string $startSignalText = null,
        ?string $startSignalSource = null,
        ?string $initiatorType = null,
        ?int $maxStepsPerTurn = null,
        ?int $scheduleId = null,
        ?RunSettings $runSettings = null,
        ?string $purposeName = null,
        ?string $reportChannel = null,
        ?Account $runAsAccount = null,
        ?bool $supportOverride = null,
        ?RunSettingsOverride $runSettingsOverride = null,
        ?OwnerDevice $ownerDevice = null,
        bool $messageWrittenByPlatform = false,
        ?string $entryPointKey = null
    ): AIConversation {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        $driveRequest = AIConversationDriveRequest::buildFor($scope, $message, $startSignalText);
        $driveRequest->assertTheCustomerSentSomethingToDrive();
        $driveTarget = $aIConversationsService->findConversationToDrive($driveRequest->isAdmin, $aiConversationId, $agentSpace, $runAsAccount, $purposeName, $entryPointKey);
        $existingConversation = $driveTarget->existingConversation;
        $account = $driveTarget->account;
        // the existing conversation's own space, else the requested one; none = the conversation works at account level
        $agentSpaceOfConversation = $driveTarget->agentSpace;

        // Budget-bypass / model pick / truncate-branch are ADMIN-debug seams. They are honoured when an admin OPERATES
        // this session: the ADMIN drive scope, OR the JWT-authenticated account is itself a full-access admin, OR a
        // CUSTOMER drive where a validated admin is impersonating the customer (the host's admin login-as, resolved against
        // the authautologin cookie). isCurrentSessionAdmin() checks the JWT account FIRST — so it works cross-site /
        // cookie-less (e.g. a local FE on a different origin, where the SameSite=Lax authautologin cookie is never sent) —
        // then falls back to the impersonation cookie. A plain customer turn forces NONE of them: budget enforced,
        // agent-default model, no branch. The decision is PERSISTED on the conversation below so the async worker (which
        // has neither the JWT nor the cookie) honours it.
        $adminIsOperatingSession = $driveRequest->isAdmin || AuthService::instance()->isCurrentSessionAdmin();
        $driveRequest->decideBudgetBypass(
            $adminIsOperatingSession,
            $budgetBypass,
            $existingConversation->budgetBypass ?? false,
            $this->aIUsageEnvelopesService()->getBudgetBypassGrantForTheProcess(),
            $this->aIUsageEnvelopesService()->getBudgetBypassGrant()
        );
        $effectiveBudgetBypass = $driveRequest->effectiveBudgetBypass;
        $driveBudgetBypassGrant = $driveRequest->driveBudgetBypassGrant;

        // ADMIN impersonates (and restores in finally); CUSTOMER already runs as the account.
        $previousAuthAccount = $driveRequest->isAdmin ? AuthService::instance()->getAccount() : null;
        if ($driveRequest->isAdmin) {
            AuthService::instance()->setAccount($account);
        }

        // The lift for the drive's own work: a grant on the envelope the caller holds (a customer controller's
        // `#[WithAIEnvelope]`, the turn of a tool that starts this drive), restored in the `finally`. Without an open
        // envelope (the admin console controller, a cron or console command) nothing is granted here — the synchronous
        // turn below grants on the envelope it opens itself, and no model call can run outside an envelope.
        $driveBudgetBypassGrantRestore = $driveBudgetBypassGrant !== null
            ? $this->aIUsageEnvelopesService()->grantBudgetBypassUntilRestored($driveBudgetBypassGrant)
            : null;
        $priorSupportOverrideNote = $this->agentAutomationEligibilityService()->getActiveSupportOverrideNote();

        try {
            // a NEW conversation is stamped with its skill-loading strategy (the start's request,
            // the purpose's map, else the global default), and the combination it would run with is validated BEFORE
            // anything is persisted — an invalid start is refused with the reason, never switched silently.
            $contextRetentionSettingsForNewConversation = null;
            if (!$existingConversation instanceof AIConversation) {
                // an admin start's wire-API row is applied after the creation — the validation reads it already
                $runSettingsForValidation = $runSettings !== null ? clone $runSettings : RunSettings::newInstance();
                if ($runSettingsOverride !== null && $adminIsOperatingSession && ($runSettingsOverride->agentWireApi !== null || $runSettingsOverride->previousReasoningCarryOver !== null)) {
                    $runSettingsForValidation->applyOverride(RunSettingsOverride::fromValues(agentWireApi: $runSettingsOverride->agentWireApi, previousReasoningCarryOver: $runSettingsOverride->previousReasoningCarryOver) ?? new RunSettingsOverride(), null, new DateTime());
                }
                // a strategy taken from the global default follows the model's wire API (a Chat Completions model gets the swap)
                $contextRetentionSettingsForNewConversation = ContextRetentionSettings::buildForNewConversation($contextRetentionSettings, $runSettings?->requestedSkillLoadingStrategy, $runSettings?->requestedRebuildEpochOnSituationChange, $runSettings?->requestedStateChangeDelivery,
                    $aIConversationModelCallsService->resolveAgentWireApi($aiModelName, $runSettingsForValidation));
                $agentWireConfigurationForNewConversation = $aIConversationModelCallsService->resolveAgentWireConfigurationForNewConversation(
                    $aiModelName,
                    $runSettingsForValidation,
                    $contextRetentionSettingsForNewConversation
                );
                if ($agentWireConfigurationForNewConversation->isRejected()) {
                    throw new BadRequestException((string)$agentWireConfigurationForNewConversation->rejectionReason);
                }
            }
            // a schedule firing passes its schedule so the run freezes the SCHEDULE's zone, not the zone of the space.
            $spawningSchedule = $scheduleId !== null ? AIConversationSchedule::getService()->find($scheduleId) : null;
            $conversation = $aIConversationsService->findOrCreateConversationToDrive($existingConversation, (int)$account->id, $agentSpaceOfConversation, $spawningSchedule);

            // a USER-ABSENT TASK conversation (admin console "unattended task") — stamp the initiator
            // discriminator + the raised step budget at CREATION only (an existing conversation keeps its identity;
            // the stamps are persisted so the async worker chain reads them). Targeted persists.
            if (!$existingConversation instanceof AIConversation && isset($conversation->id)) {
                if ($initiatorType !== null) {
                    $conversation->initiatorType = $initiatorType;
                    $conversation->updatePartialIgnoringRights('initiatorType');
                }
                if ($entryPointKey !== null) {
                    $conversation->entryPointKey = $entryPointKey;
                    $conversation->updatePartialIgnoringRights('entryPointKey');
                }
                // a SCHEDULE-spawned run carries its schedule's id (run history = WHERE scheduleId)
                // and the schedule's run settings (perRunCostCapUsd already folded in by buildRunSettingsForRun).
                if ($scheduleId !== null) {
                    $conversation->scheduleId = $scheduleId;
                    $conversation->updatePartialIgnoringRights('scheduleId');
                }
                // a PURPOSED conversation carries its purpose name — stamped ONCE at creation
                // (drives the PURPOSE_DIRECTIVE segment + the RunSettings copy + the seed). Targeted persist.
                if ($purposeName !== null) {
                    $aIConversationPurposeRunsService->stampPurposeOnNewConversation($conversation, $purposeName);
                    // the purpose's WORKING-CONTEXT budget → the conversation's compaction trigger + the
                    // scaled eviction knobs, for EVERY scope (part of the purpose contract). An explicit admin
                    // compactionSettings / contextRetentionSettings sent with this drive still wins further down.
                    /** @var AIConversationPurposesService $aIConversationPurposesServiceForBudget */
                    $aIConversationPurposesServiceForBudget = DDDService::instance()->getService(AIConversationPurposesService::class);
                    $creationPurpose = $aIConversationPurposesServiceForBudget->getPurposeByName($purposeName);
                    if ($creationPurpose instanceof AIConversationPurpose) {
                        $aIConversationPurposeRunsService->applyPurposeContextBudgetToConversation(
                            $conversation,
                            $creationPurpose,
                            $aiModelName ?? ($conversation->aiModelNameOverride ?? null),
                            onlyWhereUnset: false
                        );
                    }
                }
                if ($runSettings !== null) {
                    $conversation->runSettings = $runSettings;
                    $conversation->updatePartialIgnoringRights('runSettings');
                }
                // the REPORT CHANNEL snapshot of an unattended run — stamped once at creation (a
                // later schedule edit never changes a running conversation). With EMAIL the delivery obligation
                // is armed AT SPAWN: the owed-reports recovery pass thereby becomes the universal delivery floor
                // — no terminal, crash, or model behaviour can lose the report.
                if ($reportChannel !== null && $initiatorType !== null) {
                    $conversation->reportChannel = $reportChannel;
                    $conversation->updatePartialIgnoringRights('reportChannel');
                    if ($reportChannel === AIConversationSchedule::REPORT_CHANNEL_EMAIL) {
                        $aIConversationOwnerReportDeliveryService->stampReportOwed($conversation);
                    }
                }
                if ($maxStepsPerTurn !== null) {
                    $taskRunSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
                    $taskRunSettings->maxStepsPerTurn = $maxStepsPerTurn;
                    $conversation->runSettings = $taskRunSettings;
                    $conversation->updatePartialIgnoringRights('runSettings');
                }
                // the audited SUPPORT OVERRIDE of the eligibility gate is an ADMIN decision persisted on
                // the conversation's run settings — a customer session can never set it.
                if ($supportOverride === true && $adminIsOperatingSession) {
                    $supportRunSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
                    $supportRunSettings->supportOverride = true;
                    $conversation->runSettings = $supportRunSettings;
                    $conversation->updatePartialIgnoringRights('runSettings');
                }
                // Purpose-declared context-retention policy (e.g. lenient tool-eviction knobs) — stamped
                // at CREATION for EVERY scope: these knobs are part of the purpose contract, not an admin debug
                // override. Gating them on an admin session (the block further down) left every customer/eval
                // run of the purpose on the GLOBAL eviction defaults — which evicted the write tools of a
                // loaded skill in the middle of its work and stalled the run until the model found load_tool itself.
                // always stamped now — a conversation created without settings carries the resolved
                // skill-loading strategy alone (a later change of the global default never flips a running conversation).
                $conversation->contextRetentionSettings = $contextRetentionSettingsForNewConversation;
                $conversation->updatePartialIgnoringRights('contextRetentionSettings');
                // AFTER the identity stamps: what a conversation is told about the past depends on its purpose
                // and on whether a person is present.
                $aIConversationsService->composeCreationSnapshots($conversation);
            }

            // Reconcile the PERSISTED bypass to THIS drive's effective decision — both so a (newly-toggled) admin/
            // impersonation bypass is inherited by later turns, AND (the security half) so a real customer turn can NEVER
            // inherit a bypass an impersonating admin left on the conversation: the async worker reads this persisted flag,
            // so a plain customer drive resets it to false here.
            // targeted single-column persists — these scalar flips must NEVER do a full-row update (it would
            // clobber a concurrent run-claim's runState/runToken written by the atomic SQL primitives).
            // Per-RUN obligation: a NEW inbound into an EXISTING unattended EMAIL conversation
            // starts a new run — re-arm the delivery obligation (owed restamped, delivered cleared) so this
            // run's report is claimable independently of the previous run's.
            if ($existingConversation instanceof AIConversation
                && ($conversation->reportChannel ?? null) === AIConversationSchedule::REPORT_CHANNEL_EMAIL
            ) {
                $aIConversationOwnerReportDeliveryService->rearmReportObligationForNewRun($conversation);
            }
            if ($conversation->budgetBypass !== $effectiveBudgetBypass) {
                $conversation->budgetBypass = $effectiveBudgetBypass;
                $aIConversationsService->persistBudgetBypass($conversation);
            }

            // Model pick — for EVERY scope. A null $aiModelName = a continue that didn't re-send a model → keep
            // whatever is stored (don't clobber it back to the agent default). ADMIN picks freely from the full
            // agent-eligible catalog; a CUSTOMER pick is validated FAIL-CLOSED against the curated customer picker
            // whitelist ({@see AIConversationModelCallsService::getCustomerSelectableAgentModels()}) — a client can otherwise order any premium
            // model by name straight onto its budget. Persisted so the ASYNC drive honours it (the resume worker /
            // fan-in / reaper read it; the resume message carries only the id).
            if ($aiModelName !== null) {
                // A CUSTOMER pick that is not on the curated whitelist is IGNORED, not rejected. Rejecting used to
                // throw a BadRequestException here, which meant that de-curating a model 400'd every single message
                // from any client still sending the retired name (the frontend persists the customer's pick and
                // re-sends it on every drive, including on brand-new conversations). Ignoring preserves the property
                // that actually matters — a non-whitelisted model is never applied to the customer's budget — and the
                // turn proceeds on the conversation's stored model, or the agent default when it has none.
                $applicableAiModelName = $adminIsOperatingSession
                    ? $aiModelName
                    : $aIConversationModelCallsService->getCustomerSelectableAgentModels()->getModelNameIfSelectable($aiModelName);
                if ($applicableAiModelName === null) {
                    $this->aiTrace('turn.modelPick.ignoredNotSelectable', ['conversationId' => $conversation->id ?? null, 'requestedModelName' => $aiModelName]);
                }
                if ($applicableAiModelName !== null && ($conversation->aiModelNameOverride ?? null) !== $applicableAiModelName) {
                    $conversation->aiModelNameOverride = $applicableAiModelName;
                    $aIConversationsService->persistAiModelNameOverride($conversation);
                }
            }

            // the per-conversation skill-preload switch and the two run knobs that used to be creation-only:
            // OUTSIDE the creation block, so a CONTINUE (the console composer, the impersonation panel) can set them too.
            // Gated on the admin-operating session inside the mutation; a customer's value is ignored.
            $aIConversationsService->applyAdminRunSettingsOverride($conversation, $runSettingsOverride, $adminIsOperatingSession);
            if ($adminIsOperatingSession && $existingConversation !== null) {
                $continueRunSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
                $continueRunSettingsChanged = false;
                if ($maxStepsPerTurn !== null && $continueRunSettings->maxStepsPerTurn !== $maxStepsPerTurn) {
                    $continueRunSettings->maxStepsPerTurn = $maxStepsPerTurn;
                    $continueRunSettingsChanged = true;
                }
                if ($supportOverride === true && $continueRunSettings->supportOverride !== true) {
                    $continueRunSettings->supportOverride = true; // the audited ADMIN exemption, now also on continue
                    $continueRunSettingsChanged = true;
                }
                if ($continueRunSettingsChanged) {
                    $conversation->runSettings = $continueRunSettings;
                    $conversation->updatePartialIgnoringRights('runSettings');
                }
            }

            if ($adminIsOperatingSession) {
                // Per-conversation compaction thresholds (admin debug seam): when the caller sends them — typically the
                // START endpoint forcing low thresholds on a fresh conversation so the FIRST turn already compacts —
                // persist them BEFORE the turn runs so the post-turn compaction reads the override. null = leave the
                // conversation's stored settings untouched (a continue that didn't re-send them).
                if ($compactionSettings !== null) {
                    $conversation->compactionSettings = $compactionSettings;
                    $aIConversationsService->persistCompactionSettings($conversation);
                }
                // Per-conversation context-retention thresholds (admin debug seam): the START endpoint
                // forcing LAZY tool loading / low eviction budgets on a fresh conversation so the footprint reduction is
                // testable from the first turn. null = leave the conversation's stored settings untouched.
                // A NEW conversation was stamped at creation above (with the skill-loading strategy); a CONTINUE keeps the
                // stamped strategy and refuses a change after the first model call.
                if ($contextRetentionSettings !== null && $existingConversation instanceof AIConversation) {
                    $aIConversationsService->updateContextRetentionSettings($conversation, $contextRetentionSettings);
                }
                // Edit/branch: soft-delete-from the given index (+ cascade) and reload the message set
                // with the entity-registry cache DISABLED so the append + re-drive build context from the kept tail only
                // (a cached reload returns the stale pre-truncation tail → the agent re-drives the pruned messages, the
                // failure the truncatedAt filter exists to prevent).
                if ($truncateFromIndex !== null) {
                    AIConversationMessage::getService()->truncateFrom($conversation, $truncateFromIndex);
                    $conversation->compactionNodes = $conversation->findCompactionCoverNodes() ?? AIConversationCompactionNodes::newInstance();
                    $conversation->messages = $aIConversationMessagesService->findContextTailMessages($conversation);
                    // The abandoned branch's TASK PLAN must not survive into the new
                    // branch — after truncation the re-issued message indices can REUSE the pruned initiator's index,
                    // so the stale plan's staleness stamp would MATCH the new turn and its open items would
                    // false-arm the checked stop against the new branch's first reply. Clear the plan (explicit-null
                    // slice → all plan markers NULL in the same statement) + the handover marker whenever the
                    // truncation cuts at/below the plan's initiating turn.
                    $truncatedTaskPlan = $conversation->getTaskPlan();
                    if ($truncatedTaskPlan !== null
                        && ($truncatedTaskPlan->updatedAtTurnInitiatorMessageIndex === null
                            || $truncateFromIndex <= $truncatedTaskPlan->updatedAtTurnInitiatorMessageIndex)
                    ) {
                        $aIConversationsService->persistTaskPlanSlice($conversation, null, null);
                        $conversation->agentWorkingState->taskPlan = null;
                        $conversation->agentTurnOwedAt = null;
                        $conversation->updatePartialIgnoringRights('agentTurnOwedAt');
                    }
                }
            }

            // Invisible START context: appended BEFORE the user message so the first turn's wire reads
            // `[SIGNAL:<source>] <context>` then the visible question — through the one inbound signal write, always
            // index-safe; no recall embedding (signals are never embedded). A NEW purposed conversation's kickoff
            // signal carries the purpose's preloaded (and pinned) skills (the purpose's seed) — the last message before turn 1,
            // so the loop reads THIS set as the cumulative capabilities.
            if ($driveRequest->hasStartSignalText()) {
                $kickoffPurpose = null;
                if ($purposeName !== null && !$existingConversation instanceof AIConversation) {
                    /** @var AIConversationPurposesService $aIConversationPurposesService */
                    $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
                    $kickoffPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
                }
                $aIConversationMessagesService->appendInboundSignal(
                    $conversation,
                    (string)$driveRequest->sanitizedStartSignalText,
                    $startSignalSource ?? AIConversationMessage::SIGNAL_SOURCE_DASHBOARD_WIDGET_CONTEXT,
                    loadedCapabilities: $kickoffPurpose instanceof AIConversationPurpose ? $aIConversationPurposeRunsService->buildPurposeKickoffCapabilities($conversation, $kickoffPurpose) : null,
                    isStartSignal: true
                );
            }

            // Signal-only start: no visible user message — the trailing SIGNAL is the owed work
            // ({@see AIConversation::hasUnansweredInboundMessage}), so the drive below runs a real turn.
            $ownerRowSavedWallUs = null;
            if ($driveRequest->hasOwnerText()) {
                $userMessage = $conversation->appendMessage(
                    AIConversationMessage::newInstance(),
                    AIConversationMessage::MESSAGE_TEXT,
                    $driveRequest->sanitizedUserText,
                    role: AIConversationMessage::ROLE_USER,
                    authorType: AIConversationMessage::AUTHOR_USER
                );
                // Owner channel awareness: the device the owner sent this message from — stamped by
                // the platform at append (the request's header / user agent, or the platform's own channel), never
                // by the model; the wire renders it inside the message's time bracket.
                $userMessage->ownerDevice = $ownerDevice;
                if (isset($conversation->id)) {
                    // update(0) — the user message IS the target; own columns (incl. aiConversationId,
                    // delegatedConversationId) are written at depth 0. Never re-upsert the loaded conversation tree.
                    // Written under the conversation's index lock — a worker still finishing the previous turn
                    // finds this row's index TAKEN and re-anchors its own row above it instead of merging into it.
                    $aIConversationMessagesService->persistInboundRowIndexSafe($conversation, $userMessage);
                    $ownerRowSavedWallUs = microtime(true); // the turn-start stamps' precise origin (`created` has second precision)
                    // a USER message is turn-owing inbound — stamp activity + re-arm the idle-finalize cycle.
                    $aIConversationsService->recordInboundMessageActivity($conversation);
                    // An owner-role row the PLATFORM wrote — an admin typing through the admin console, the voice model
                    // relaying a caller's task — is marked as such:
                    // a purpose that reads the owner's own words never takes it for them. The marker is per message and
                    // persisted — the drive scope is not stored anywhere.
                    if ($messageWrittenByPlatform && isset($userMessage->indexInConversation)) {
                        $agentWorkingStateForMarker = $conversation->getOrCreateAgentWorkingState();
                        $agentWorkingStateForMarker->markOwnerMessageWrittenByPlatform((int)$userMessage->indexInConversation);
                        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'platformWrittenOwnerMessageIndexes');
                    }
                    // An EXPLICIT continuation of a finished delegation child (admin
                    // Continue / Edit, a customer drive of the child) opens the sub-agent's next run right after its
                    // message landed — the run on the record, its deadline row, the child re-armed (the machine claim
                    // refuses terminal children) and admitted when the tree's capacity allows. A root,
                    // or a child whose run has not ended, is left as it is.
                    $aIConversationDelegationSpawnService->openSubAgentContinuationRun(
                        $conversation,
                        $driveRequest->sanitizedUserText,
                        $driveRequest->isAdmin ? DelegationSubAgentRun::CONTINUED_BY_ADMIN : DelegationSubAgentRun::CONTINUED_BY_OWNER
                    );
                    // This user message is persisted DIRECTLY (not via persistMessage, which is
                    // the only other place that embeds), so without this it would never get a recall embedding — the HTTP
                    // path's user messages were invisible to recall_conversation. Embed via the same async seam, best-effort.
                    if ($this->turnOptionsOutsideARun()->embedMessagesEnabled) {
                        try {
                            $aIConversationMessagesService->embedMessage($userMessage, async: $this->turnOptionsOutsideARun()->embedMessagesAsync);
                        } catch (\Throwable) {
                            // swallow — embedding is best-effort + off the critical path; the backfill command re-embeds misses.
                        }
                    }
                }
            }

            // an owner message at the drive entry resumes a run its purpose paused on the owner's input (a choice
            // the run asked for) — ANY owner message, never free-text parsing
            // ({@see AIConversationPurpose::resumeRunAfterOwnerInput()})
            if ($driveRequest->hasOwnerText() && isset($conversation->id)) {
                $conversation->findPurpose()?->resumeRunAfterOwnerInput($conversation);
            }

            // CUSTOMER is ALWAYS async. ADMIN keeps the caller's sync/async choice, but is FORCED async when the
            // conversation is NOT idle (race-guard): the sync path below drives generateAgentReply with NO run-claim —
            // safe only on a quiescent conversation. A RUNNING / AWAITING_DELEGATES parent would race the claimed worker
            // (concurrent appends → UNIQUE(index) collision) and skip the fold/consume; route it through the
            // claim-guarded resumeConversationIfReady, whose derive folds pending delegates AND processes the new user message.
            // A DELEGATION child is ALWAYS driven through the claimed worker path: the sync
            // admin drive would run generateAgentReply without a claim and without finishDelegationChild, leaving a
            // continued child IDLE — the exact state every activity read counts as "executable".
            // the audited support override (admin run setting) applies to the SYNC drive below; the async
            // path activates it inside the turn worker from the persisted run settings.
            $priorSupportOverrideNote = $this->activateEligibilitySupportOverrideIfSet($conversation);
            $driveAsync = !$driveRequest->isAdmin || $async || $conversation->runState !== AIConversationRunState::IDLE || $conversation->isDelegation();

            if ($driveAsync) {
                // Background turn: hand off to the ai_conversation_turn worker and return
                // NOW with the just-appended USER message. The dispatch runs while auth = the (impersonated, or the
                // customer's own) account, so AppMessage captures the right accountId; the worker re-establishes it,
                // opens its own envelope (budget enforced) and runs the SAME loop. The admin model pick is honoured
                // async via the persisted aiModelNameOverride. Ping RUNNING so the frontend shows the user's message +
                // the "working…" state immediately; the worker pings IDLE/FAILED at the end. The reply lands via the realtime signal.
                // The worker dispatch goes out FIRST — the synchronous Firebase ping (~80 ms) used to sit
                // between the append and the send, on the owner's waiting time; the frontend still sees RUNNING within
                // the same request.
                $this->resumeConversationIfReady($conversation, async: true, ownerRowSavedWallUs: $ownerRowSavedWallUs);
                $aIConversationRealtimeSignalService->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_RUNNING);
                $conversation->expand();
                return $conversation;
            }

            // Synchronous turn (ADMIN "wait for the reply" on an IDLE conversation). Argus AI ops require an envelope
            // (usage aggregation); open one explicitly, attributed to the impersonated account, mirroring the CLI.
            // compactAsync: this returns the reply on the HTTP response thread, so compaction is dispatched as its own
            // ai_conversation_compaction job — it must NOT block the response. Only the CLI runs it sync inline.
            $synchronousTurnEnvelope = $this->aIUsageEnvelopesService()->openEnvelope(
                $conversation->accountId ?? null,
                AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_TURN,
                AIUsageInitiator::ACCOUNT
            );
            try {
                $this->generateSynchronousReplyInsideEnvelope($conversation, $aiModelName, $driveBudgetBypassGrant, $aIConversationTurnEventsService);
            } finally {
                $this->aIUsageEnvelopesService()->closeEnvelope($synchronousTurnEnvelope);
            }

            $conversation->expand();
            return $conversation;
        } finally {
            $this->aIUsageEnvelopesService()->restoreBudgetBypassGrant($driveBudgetBypassGrantRestore);
            $this->agentAutomationEligibilityService()->restoreSupportOverride($priorSupportOverrideNote);
            if ($driveRequest->isAdmin) {
                // Restore the original (admin) auth account. getAccount() is non-null in an authenticated admin request.
                AuthService::instance()->setAccount($previousAuthAccount);
            }
        }
    }

    /**
     * The synchronous turn of {@see driveConversation()}, run inside the turn envelope that method opened: the drive's
     * budget lift reaches this envelope — inherited from the caller's envelope when one was open, else granted here (the
     * admin console controller, a console or cron caller open none) — then the chunk row and the reply.
     *
     * @param AIConversation $conversation The conversation to answer
     * @param string|null $aiModelName The model override of the drive
     * @param AIBudgetBypassGrant|null $driveBudgetBypassGrant The drive's budget lift, null when none
     * @param AIConversationTurnEventsService $aIConversationTurnEventsService The turn-event recorder of the drive
     * @return AIConversation
     */
    protected function generateSynchronousReplyInsideEnvelope(
        AIConversation $conversation,
        ?string $aiModelName,
        ?AIBudgetBypassGrant $driveBudgetBypassGrant,
        AIConversationTurnEventsService $aIConversationTurnEventsService
    ): AIConversation {
        if ($driveBudgetBypassGrant !== null && !$this->aIUsageEnvelopesService()->isBudgetBypassGranted()) {
            $this->aIUsageEnvelopesService()->grantBudgetBypassForCurrentEnvelope($driveBudgetBypassGrant);
        }
        $aIConversationTurnEventsService->recordChunkEventOfConversation($conversation, null); // the sync path never claims — its chunk row keys on the envelope
        return $this->generateAgentReply($conversation, null, false, false, false, $aiModelName, compactAsync: true);
    }

    /**
     * ADMIN-console drive — thin wrapper over the generic {@see driveConversation()} with the
     * {@see AIConversationDriveScope::ADMIN} scope (impersonation + budgetBypass + model pick + truncate/branch +
     * the synchronous path). Kept as the admin controller's entry point so its call sites are unchanged.
     */
    public function driveAdminConversation(
        ?int $aiConversationId,
        ?AgentSpace $agentSpace,
        string $message,
        ?string $aiModelName = null,
        ?bool $budgetBypass = null,
        ?int $truncateFromIndex = null,
        bool $async = false,
        ?CompactionSettings $compactionSettings = null,
        ?ContextRetentionSettings $contextRetentionSettings = null,
        ?string $initiatorType = null,
        ?int $maxStepsPerTurn = null,
        ?bool $supportOverride = null,
        ?RunSettingsOverride $runSettingsOverride = null
    ): AIConversation {
        return $this->driveConversation(
            AIConversationDriveScope::ADMIN,
            $aiConversationId,
            $agentSpace,
            $message,
            $aiModelName,
            $budgetBypass,
            $truncateFromIndex,
            $async,
            $compactionSettings,
            $contextRetentionSettings,
            initiatorType: $initiatorType,
            maxStepsPerTurn: $maxStepsPerTurn,
            supportOverride: $supportOverride,
            runSettingsOverride: $runSettingsOverride,
            // the admin console is the platform's own channel — an admin types here, never the owner from a device
            ownerDevice: OwnerDevice::adminConsole(),
            messageWrittenByPlatform: true
        );
    }

    /**
     * Runs {@see runConversationLoopSteps()} and books what the turn cost outside its message rows and tool calls —
     * the turn-start and mid-turn decision calls, the activity lines — onto the conversation
     * ({@see AIConversationsService::addOverheadCostsToConversation()}). A recording is open for the whole loop; a
     * tool call opens its own nested one and keeps its costs, a delegation child's inline turn opens its own and
     * books them on the child.
     *
     * @param bool $allowThreads whether the orchestrator may spawn sub-threads (true = main; false inside a thread → one-level nesting)
     * @return AgentReply final assistant text (verdict) + whether it ran to a real answer (vs. MAX_STEPS exhaustion)
     */
    protected function runConversationLoop(
        AgentRun $agentRun,
        AIConversation $conversation,
        bool $allowThreads,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal = false,
        bool $asyncDelegations = false,
        ?string $runToken = null
    ): AgentReply {
        // resolved before the recording opens: nothing in the `finally` may throw over the loop's own exception
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIUsageEnvelopesService = $this->aIUsageEnvelopesService();
        $turnOverheadBookedCosts = $aIUsageEnvelopesService->startRecordingBookedCosts();
        // a turn boundary — the previous turn may have ended without its own pass (a lost claim)
        $this->resolvePendingComposedViewBindingsAtTurnBoundary($conversation);
        try {
            return $this->runConversationLoopSteps($agentRun, $conversation, $allowThreads, $transientMessageIds, $modelNameOverride, $consoleStream, $emitRealtimeSignal, $asyncDelegations, $runToken);
        } finally {
            // the turn's end on EVERY exit (final text, text-less end, budget, error, a throw)
            $this->resolvePendingComposedViewBindingsAtTurnBoundary($conversation);
            $aIUsageEnvelopesService->stopRecordingBookedCosts($turnOverheadBookedCosts);
            if (isset($conversation->id)) {
                $aIConversationsService->addOverheadCostsToConversation((int)$conversation->id, $turnOverheadBookedCosts);
            }
        }
    }

    /**
     * At a turn boundary no `ComposedView` binding stays pending for a call that will not come
     * ({@see AIConversationMessagesService::resolvePendingComposedViewBindingsAtTurnBoundary()}). Best effort: it never
     * throws over the turn — a write that fails (the claim was lost) is done by the next turn's start.
     */
    protected function resolvePendingComposedViewBindingsAtTurnBoundary(AIConversation $conversation): void
    {
        try {
            /** @var AIConversationMessagesService $aIConversationMessagesService */
            $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
            $aIConversationMessagesService->resolvePendingComposedViewBindingsAtTurnBoundary($conversation);
        } catch (\Throwable) {
            // the next turn's start resolves the rows again
        }
    }

    /**
     * The agent loop, reused for the root dialog and each delegation child conversation: ONE
     * conversation = ONE context window; a delegation runs this same loop recursively on ITS conversation. The
     * offered tools derive from the conversation's last message's cumulative loaded skills each turn. Returns the
     * final assistant text (the conversation's verdict), or null if nothing was produced.
     *
     * @param bool $allowThreads whether the orchestrator may spawn sub-threads (true = main; false inside a thread → one-level nesting)
     * @return AgentReply final assistant text (verdict) + whether it ran to a real answer (vs. MAX_STEPS exhaustion)
     */
    protected function runConversationLoopSteps(
        AgentRun $agentRun,
        AIConversation $conversation,
        bool $allowThreads,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal = false,
        bool $asyncDelegations = false,
        ?string $runToken = null
    ): AgentReply {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        /** @var AIConversationPurposeBackgroundWorkService $aIConversationPurposeBackgroundWorkService */
        $aIConversationPurposeBackgroundWorkService = DDDService::instance()->getService(AIConversationPurposeBackgroundWorkService::class);
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        /** @var AgentToolCallDispatchService $agentToolCallDispatchService */
        $agentToolCallDispatchService = DDDService::instance()->getService(AgentToolCallDispatchService::class);
        /** @var AgentToolResultScreeningService $agentToolResultScreeningService */
        $agentToolResultScreeningService = DDDService::instance()->getService(AgentToolResultScreeningService::class);
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
        $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = DDDService::instance()->getService(AIConversationTaskPlanRecordService::class);
        /** @var AgentFinalTextAcceptanceService $agentFinalTextAcceptanceService */
        $agentFinalTextAcceptanceService = DDDService::instance()->getService(AgentFinalTextAcceptanceService::class);
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        /** @var AgentTurnStartDecisionBatchService $agentTurnStartDecisionBatchService */
        $agentTurnStartDecisionBatchService = DDDService::instance()->getService(AgentTurnStartDecisionBatchService::class);
        /** @var AgentPlanDynamicsDecisionsService $agentPlanDynamicsDecisionsService */
        $agentPlanDynamicsDecisionsService = DDDService::instance()->getService(AgentPlanDynamicsDecisionsService::class);
        /** @var AgentStallRecoveryService $agentStallRecoveryService */
        $agentStallRecoveryService = DDDService::instance()->getService(AgentStallRecoveryService::class);
        /** @var AgentSkillPreloadDecisionsService $agentSkillPreloadDecisionsService */
        $agentSkillPreloadDecisionsService = DDDService::instance()->getService(AgentSkillPreloadDecisionsService::class);
        $finalText = null;
        // Why the step loop ended: set where the loop leaves it; none = the step budget was reached.
        $loopStopReason = null;
        $agentRun->stampTurnStartPhase('prepare');
        $agentRun->turnTimings->resetPersistAfterCarry();
        // the push-config memo is per loop: a publish of this run before the loop (the resume head) reads again
        $agentRun->pushConfigMemo = new AIConversationTaskPushNotificationConfigMemo();
        $aIConversationRealtimeSignalService->resetPushConfigMemo();

        // the REPLY-LANGUAGE decision of this owner turn: a System One decision model types what the
        // owner writes / asks for / what the standing directives prescribe, PHP resolves the recency chain and stamps
        // the working state the context build reads. Gated by the committed config (decisionModel.php), once per owner message, fail-open,
        // never throws into the loop; off = not one call, not one changed byte.
        // The speculative cleanup runs BEFORE the decision: a preloaded skill that leaves now (no use in the turn it
        // was loaded for) is on offer again for this turn's preload decision — the decision cannot name a skill that
        // is still loaded, and a skill that answered by its rules alone has no tool call that would keep it.
        $aIConversationMessagesService->cleanupUnusedSpeculativeCapabilitiesOfRun($agentRun, $conversation, $consoleStream, $emitRealtimeSignal);
        $agentRun->stampPreFirstStepPart('unusedSkillCleanup');
        $aIConversationPurposeRunsService->dropDoneCriteriaWithoutClassFromStoredTaskPlan($conversation);

        // Resolve the BASE system prompt ONCE per run: cheap, and nothing volatile remains in it — the
        // former CURRENT_DATETIME (a per-build date() that changed the cached prefix at the minute tick) was removed;
        // "now" is read from the newest user message's [time] prefix. One resolution = byte-stable prefix across steps.
        $promptStart = hrtime(true);
        $baseSystemPrompt = $aIConversationModelCallsService->resolveBaseSystemPrompt($conversation);
        $consoleStream?->writeTiming('system prompt build', (hrtime(true) - $promptStart) / 1e9);
        $agentRun->stampTurnStartPhase('systemPrompt');
        // how this run talks to the model, resolved with the run's model and validated ONCE — an
        // invalid combination ends the run before its first model call, the reason in the run's error row. A conversation
        // created before the strategy setting existed gets its strategy stamped first (and one created before the
        // state-change delivery setting existed, that one too), so a later flip of a default never switches it.
        /** @var AIConversationsService $aIConversationsServiceForStrategyStamp */
        $aIConversationsServiceForStrategyStamp = AIConversation::getService();
        $aIConversationsServiceForStrategyStamp->stampCreationSettingsOfUnstampedConversation($conversation, $modelNameOverride);
        $agentRun->agentWireConfiguration = $aIConversationModelCallsService->resolveAgentWireConfiguration($conversation, $modelNameOverride);
        if ($agentRun->agentWireConfiguration->isRejected()) {
            $aIConversationOwnerReportDeliveryService->failRunOnRejectedAgentWireConfiguration($agentRun, $conversation, $transientMessageIds, $agentRun->agentWireConfiguration, $consoleStream, $emitRealtimeSignal);
        }
        // The folded turn-start request goes out after the system prompt — a prompt build on a cache miss takes longer
        // than the decision timeout — and is applied below in its persisted place: the step budget and the mandate
        // sweep, which read nothing the decisions write, run while its answers travel.
        $turnStartDecisionsInFlight = $agentTurnStartDecisionBatchService->sendTurnStartDecisions($agentRun, $conversation, $runToken);
        $agentRun->stampTurnStartPhase('decisionsSent');

        // What runs between the send and the application must never leave the request unreceived: the provider bills
        // it, and only the receive books the usage and closes its transfers.
        try {
            // run budgets. The TOTAL step budget of one user turn ({@see RunSettings::$maxStepsPerTurn},
            // default MAX_STEPS = the single-invocation behaviour) spans ALL of the turn's worker invocations; the steps already
            // consumed are DERIVED from the persisted usage-stamped messages (crash/park/chunk-safe, no stored counter).
            // On the WORKER path (a run-claim is held) one invocation runs at most a MAX_STEPS-sized batch and YIELDS
            // when the batch is exhausted with budget remaining (chunked execution — the next invocation's claim
            // preamble reloads cover+tail fresh, picking up any compaction landed in between). Sync paths (CLI/eval/
            // HTTP-sync — no claim, no re-fire machinery) run the whole remainder inline in this one invocation.
            // Plan-adaptive: the budget is re-derived in the FOR-HEADER each iteration
            // (including post-`continue` ones) via {@see deriveInvocationStepLimit()} — a plan created at step 1
            // raises the budget of the very turn that created it; explicit RunSettings always win.
            $agentRun->stepsConsumedBeforeInvocation = $conversation->messages->buildCurrentTurn()->countModelCallSteps();
            // Initial value for the pre-loop exception paths; the for-header re-derives it every iteration.
            $agentRun->invocationStepLimit = $this->buildTurnBudget($conversation)->deriveInvocationStepLimit($agentRun->stepsConsumedBeforeInvocation, $runToken !== null);
            // What the loop carries from one step to the next: the owed tool-less answer step, the one-time prose-stall
            // exemption, the budget closing step, the candidate text's closings and the stall recovery's memory of this turn.
            $loopCorrectionState = AgentLoopCorrectionState::startForInvocation($conversation);
            $agentTurnStartDecisionBatchService->advanceTurnStartDecisions($turnStartDecisionsInFlight);

            // ONE_INSTRUCTION turn-start close-out: a new customer message ends every earlier
            // request — its still-ACTIVE ONE_INSTRUCTION mandates in this conversation flip to CONSUMED before any step
            // runs. This is what makes the delegation carve-out safe (a child skips the initiator comparison; see
            // AgentMandate::authorizesRequest): while this turn runs, only ITS OWN grant can be ACTIVE. Idempotent
            // (chunk resumes re-run it as a no-op); best-effort — a sweep failure must never kill the turn.
            $newestUserMessageForMandateSweep = $conversation->getNewestUserMessage();
            $turnInitiatorMessageIdForMandateSweep = $newestUserMessageForMandateSweep !== null
                ? ($newestUserMessageForMandateSweep->id ?? null)
                : null;
            if ($turnInitiatorMessageIdForMandateSweep !== null
                && ($conversation->accountId ?? null) !== null
                && ($conversation->rootConversationId ?? $conversation->id ?? null) !== null
            ) {
                try {
                    /** @var AgentMandatesService $agentMandatesService */
                    $agentMandatesService = DDDService::instance()->getService(AgentMandatesService::class);
                    $agentMandatesService->closeOutOneInstructionMandatesFromEarlierRequests(
                        (int)$conversation->accountId,
                        $conversation->getTreeRootConversationId(),
                        (int)$turnInitiatorMessageIdForMandateSweep
                    );
                } catch (Throwable) {
                }
            }
            $agentRun->stampPreFirstStepPart('mandateSweep');
            $agentTurnStartDecisionBatchService->applyTurnStartDecisions($turnStartDecisionsInFlight);
        } finally {
            $agentTurnStartDecisionBatchService->receiveTurnStartDecisionsNotApplied($turnStartDecisionsInFlight);
        }
        // Skill variants: resolve the conversation's SITUATION before the first model call of the turn
        // (every dimension, from the record) — the loaded-skills block renders the sections for it.
        $aIConversationPurposeRunsService->resolveAndPersistVariants($conversation, null);
        $agentRun->stampPreFirstStepPart('variants');
        $aIConversationPurposeRunsService->appendPurposeTurnStartNotice($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $agentRun->stampPreFirstStepPart('purposeNotice');

        // A turn initiated by a FRONTEND widget signal (a widget reporting that the owner finished its action) usually
        // runs no work tool, so the post-write re-sweep never fires — the seeded-leaf sweep runs at the START of such a
        // turn instead: the criteria that read that action then tick the item platform-side, and a model tick after the
        // signal is accepted by the fresh read. Cheap (open + criterion leaves only) and idempotent on chunk resumes.
        // … and on the owner's form SUBMIT: what the form states is platform evidence the plan
        // must carry before the model's first step of the turn.
        // … and on the finished-background-work signal: a background read that ended after the last turn carries
        // evidence the criteria read — the record verdict re-resolves before the model's first step.
        // Both owner rows are read as the owner's reaction (a widget click, the form submit) of the
        // newest row — never through a shown widget, so a click whose widget lies behind the compaction edge still counts.
        $newestRowOwnerReaction = $conversation->messages->buildOwnerReactionOfNewestRow();
        $turnInitiatorMessage = $conversation->messages->buildCurrentTurn()->getInitiatorMessage();
        if ($newestRowOwnerReaction?->isWidgetClickSignal() === true
            || $newestRowOwnerReaction?->isFormSubmit() === true
            || $conversation->messages->isNewestMessageBackgroundWorkFinishedSignal()
        ) {
            $aIConversationTaskPlanRecordService->reResolveOpenSeededLeavesAfterWork($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
            // The owner's click answered a widget: its leaf closes NOW (the widget closer, no decision asked), so the
            // plan the model reads first no longer says "awaiting the owner's answer — END YOUR TURN" (a model
            // announced the draft instead of writing it against that stale line).
            $aIConversationTaskPlanRecordService->closePlanLeavesAtTurnEnd($conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal, false);
            $agentRun->stampPreFirstStepPart('planLeaves');
        } elseif ($agentRun->stepsConsumedBeforeInvocation === 0
            && $turnInitiatorMessage?->isUserRoleOrOwnerAuthoredText() === true
            && !$turnInitiatorMessage->isOrchestratorTaskText()
        ) {
            // An owner's typed message ENDED the previous owner turn: the criteria that rule on an ended turn (the nod
            // to a presented draft) re-read now — no write may follow in this turn, and their leaf would otherwise stay
            // the plan's "← next" (a question the owner had answered is asked again). The turn's INITIATOR decides —
            // a platform notice or a supervisor steer may already sit below it; a resumed chunk does not re-read.
            $criterionKeysResolvedOnceAnOwnerTurnEnds = $conversation->findPurpose()?->getCriterionKeysResolvedOnceAnOwnerTurnEnds() ?? [];
            if ($criterionKeysResolvedOnceAnOwnerTurnEnds !== []) {
                $aIConversationTaskPlanRecordService->reResolveOpenSeededLeavesAfterWork($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal, null, $criterionKeysResolvedOnceAnOwnerTurnEnds);
                $agentRun->stampPreFirstStepPart('planLeaves');
            }
        }
        // AFTER the widget-signal check above (the note must not hide the click as
        // the newest row): what the platform closed at the end of the last turn, as one signal row.
        $aIConversationTurnSignalsService->appendPlanBookkeepingSignalIfPending($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $aIConversationTurnSignalsService->appendOwnerQuestionPickSignalIfTheTurnOpensOnOne($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $aIConversationTurnSignalsService->applyOwnerWidgetEditsAtTurnOpen($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $aIConversationTurnSignalsService->appendPurposeWidgetAtTurnOpen($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $aIConversationTurnSignalsService->appendContinueAfterPauseSignalIfOwed($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        // An owner who typed before the kickoff's opener reached them is answered in that opener (one opening message
        // that answers) — appended LAST so the note is the newest row the first model call reads.
        $aIConversationTurnSignalsService->appendKickoffOwnerInboundNoteIfOpenerOwed($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        $agentRun->stampPreFirstStepPart('signals');

        // The high-water mark of the persisted rows when this loop invocation started — the
        // freshness probe before a final text looks for OWNER rows above it that the loop has not seen.
        $loopStartHighestIndex = $conversation->messages->getNextIndex() - 1;

        try {
        for ($agentRun->invocationStep = 0; $agentRun->invocationStep < ($agentRun->invocationStepLimit = $this->buildTurnBudget($conversation)->deriveInvocationStepLimit($agentRun->stepsConsumedBeforeInvocation, $runToken !== null)); $agentRun->invocationStep++) {
            // Step-boundary heartbeat (worker path only): refresh runHeartbeatAt so a multi-step turn is not
            // mis-read as hung by the reaper's staleness clause (a single model call rarely exceeds the hung
            // threshold, so a touch each step keeps it live). heartbeat() returns false iff we LOST the claim (token
            // mismatch — the reaper already stole a genuinely-overrun turn) → throw AgentRunClaimLostException, caught
            // below: the loop stops SILENTLY and the new owner drives. Unified with the token-guarded-append throw
            // + the UNIQUE-collision ack-stop in persistMessage so there is ONE claim-lost exit path.
            $stepStartedAt = hrtime(true);
            if ($agentRun->turnTimings->turnStartStamps !== null && !array_key_exists('preFirstStep', $agentRun->turnTimings->turnStartStamps)) {
                $agentRun->stampTurnStartPhase('preFirstStep'); // the decision model's turn-start passes + plan dynamics before step 1
            }
            if ($runToken !== null && !$aIConversationRunClaimsService->heartbeat($conversation, $runToken)) {
                throw new AgentRunClaimLostException('Run-claim lost at step boundary (heartbeat) — another worker owns this conversation.');
            }

            // Cost cap: the turn's Σ (own steps + settled children's slice costs, estimate fallback for
            // cost-less providers) is re-derived each step boundary; a breach is a GRACEFUL terminal, never silent.
            // The cap itself is re-derived too — a plan-raised turn without an explicit cap gets the default one.
            $maxTurnCostUsd = $this->buildTurnBudget($conversation)->maxTurnCostUsd;
            if ($maxTurnCostUsd !== null && $conversation->messages->buildCurrentTurn()->getCostInUSD() >= $maxTurnCostUsd) {
                $loopStopReason = AgentLoopStopReason::costCapReached();
                break;
            }

            // A platform sub-agent run the purpose asked this turn to wait for (a read the next step builds on) ends
            // before the model's next call — bounded by the purpose's grace, never a turn of its own; its notification
            // waits for the hand-over right below.
            $aIConversationPurposeBackgroundWorkService->awaitSubAgentRunsOwedBeforeThisStepWithoutThrowing($agentRun, $conversation, $consoleStream);
            // A work signal that arrived while this turn ran is handed to
            // the model NOW, before its next call — its injection dispatched no resume.
            $aIConversationTurnSignalsService->appendSignalsThatArrivedDuringTheTurn($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
            // Whether a sub-agent still runs, read ONCE per loop and only on a turn sub-agent
            // notifications opened — the offer of `wait_for_sub_agents` reads it at every step of this loop. Read after
            // the first step's hand-over: a run end that landed during the last model call of the
            // previous run opens this turn only through the replay row the hand-over writes.
            if ($agentRun->invocationStep === 0) {
                $agentRun->hasUnfinishedSubAgentRunAtLoopStart = $allowThreads
                    && $aIConversationRunClaimsService->hasUnfinishedSubAgentRunOnANotificationTurn($conversation);
            }
            // Supervisor: a steer the tandem decided on a STEP
            // beat of THIS turn is materialized here, under the claim, before the next model call — the running loop
            // picks it up at its step boundary instead of waiting for the next owner turn.
            if ($agentRun->invocationStep > 0 && isset($conversation->id)) {
                $supervisorCheckpointRunsService->materializeUnconsumedSupervisorSteers($conversation);
            }

            // the speculative cleanup is its OWN pass before the offer is built — independent of the
            // retention layer's sweep (which runs after a response and is bypassed when retention is off).
            $aIConversationMessagesService->cleanupUnusedSpeculativeCapabilitiesOfRun($agentRun, $conversation, $consoleStream, $emitRealtimeSignal);
            // under SKILL-granular unloading the sweep runs ONCE per owner turn, at its FIRST model call
            // (chunk-safe: `$agentRun->invocationStep` restarts per worker chunk, the step count does not), BEFORE the plan-item preload —
            // so a stale group the next item needs is re-admitted by the preload, never removed under it. The turn's
            // one capability mutation then carries the cleanup, the unloading and the preloads together.
            if ($conversation->unloadsPerSkill() && $conversation->messages->buildCurrentTurn()->countModelCallSteps() === 0) {
                $aIConversationMessagesService->unloadColdCapabilitiesOfRun($agentRun, $conversation, $consoleStream, $emitRealtimeSignal);
            }
            // The plan's `← next` item names its skills — preload them before EVERY model call of a
            // purposed run (entry, after a status tick, after a plan rebuild, after the automatic re-resolution).
            $agentSkillPreloadDecisionsService->admitSkillsForNextPlanItem($conversation, $runToken, $aIConversationRunClaimsService->getRunTokenClaimedInThisProcess($conversation), $agentRun);

            $schemasStart = hrtime(true);
            $agentRun->turnTimings->currentStepPreCallMs = (int)round(($schemasStart - $stepStartedAt) / 1e6);
            $offeredToolsBeforeStepFilters = AgentOfferedTools::fromToolSchemas($aIConversationModelCallsService->buildOfferedToolSchemas($conversation, $allowThreads, null, $agentRun));
            $offeredTools = $offeredToolsBeforeStepFilters;
            // A seeded-plan purpose offers `delegate` only in its closing act — a refused
            // call still costs a model call (five of them). The plan rewrite is never offered to it: the
            // conversation's offer leaves `update_task_plan` out ({@see AIConversation::buildOfferedTools()}),
            // so the append-only epoch's tools lack it too.
            if ($aIConversationPurposeRunsService->conversationPurposeSeedsItsTaskPlan($conversation)
                && $conversation->getTaskPlan() !== null
                && !$conversation->isNextPlanItemInThePurposesClosingAct()
            ) {
                $offeredTools = $offeredTools->withoutTools([DelegateTool::NAME]);
            }
            $consoleStream?->writeTiming('tool schemas (step ' . ($agentRun->invocationStep + 1) . ')', (hrtime(true) - $schemasStart) / 1e9);
            $agentRun->turnTimings->currentStepSchemasMs = (int)round((hrtime(true) - $schemasStart) / 1e6);

            $modelStart = hrtime(true);
            $this->aiTrace('turn.step.modelCall', ['conversationId' => $conversation->id ?? null, 'step' => $agentRun->invocationStep + 1, 'model' => $modelNameOverride]);
            // Supervisor TEETH: when THIS turn's window carries a freshly materialized
            // SUPERVISOR_STEERING signal, the FIRST step runs with tool_choice='required' — the steer always ends in
            // an imperative "Do this next: <concrete move>", and a weak model's failure mode is replying to it
            // with announce-only prose ("I am loading the data…", again, no call) while gates stay open. Forcing a
            // tool call on the steer-consuming step forecloses exactly that; WHICH tool stays the model's choice with
            // the steer text in context (the wire tool_choice is the string form — 'required', not a function pin).
            // Self-limiting: the next owner turn has no steer in its window, so tool_choice returns to 'auto'.
            // WORK-SIGNAL turn: the same teeth when the newest inbound message is a work order (a kickoff signal, an application's own) under
            // an autonomous purpose — the signal is an imperative work order with no owner to converse with, and the
            // failure without it is a fabricated "everything done" prose reply with ZERO tool
            // calls on a freshly re-seeded plan, accepted as the final answer.
            // `$agentRun->invocationStep` restarts with every worker CHUNK — the force must fire on the turn's FIRST model call only
            // (a pin that re-fires at every chunk start makes the model call the same tool
            // again and again while the work behind it never happens).
            $workSignalForcesFirstStep = $agentRun->invocationStep === 0 && !$offeredTools->isEmpty()
                && $conversation->messages->buildCurrentTurn()->countModelCallSteps() === 0
                && $conversation->messages->hasWorkSignalAsNewestInboundMessage()
                && $aIConversationPurposeRunsService->conversationPurposeWorksAutonomously($conversation);
            // The pin fires on the ONE step that consumes a fresh supervisor signal (no model output after it) —
            // at the turn's first call for a steer materialized before the turn, at a later step for a step-beat steer.
            $supervisorSteerForcesFirstStep = !$offeredTools->isEmpty()
                && ($conversation->messages->hasSupervisorSignalAwaitingItsFirstModelCall() || $workSignalForcesFirstStep);
            // Under the forced first step, EXCLUDE the plan tools from the offered schemas —
            // a forced weak model absorbed the 'required' with a set_task_status tick (the cheapest call) and the
            // steered work still did not happen. load_skills/delegate STAY available: the proven
            // recovery route is forced call → "tool belongs to skill X" → load_skills → the real call.
            // A WORK-SIGNAL first step additionally drops `delegate`: on an empty run the forced call went straight
            // to a sub-agent that checks work which did not exist yet — the work tools come first, always.
            $firstStepTools = $offeredTools;
            if ($supervisorSteerForcesFirstStep) {
                $firstStepTools = $offeredTools->withoutTools($workSignalForcesFirstStep
                    ? [...AgentMetaTools::FORCED_FIRST_STEP_LEFT_OUT_NAMES, DelegateTool::NAME]
                    : AgentMetaTools::FORCED_FIRST_STEP_LEFT_OUT_NAMES);
                if ($firstStepTools->isEmpty()) {
                    $firstStepTools = $offeredTools;
                    $supervisorSteerForcesFirstStep = false;
                }
            }
            // PLAN-TICK PERSEVERATION BRAKE: after MAX_CONSECUTIVE_REJECTED_PLAN_TICKS
            // rejected `set_task_status` / `update_task_plan` calls in a row, the plan tools leave the offered schemas
            // for this step — a gate that refuses a tick by word cannot be argued with; the record ticks the item
            // when the stage tool has run. load_skills, delegate and the work tools stay.
            $rejectedPlanTickStreak = $conversation->messages->buildCurrentTurn()->countTrailingRejectedPlanToolCalls();
            $rejectedPlanTicksInTurn = $conversation->messages->buildCurrentTurn()->countRejectedPlanToolCalls();
            $planToolsHeldBackAfterRejectedTicks = false;
            if ($rejectedPlanTickStreak >= self::MAX_CONSECUTIVE_REJECTED_PLAN_TICKS
                || $rejectedPlanTicksInTurn >= self::MAX_REJECTED_PLAN_TICKS_PER_TURN
            ) {
                $workOnlyTools = $firstStepTools->withoutTools(TaskPlan::PLAN_TOOL_NAMES);
                if (!$workOnlyTools->isEmpty()) {
                    $firstStepTools = $workOnlyTools;
                    $planToolsHeldBackAfterRejectedTicks = true;
                    $this->aiTrace('turn.step.planToolsSuppressed', ['conversationId' => $conversation->id ?? null, 'step' => $agentRun->invocationStep + 1, 'rejectedStreak' => $rejectedPlanTickStreak]);
                }
            }
            // BUDGET CLOSING STEP: the turn's step budget is one model call from spent and
            // the turn has no final text yet — the model worked "calls until I have something to say" and the brake
            // would fall one call before the saying, leaving every write of the turn unmentioned and the owner with a
            // template report. The LAST allowed call runs without tools, behind the platform's record of the turn: the
            // model writes the draft, the saved values, the widget sentences and the one open question from what it
            // has. Reserved INSIDE the budget (the plan budget's base steps include the final synthesis); once per
            // owner turn across chunks (the stamp on the working state); exempt from the stall ladder below (the
            // one-time prose exemption) and from the plan-open bounce (an honest partial answer is the point).
            $loopCorrectionState->startStep($this->buildTurnBudget($conversation)->isClosingStepDue($conversation));
            if ($loopCorrectionState->budgetClosingStep) {
                $aIConversationTurnSignalsService->armBudgetClosingStep($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal, $this->buildTurnBudget($conversation)->totalMaxSteps);
            }
            $firstStepToolChoice = AgentStepToolChoice::buildForStep(
                $loopCorrectionState->consumeToolLessAnswerStep(),
                $supervisorSteerForcesFirstStep,
                $workSignalForcesFirstStep,
                $supervisorSteerForcesFirstStep ? $conversation->messages->getExpectedToolNameFromArmedSupervisorSteer() : null,
                $supervisorSteerForcesFirstStep && $workSignalForcesFirstStep ? $conversation->getAgentWorkingState()?->taskPlan?->items?->getFirstOpenDoneGateToolName() : null,
                $firstStepTools,
                $loopCorrectionState->consumeForcedToolNameForNextStep()
            );
            if ($agentRun->invocationStep === 0) {
                // This claimed turn is about to WORK — a standing stop of the build run is history
                // (after the heartbeat and cost-cap checks: a claim that never reaches a model call clears nothing).
                $aIConversationPurposeRunsService->clearPurposeRunStopOnResumption($conversation, $runToken);
            }
            // under append-only loading the narrowed set never reaches the wire (the cached `tools`
            // stay as they are; the dispatch refuses a held-back tool); under the system-prompt swap the call sends it as before
            $stepToolRestriction = AgentStepToolRestriction::forStep($agentRun->agentWireConfiguration?->usesConversationAppend() === true, $offeredToolsBeforeStepFilters, $firstStepTools, $firstStepToolChoice->toWireValue());
            if ($planToolsHeldBackAfterRejectedTicks) {
                $stepToolRestriction->markPlanToolsHeldBackAfterRejectedTicks();
            }
            $agentRun->stepToolRestriction = $stepToolRestriction->restrictsTools() ? $stepToolRestriction : null;
            $modelResponse = $aIConversationModelCallsService->performModelCallOfStep($conversation, $stepToolRestriction->toolSchemasToSend, $stepToolRestriction->toolChoiceToSend, $modelNameOverride, $baseSystemPrompt, $agentRun);
            $consoleStream?->writeTiming('model call (step ' . ($agentRun->invocationStep + 1) . ')', (hrtime(true) - $modelStart) / 1e9);
            // A result the background screening REMOVED while the model was writing this response — the
            // response was written on the injected content: discard it and run the step again on the cleaned context
            // (once per owner turn, only with a step left). The read never waits for a pending screening.
            if ($agentToolResultScreeningService->discardCandidateWrittenOnRemovedToolResult($agentRun, $conversation, $modelResponse, $transientMessageIds, $consoleStream, $emitRealtimeSignal,
                !$loopCorrectionState->budgetClosingStep && $agentRun->invocationStep + 1 < $agentRun->invocationStepLimit
            )) {
                continue;
            }
            $toolCalls = $modelResponse->toolCalls;
            if ($loopCorrectionState->budgetClosingStep && $toolCalls !== null && !$toolCalls->isEmpty()) {
                // The closing step ran with tool_choice='none'; a provider that still returned calls gets none
                // executed — there is no budget left for their results. The text, if any, is the answer; without
                // text the step terminal below delivers the template report.
                $this->aiTrace('turn.budgetClosing.toolCallsDropped', ['conversationId' => $conversation->id ?? null, 'count' => $toolCalls->count()]);
                $modelResponse->toolCalls = null;
                $toolCalls = null;
            }
            $modelName = $modelResponse->modelName;
            $proseStallExemptionApplies = $loopCorrectionState->consumeProseStallExemption(); // consumed by exactly this response

            // Provider/proxy ERROR (e.g. a Google 400 on a malformed tool schema) — the call FAILED, NOT a stall. Fail
            // FAST: persist the error + throw (the drive's catch → FAILED, no nudge, no retry). MUST be checked BEFORE
            // the stall recovery below, or the resulting empty response is mis-read as a stall and nudged to give-up.
            $aIConversationOwnerReportDeliveryService->failFastOnProviderError($agentRun, $modelResponse, $conversation, $transientMessageIds, $modelName, $consoleStream, $emitRealtimeSignal);

            // ALL loop-control state is DERIVED from this conversation's messages — pure folds, no run object
            // (the former AgentRun counters were derivable aggregates; deriving them per iteration
            // also makes crash-resume reconstruct the exact same state for free).
            // a refused or failed work call counts: the model used its skill
            $workToolRan = $conversation->messages->buildCurrentTurn()->hasWorkToolCallThatRanOrFailed();

            // The REAL (work) tools available this step = the loaded skills' tools, EXCLUDING the load_skills / delegate
            // meta-tools. Forcing a tool call only makes sense when such tools exist (the model has something useful to
            // call). With ONLY meta-tools available, forcing is pointless and harmful: a weak model, forced, just calls
            // load_skills — and since that never counts as "work", it gets forced again next step, loading the whole
            // catalog (then mis-calling writes) for a task that needed no tools at all. So: force only when work tools
            // exist, and the model must voluntarily load the skills it decides it needs (the directive guides that).
            // the PLAN tools are META — counting one as a work tool would arm the forced
            // tool_choice='required' on conversational stalls, and a forced weak model would tick the plan
            // as the cheapest call (never "work"), re-forcing forever. A forced stall must never send
            // an EMPTY owner report as the cheapest call — the send tool counts as work when the model chooses
            // it, but is never forced.
            $workTools = $offeredTools->withoutTools(AgentMetaTools::NOT_WORK_FOR_THE_FORCED_STEP_NAMES);

            // Stall recovery (signal-first, universal): EMPTY, PROSE-AFTER-LOAD-SKILLS, PRE-CALL, DICTATED-BLOCK-MISSING, DIRECT-WIDGET-MISSING, WIDGET-TREE-FINDINGS or CODE-OR-FORMULA-IN-PROSE — the rules and
            // their measured reasons are on {@see AgentStallClassification}. `$noToolCall` stays a local: a forced
            // re-drive below replaces the response.
            $noToolCall = $toolCalls === null || $toolCalls->isEmpty();
            $turnHasSkillOrToolLoadCall = $conversation->messages->buildCurrentTurn()->hasSkillOrToolLoadCall();
            $stallClassification = AgentStallClassification::buildFor(
                $conversation,
                (string)($modelResponse->text ?? ''),
                noToolCall: $noToolCall,
                emittedNoText: $modelResponse->emitsNoOutput(),
                // The turn's recorded `askOwner` question (rendered UNDER the final text, so no fence test of the text
                // sees it). A model-written consent block exempts nothing: no tool asks for one.
                turnRecordedOwnerQuestion: $conversation->messages->buildCurrentTurn()->getNewestCompletedToolCallNamedSinceOwnerInbound(AskOwnerTool::NAME) !== null,
                workToolRan: $workToolRan,
                // a step the loop ran without tools (the answer step after a refused repeat, an owner-gate refusal, a
                // result that ends the purpose) offered the model no tool to call: its text is the answer that step
                // asks for, never text in place of a tool call
                workToolsOffered: $firstStepToolChoice->offersWorkToolsToCall($workTools),
                proseStallExemptionApplies: $proseStallExemptionApplies,
                turnHasSkillOrToolLoadCall: $turnHasSkillOrToolLoadCall,
                visibleProseCarriesLeakedToolCall: $modelResponse->visibleProseCarriesLeakedToolCall(),
                visibleProseCarriesPreCallToken: $modelResponse->visibleProseCarriesPreCallToken(),
                dictatedBlockNudgeSpentThisTurn: $loopCorrectionState->dictatedBlockNudgeSpentThisTurn,
                directWidgetNudgeSpentThisTurn: $loopCorrectionState->directWidgetNudgeSpentThisTurn,
                widgetTreeFindingsSpentThisTurn: $loopCorrectionState->widgetTreeFindingsSpentThisTurn,
                codeOrFormulaNudgeSpentThisTurn: $loopCorrectionState->codeOrFormulaNudgeSpentThisTurn
            );
            if ($noToolCall && !$stallClassification->emittedNoText && !$stallClassification->isStall
                && !$stallClassification->turnWidgetAwaitsTheOwnerOrEndsThePurpose
                && !$workToolRan && !$workTools->isEmpty() && $turnHasSkillOrToolLoadCall
                && !$firstStepToolChoice->letsTheModelCallTools()
            ) {
                // the text of a step that ran without tools, on a turn that loaded a skill or a tool and ran no work
                // tool, is not judged as a stall — counted here; a later check of the final text may still send it back
                $this->aiTrace('turn.stall.textOfStepWithoutToolsNotJudged', [
                    'conversationId' => $conversation->id ?? null,
                    'step' => $agentRun->invocationStep + 1,
                    'budgetClosingStep' => $loopCorrectionState->budgetClosingStep,
                ]);
            }

            // Post-send landing: an EMPTY turn in a run whose report is
            // claimed-and-delivered is NEVER a stall — the delivered OWNER_REPORT message IS the final answer.
            // Two documented failure modes made this structural: Gemini emits no prose on/after tool turns
            // (model limit, prompting doesn't fix it), and "don't repeat the report" steering demonstrably
            // produces empty turns. Without this exit the loop would nudge ×3, force a re-drive, and append a
            // give-up text BEHIND the already-visible report. A NON-empty short closing sentence flows through
            // the normal final path below. (reportDeliveredAt is per-run — re-armed on a new inbound.)
            // Content-aware: only a report WITH a body is a final answer — a merged, empty OWNER_REPORT row would
            // otherwise close the run "completed" with an empty final text.
            $ownerReportMessageAsFinal = $stallClassification->emittedNoText
                ? $conversation->messages->getNewestOwnerReportWithContentForRun($conversation->reportOwedAt ?? null)
                : null;
            if ($ownerReportMessageAsFinal !== null) {
                $finalText = $ownerReportMessageAsFinal->message;
                $loopStopReason = AgentLoopStopReason::answered();
                break;
            }

            if ($stallClassification->isStall && $loopCorrectionState->budgetClosingStep) {
                // A STALLED closing step (no text, or the leaked-call text): the budget is spent — no nudge, no
                // decision call and never the forced tool re-drive (it would run tools past the budget). The step's
                // usage rides a signal row (the budgets and the cost Σ stay complete) and the step terminal below
                // delivers the template report, exactly once.
                $aIConversationMessagesService->appendLoopSignalWithStepUsage(
                    $agentRun,
                    $conversation,
                    $transientMessageIds,
                    'The closing step produced no owner message — the platform delivers the pause report instead.',
                    AIConversationMessage::SIGNAL_SOURCE_BUDGET_CLOSING,
                    $modelName,
                    $modelResponse,
                    false,
                    $consoleStream,
                    $emitRealtimeSignal
                );
                $this->aiTrace('turn.budgetClosing.stalled', ['conversationId' => $conversation->id ?? null, 'emittedNoText' => $stallClassification->emittedNoText]);
                $loopStopReason = AgentLoopStopReason::budgetClosingStalled();
                break;
            }

            if ($stallClassification->isStall) {
                // the stall recovery — the decision at the second stall, the forced re-drive, the informative nudge —
                // is the stall recovery service's ({@see AgentStallRecoveryService::recoverStalledStep()})
                $agentStallRecoveryOutcome = $agentStallRecoveryService->recoverStalledStep($agentRun, $conversation, $transientMessageIds, $loopCorrectionState, $stallClassification, $modelResponse, $offeredTools, $modelNameOverride, $baseSystemPrompt, $runToken, $consoleStream, $emitRealtimeSignal, $offeredToolsBeforeStepFilters);
                if ($agentStallRecoveryOutcome->nudgeAppended) {
                    continue;
                }
                $modelResponse = $agentStallRecoveryOutcome->modelResponse;
                $modelName = $agentStallRecoveryOutcome->modelName;
                $noToolCall = $agentStallRecoveryOutcome->noToolCall;
            }

            // CHECKED STOP, ONE rule ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentCheckedStopPolicy::shouldBounceCheckedStop()}):
            // the model wants to END (final text, no tool call, not a stall) while its task plan — touched THIS turn, or
            // the turn was started by a work signal — still owes open items the candidate text does not close, and the
            // text hands nothing to the owner (no question, no widget awaiting the owner or ending the purpose). One
            // rejection per chunk, then the stop is accepted. The rejected text is DISCARDED (the model regenerates; the
            // customer never sees answer-then-second-answer) and its usage rides the bounce signal.
            $currentTaskPlan = $conversation->getTaskPlan();
            // The evidence the checked stop reads, gathered once: the candidate text is this final
            // text, not appended yet — the stop READS what it would close, the turn end writes the closings once.
            $checkedStopTaskPlanEvidence = $currentTaskPlan !== null ? $conversation->buildTaskPlanEvidence((string)($modelResponse->text ?? '')) : null;
            $bounceSignalsInCurrentChunk = $conversation->messages->buildCurrentTurn()->countTaskPlanOpenBouncesInCurrentChunk();
            $purposeWorksAutonomously = $aIConversationPurposeRunsService->conversationPurposeWorksAutonomously($conversation);
            $finalTextClassification = AgentFinalTextClassification::buildFor(
                noToolCall: $noToolCall,
                isStall: $stallClassification->isStall,
                turnRecordedOwnerQuestion: $stallClassification->turnRecordedOwnerQuestion,
                budgetClosingStep: $loopCorrectionState->budgetClosingStep,
                purposeWorksAutonomously: $purposeWorksAutonomously,
                closedTextBlockTypes: AIConversationMessages::findClosedWidgetTypesOfText((string)($modelResponse->text ?? '')),
                toolEmittedWidgetTypesInTurn: $conversation->messages->findToolEmittedWidgetTypesInCurrentTurn(),
                finalTextAsksTheOwner: AIConversationMessages::textEndsWithOwnerQuestion((string)($modelResponse->text ?? '')),
                // a ComposedView with a Form awaits the owner — its type alone does not
                widgetContentAwaitsOwnerInput: AIConversationMessages::textCarriesWidgetWhoseContentAwaitsOwnerInput((string)($modelResponse->text ?? ''))
                    || $conversation->messages->hasToolEmittedWidgetWhoseContentAwaitsOwnerInputInCurrentTurn()
            );
            if ($agentFinalTextAcceptanceService->bounceCandidateTextIfTheCheckedStopAsks($agentRun, $conversation, $transientMessageIds, $modelResponse, $modelName, $currentTaskPlan, $checkedStopTaskPlanEvidence, $finalTextClassification, $bounceSignalsInCurrentChunk, $loopCorrectionState->budgetClosingStep, $consoleStream, $emitRealtimeSignal)) {
                continue;
            }

            // TOOL-DECLARED OPEN OBLIGATIONS: a tool of this turn reported work
            // that is not done (a BLOCKED rebuild, a section whose required images are still empty) and no later result
            // cleared it — a final answer now would end the turn on a false "done". Bounce once per chunk with the
            // exact obligation; the model finishes it or says plainly that it is not done.
            if ($noToolCall && !$stallClassification->isStall && !$stallClassification->turnRecordedOwnerQuestion && !$loopCorrectionState->budgetClosingStep) {
                if ($aIConversationTurnSignalsService->bounceCandidateTextOnOpenToolObligations($agentRun, $conversation, $transientMessageIds, $modelResponse, $modelName, $consoleStream, $emitRealtimeSignal)) {
                    continue;
                }
            }

            // FRESHNESS RETRY: an owner row that landed while the model worked is answered by the
            // retried step, not re-asked — the note quoting the FRESH rows is the bounce (source MID_TURN_OWNER_INBOUND,
            // cap 1 per chunk, independent of the checked-stop budget); the candidate text is discarded, its usage
            // rides the note. A candidate carrying an owner-interaction widget is discarded too — the widget is
            // regenerated by the retried step, now with the owner's words in context. Never under an autonomous
            // purpose (no owner is typing into such a run).
            if ($noToolCall && !$stallClassification->isStall && !$purposeWorksAutonomously && !$loopCorrectionState->budgetClosingStep
                && $conversation->messages->buildCurrentTurn()->countMidTurnOwnerInboundNotesInCurrentChunk() < 1
            ) {
                if ($aIConversationTurnSignalsService->retryCandidateTextOnFreshOwnerInbound($agentRun, $conversation, $transientMessageIds, $loopStartHighestIndex, $modelResponse, $modelName, $consoleStream, $emitRealtimeSignal)) {
                    continue;
                }
            }

            // an ACCEPTED final answer on a FINISHED plan retires the plan into the finished set (only while
            // the plan-dynamics site guides; recorded under shadow) — the next owner turn starts plan-less.
            if ($noToolCall && !$stallClassification->isStall && !$stallClassification->turnRecordedOwnerQuestion) {
                $aIConversationTaskPlanRecordService->retireFinishedTaskPlanIfDue($conversation, $runToken, $purposeWorksAutonomously);
            }

            // Deterministic purpose appendix on the ACCEPTED final answer: a purposed
            // conversation may guarantee a machine fact reaches the owner even when the model omitted it —
            // e.g. the address of what the turn produced, appended when the turn succeeded but the answer text
            // lacks it. Additive only; runs before the answer is stored so the owner-visible message carries it.
            if ($noToolCall && !$stallClassification->isStall && ($conversation->purposeName ?? null) !== null) {
                $finalAnswerAppendix = $conversation->findPurpose()
                    ?->buildFinalAnswerAppendix($conversation, (string)($modelResponse->text ?? ''));
                if ($finalAnswerAppendix !== null && $finalAnswerAppendix !== '') {
                    $modelResponse->text = ((string)($modelResponse->text ?? '')) . $finalAnswerAppendix;
                }
                $aIConversationPurposeRunsService->recordPurposeRunPlanBlockedStop($conversation, $runToken);
            }

            // BUDGET CLOSING STEP with a text: the text IS the turn's terminal message — it carries the pause report's
            // signal source (the owner's next row counts as a continue for the retention window, the delivery
            // obligation is stamped like the template report's) and, when autonomous work remains and the text does
            // not hand the turn to the owner, the deterministic pause footer (progress + "continue").
            $closingTextSignalSource = null;
            if ($loopCorrectionState->budgetClosingStep && $noToolCall && trim((string)($modelResponse->text ?? '')) !== '' && !$conversation->isDelegation()) {
                // A delegation child's text is a verdict to its parent, never an owner message — no footer, no marking.
                if ($conversation->closingTextNeedsPauseFooter((string)($modelResponse->text ?? ''))) {
                    // Autonomous work remains: the pause footer, and the pause report's source — the owner's next
                    // row is a "continue" (retention + the resume record).
                    $closingTextSignalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT;
                    $modelResponse->text = ((string)($modelResponse->text ?? '')) . "\n\n"
                        . AgentRunReport::composeFromConversation($conversation, AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP)
                            ->toPauseFooterText(invitesOwnerToContinue: $conversation->getOwnerPresence() !== OwnerPresence::ABSENT_SCHEDULED_RUN);
                    $aIConversationPurposeRunsService->recordPurposeRunStop($conversation, AgentRunReport::STOP_REASON_STEP_BUDGET_USED_UP, $runToken);
                } else {
                    // The text hands the turn to the owner (a question, an asking widget) or the plan is done: the
                    // owner's answer is a real reply — retention keeps the paused turn's results, but no resume note.
                    $closingTextSignalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT_WITH_QUESTION;
                }
                if (isset($conversation->id)) {
                    $aIConversationOwnerReportDeliveryService->stampReportOwed($conversation);
                }
            }

            // Emit + resolve the turn (assistant message, then — for a tool turn — the tool-call dispatch) onto
            // the conversation. The per-turn emit/resolve logic is service-bound (executor port, delegation
            // recursion, persistence).
            $highestMessageIndexBeforeStep = $conversation->messages->getNextIndex() - 1;
            $outcome = $this->appendTurn($agentRun, $conversation, $modelResponse, $allowThreads, $modelName, $transientMessageIds, $modelNameOverride, $consoleStream, $emitRealtimeSignal, $asyncDelegations, $closingTextSignalSource);
            // Screen the untrusted results this step produced — in the background, never waited for.
            $agentToolResultScreeningService->startScreeningOfUntrustedToolResultsOfStep($conversation, $highestMessageIndexBeforeStep);
            if ($closingTextSignalSource !== null && $outcome->isFinal && isset($conversation->id)) {
                $aIConversationOwnerReportDeliveryService->stampReportDelivered($conversation);
                $this->aiTrace('turn.budgetClosing.delivered', ['conversationId' => $conversation->id ?? null]);
            }
            // EVICTION SWEEP — TOOL granularity only: after the step, unload now-cold resident tools by
            // their cost-benefit score (skill bodies by cascade, hard-cap jetsam). Under SKILL granularity (the
            // default) the sweep ran once at the turn start above — a per-step sweep would mutate the `tools` array
            // mid-turn for no gain (the turn axis is constant within a turn).
            if (!$conversation->unloadsPerSkill()) {
                $aIConversationMessagesService->unloadColdCapabilitiesOfRun($agentRun, $conversation, $consoleStream, $emitRealtimeSignal);
            }
            if ($outcome->isFinal) {
                $finalText = AIConversationMessage::stripModelCitationMarkers((string)($modelResponse->text ?? ''));
                // An EMAIL run that ends with a text and no send_owner_report call runs ONE more step pinned to it —
                // only with a step left that is not the budget closing step (that step runs without tools)
                if ($outcome->completed && !$loopCorrectionState->budgetClosingStep
                    && $aIConversationOwnerReportDeliveryService->armForcedOwnerReportCallIfOwed($agentRun, $conversation, $loopCorrectionState, $finalText,
                        $agentRun->invocationStep + 1 < $agentRun->invocationStepLimit && !$this->buildTurnBudget($conversation)->isClosingStepDue($conversation),
                        $transientMessageIds, $consoleStream, $emitRealtimeSignal)
                ) {
                    continue;
                }
                $loopStopReason = AgentLoopStopReason::finalText($outcome->completed);
                if ($loopStopReason->isAnswered()) {
                    // With the final text persisted, the platform closes the plan leaves
                    // whose evidence is now in the transcript.
                    $aIConversationTaskPlanRecordService->closePlanLeavesAtTurnEnd($conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
                }
                break;
            }
            if ($aIConversationOwnerReportDeliveryService->settlesTurnAfterForcedOwnerReportCall($conversation, $loopCorrectionState)) {
                // The forced send_owner_report call settled the report — the text it followed is the turn's answer
                $finalText = (string)$loopCorrectionState->finalTextBeforeForcedOwnerReportCall;
                $loopStopReason = AgentLoopStopReason::finalText(true);
                $aIConversationTaskPlanRecordService->closePlanLeavesAtTurnEnd($conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
                break;
            }
            if ($outcome->endsTurnWithoutText) {
                // `Wait_for_sub_agents` ended a turn sub-agent notifications started — no text, no
                // terminal report, no plan closing; the next run end's notification resumes the conversation.
                $loopStopReason = AgentLoopStopReason::waitingForSubAgents();
                break;
            }
            // Supervisor STEP BEAT: the batch just persisted carried a write, a failed call
            // or a widget → the tandem checks it NOW, in parallel; a steer lands at a later step of this turn.
            $supervisorCheckpointRunsService->dispatchSupervisorStepBeatIfDue($conversation, $highestMessageIndexBeforeStep);
            if ($outcome->forceToolLessAnswerStep) {
                // Refused-write breaker (transported via the outcome — see AgentTurnOutcome): the NEXT step runs
                // tool_choice='none' so the model writes its closing answer instead of re-calling.
                $loopCorrectionState->forceToolLessAnswerStep = true;
            }
            if ($outcome->awaitingDelegates) {
                // This turn dispatched async delegation children. The verdict is NOT available yet — the
                // children run in their own workers and the settle resumes the parent for the fold turn. End the
                // loop here; the caller's release branch releases IDLE (fully conversational). No fallback error.
                // The turn's work is over here too — the evidence leaves close now,
                // not only after a plain final text (a turn that dispatches a read after the owner's nod ends here).
                // The turn ends as the defined text-less turn end — the platform's own COMPLETED
                // `wait_for_sub_agents` row — so each run end's notification opens a notification turn of its own.
                $aIConversationDelegationSpawnService->appendPlatformWaitForSubAgentsRow($agentRun, $conversation, $transientMessageIds, $consoleStream);
                $aIConversationTaskPlanRecordService->closePlanLeavesAtTurnEnd($conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
                $loopStopReason = AgentLoopStopReason::parkedForDelegates();
                break;
            }
            if ($outcome->repeatedToolCallAbort) {
                $loopStopReason = AgentLoopStopReason::repeatedCallAborted();
                break;
            }
        }
        } catch (AgentRunClaimLostException $runClaimLost) {
            // We lost the run-claim mid-turn (heartbeat, token-guarded append, or UNIQUE collision). Exit
            // SILENTLY — the claim-lost reason skips the step-limit fallback append below; the new owner drives.
            // The in-flight realtime signal is settled only — published nothing more, the other worker owns the document.
            $aIConversationRealtimeSignalService->settleInFlightSignal();
            $aIConversationRunClaimsService->forgetRunTokenClaimedInThisProcess($conversation);
            $loopStopReason = AgentLoopStopReason::claimLostAfter($loopStopReason);
        } catch (MessageIndexLockUnavailableException $indexLockUnavailable) {
            // The index lock could not be taken twice, so a row was NOT written
            // (a pre-dispatch persist that fails runs no tool). Never the silent claim-lost exit — nobody else drives
            // this conversation: log it loudly and let the resume boundary end the turn FAILED (visible, re-drivable).
            $agentToolCallDispatchService->logToolFailure(
                $conversation,
                'persistMessage',
                ['conversationId' => $indexLockUnavailable->conversationId, 'step' => $agentRun->invocationStep],
                $indexLockUnavailable->getMessage(),
                $indexLockUnavailable,
                $modelNameOverride
            );
            throw $indexLockUnavailable;
        } catch (AiBudgetExceededException $aiBudgetExceededException) {
            // The account's AI budget is exhausted — the model call's own budget gate threw. Persist the
            // STRUCTURED error first: messageType ERROR + signalSource = the stable AI_BUDGET_REACHED code the
            // frontends key on (never the message text — the old free-text match broke on any reword), then a plain
            // user-facing reply so the chat is never silently dead. Then rethrow: the resume boundary settles FAILED
            // WITHOUT retry (deterministic until the budget resets / the plan upgrades) — see the catch in
            // {@see resumeConversationIfReady}. Persisting here (not at the resume boundary) covers the sync CLI/HTTP
            // paths too. persistMessage stays safe under an exhausted budget (embeddings are budget-exempt + async).
            $budgetErrorMessage = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_ERROR,
                'AI budget reached — ' . $aiBudgetExceededException->getMessage(),
                authorType: AIConversationMessage::AUTHOR_AGENT,
                signalSource: AiBudgetExceededException::ERROR_CODE
            );
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $budgetErrorMessage, $consoleStream, $emitRealtimeSignal);
            $aIConversationPurposeRunsService->recordPurposeRunStop($conversation, AiBudgetExceededException::ERROR_CODE, $runToken);
            // the budget terminal's user-facing reply IS the run report — its stop reason ABSORBS the
            // budget sentence (never two terminal replies side by side), the plan state names what stands/remains.
            // Template-only composition: NO model call may run here (it would re-throw the budget exception — the
            // poison-loop class the finalize handler's exemption exists for).
            $aIConversationOwnerReportDeliveryService->deliverRunReportNow(
                $agentRun,
                $conversation,
                $transientMessageIds,
                AgentRunTerminalReport::forOwnerFacingStopSentence(AgentRunReport::STOP_REASON_MONTHLY_AI_BUDGET_USED_UP),
                $consoleStream,
                $emitRealtimeSignal
            );
            // The turn ended on its ERROR and report: the run ends still waiting are read where they stand.
            // Only here, after both rows are persisted — a write that failed above is released OWED and re-driven with
            // its run ends still waiting; the rethrown budget exception ends the run FAILED and is never re-driven.
            $aIConversationMessagesService->markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd($conversation);
            throw $aiBudgetExceededException;
        } catch (AIScopeNotEligibleException $scopeNotEligibleException) {
            // The space or account stopped permitting automated work while the turn ran: the STRUCTURED stop is
            // persisted first (messageType ERROR + signalSource = the stable AI_SCOPE_NOT_ELIGIBLE code), so the admin
            // conversation view shows why the run ended, and the purpose run records its stop. No owner reply: no model
            // call may run for this scope. Rethrown — the resume boundary releases the run without a retry.
            $scopeStopMessage = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_ERROR,
                'Automated work not permitted for this scope — ' . $scopeNotEligibleException->getMessage(),
                authorType: AIConversationMessage::AUTHOR_AGENT,
                signalSource: AIScopeNotEligibleException::ERROR_CODE
            );
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $scopeStopMessage, $consoleStream, $emitRealtimeSignal);
            $aIConversationPurposeRunsService->recordPurposeRunStop($conversation, AIScopeNotEligibleException::ERROR_CODE, $runToken);
            throw $scopeNotEligibleException;
        }
        // The loop ran out of steps without a reason of its own: the invocation's batch or the turn's budget is spent.
        $loopStopReason ??= AgentLoopStopReason::stepBudgetReached();

        // Chunk YIELD (worker path only) — decision extracted to
        // {@see self::shouldYieldInvocationForContinuation()}. Append the TURN_CONTINUATION signal as the trailing
        // message — that trailing SIGNAL is what makes the release path re-fire and the next invocation's derive
        // read owed work — and end this invocation without any fallback.
        if ($loopStopReason->shouldYieldInvocationForContinuation(
            runToken: $runToken,
            stepAtLoopExit: $agentRun->invocationStep,
            invocationStepLimit: $agentRun->invocationStepLimit,
            stepsConsumedInTurn: $conversation->messages->buildCurrentTurn()->countModelCallSteps(),
            // Re-derived — a plan created DURING this invocation raised the turn budget, so a batch-exhausted
            // invocation with plan-raised headroom YIELDS into the chunk chain instead of hitting the terminal.
            totalMaxStepsPerTurn: $this->buildTurnBudget($conversation)->totalMaxSteps
        )) {
            $continuationSignal = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_SIGNAL,
                'Checkpoint: continue working on the task above from where you stopped. Do not restart or repeat '
                . 'work that is already done — the results you already retrieved are in the messages above. Every '
                . 'text you already wrote in this turn was DELIVERED to the owner: never re-send or paraphrase it, '
                . 'write only what is new.',
                role: AIConversationMessage::ROLE_SYSTEM,
                authorType: AIConversationMessage::AUTHOR_AGENT,
                signalSource: AIConversationMessage::SIGNAL_SOURCE_TURN_CONTINUATION
            );
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $continuationSignal, $consoleStream, $emitRealtimeSignal);
            // The continuation signal IS a turn-owing inbound — stamp the activity clock so the
            // idle-finalize sweep never marks a conversation finalized BETWEEN chunks (runState is briefly IDLE at
            // each yield; without the stamp a turn longer than the idle window would be completion-compacted — floor
            // inactive on IDLE — and summary-refreshed mid-task). stampAgentTurnOwed: the yield ALSO
            // sets the handover marker — cleared by the next worker's claim; a stamp that survives the quiet window
            // means the re-fire was LOST, and the owed-turns recovery pass re-dispatches it.
            if (isset($conversation->id)) {
                /** @var AIConversationsService $aIConversationsService */
                $aIConversationsService = AIConversation::getService();
                $aIConversationsService->recordInboundMessageActivity($conversation, stampAgentTurnOwed: true);
            }
            $loopStopReason = $loopStopReason->withYieldForContinuation();
        }

        if ($loopStopReason->endsWithATerminalReport()) {
            // Loop ended without a final answer — turn step/cost budget exhausted, or aborted because the model got
            // stuck repeating an identical tool call. Always yield a user-facing result + an error event. (Skipped
            // on a lost claim: a worker that lost its run-claim mid-turn must append nothing — the new owner drives.)
            $errorMessage = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_ERROR,
                $loopStopReason->terminalErrorText(),
                authorType: AIConversationMessage::AUTHOR_AGENT
            );
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $errorMessage, $consoleStream, $emitRealtimeSignal);
            // the DATA-carried delivery obligation: stamp OWED first (crash-safe: whoever dies now,
            // the sweep finds and delivers), compose the template-only AgentRunReport (done/open/blocked paths +
            // stop reason — it degrades to an honest two-liner on a plan-less run), deliver it as the terminal
            // chat message (the report REPLACES the old generic fallback texts), stamp DELIVERED.
            $stopReason = $loopStopReason->terminalReportStopReason();
            $aIConversationPurposeRunsService->recordPurposeRunStop($conversation, $stopReason, $runToken);
            // The cut turn's landed writes ride the report ("Saved in this stretch") — the owner was never told before.
            $finalText = $aIConversationOwnerReportDeliveryService->deliverRunReportNow(
                $agentRun,
                $conversation,
                $transientMessageIds,
                AgentRunTerminalReport::forLoopStopWithSavedWrites($stopReason),
                $consoleStream,
                $emitRealtimeSignal
            )->message;
            // The turn ended on its ERROR and report (a yielded continuation never reaches here): the run ends still
            // waiting are read where they stand, after both rows are persisted — a write that failed above
            // is released OWED and re-driven with its run ends still waiting.
            $aIConversationMessagesService->markWaitingSubAgentRunEndNotificationsReadAtTerminalTurnEnd($conversation);
        }

        $agentReply = new AgentReply();
        $agentReply->text = $finalText;
        $agentReply->completed = $loopStopReason->isAnswered();
        $agentReply->yielded = $loopStopReason->isYieldedForContinuation();
        $agentReply->loopStopReason = $loopStopReason;
        return $agentReply;
    }

    /** Consecutive REJECTED plan-tool calls after which the plan tools leave the offered schemas for the step. */
    public const int MAX_CONSECUTIVE_REJECTED_PLAN_TICKS = 3;

    /** REJECTED plan-tool calls in ONE turn (reads interleaved or not) after which the plan tools leave the schemas. */
    public const int MAX_REJECTED_PLAN_TICKS_PER_TURN = 6;

    /** The current owner turn's budget (the calculation is {@see AIConversationTurnBudget}). */
    protected function buildTurnBudget(AIConversation $conversation): AIConversationTurnBudget
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        return AIConversationTurnBudget::buildForConversation($conversation, $aIConversationPurposeRunsService->conversationPurposeWorksAutonomously($conversation));
    }

    /**
     * Append one produced model turn onto the conversation — the per-turn dispatch. Assistant message
     * first; then, for a tool turn, one MESSAGE_TOOL_CALL per call, each resolved IN PLACE by {@see AgentToolCallDispatchService::dispatchToolCall()}
     * (repeat-guard → corrective/abort, else load_skills / delegate sub-loop / tool execution). Service-bound by nature:
     * it drives the executor port, sub-thread recursion, and message persistence — so it lives here. The loop calls it
     * directly so the unit-test doubles' overridden methods (appendMessage / persistMessage / delegate) apply.
     * Returns the {@see AgentTurnOutcome} the loop reads to continue / end / park.
     */
    protected function appendTurn(
        AgentRun $agentRun,
        AIConversation $conversation,
        ModelResponse $modelResponse,
        bool $allowThreads,
        ?string $modelName,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelNameOverride,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal = false,
        bool $asyncDelegations = false,
        ?string $finalTextSignalSource = null
    ): AgentTurnOutcome {
        /** @var AgentCompletedToolCallFollowUpsService $agentCompletedToolCallFollowUpsService */
        $agentCompletedToolCallFollowUpsService = DDDService::instance()->getService(AgentCompletedToolCallFollowUpsService::class);
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        /** @var AIConversationPurposeBackgroundWorkService $aIConversationPurposeBackgroundWorkService */
        $aIConversationPurposeBackgroundWorkService = DDDService::instance()->getService(AIConversationPurposeBackgroundWorkService::class);
        /** @var AIConversationDelegationNotificationsService $aIConversationDelegationNotificationsService */
        $aIConversationDelegationNotificationsService = DDDService::instance()->getService(AIConversationDelegationNotificationsService::class);
        /** @var AgentToolCallDispatchService $agentToolCallDispatchService */
        $agentToolCallDispatchService = DDDService::instance()->getService(AgentToolCallDispatchService::class);
        /** @var AIConversationOwnerReportDeliveryService $aIConversationOwnerReportDeliveryService */
        $aIConversationOwnerReportDeliveryService = DDDService::instance()->getService(AIConversationOwnerReportDeliveryService::class);
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        /** @var AIConversationTaskPlanRecordService $aIConversationTaskPlanRecordService */
        $aIConversationTaskPlanRecordService = DDDService::instance()->getService(AIConversationTaskPlanRecordService::class);
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
        $toolCalls = $modelResponse->toolCalls;
        $awaitingDelegates = false;
        // a completed `wait_for_sub_agents` call of this step ends the turn after it
        $endsTurnWithoutText = false;
        // Collected by-ref from dispatchToolCall and handed to the loop via the outcome — the loop's next step
        // then runs tool_choice='none' (the refused-write breaker's transport; see AgentTurnOutcome).
        $forceToolLessAnswerStep = false;

        // No tool calls → final text answer (or budget exhausted) = this conversation's verdict.
        if ($toolCalls === null || $toolCalls->isEmpty()) {
            // the model's leaked citation markers never reach the owner
            $finalText = $conversation->localizeProductNamesInAnswer(AIConversationMessage::stripModelCitationMarkers((string)($modelResponse->text ?? '')));
            // The model's widget blocks reach the owner as written: a widget the owner asked for again is
            // shown again — the platform never removes a block from the model's text.
            // The repeat-of-delivered-text suppression is gone: it
            // swallowed the only reply of a turn (an owner changed a selection and got silence) —
            // a repeated text is the model's to avoid, never the loop's to discard.
            $assistantMessage = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_TEXT,
                $finalText,
                role: AIConversationMessage::ROLE_ASSISTANT,
                authorType: AIConversationMessage::AUTHOR_ASSISTANT,
                requestSnapshot: $modelResponse->requestSnapshot,
                aiModelName: $modelName,
                signalSource: $finalTextSignalSource
            );
            $assistantMessage->applyUsage($modelResponse);
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $assistantMessage, $consoleStream, $emitRealtimeSignal);
            // An EMAIL run whose send_owner_report(REPORT) came before the report text: this final text IS the report.
            if (($conversation->reportChannel ?? null) === AIConversationSchedule::REPORT_CHANNEL_EMAIL) {
                $aIConversationOwnerReportDeliveryService->deliverOwnerReportWrittenAfterTheCall($agentRun, $conversation, $assistantMessage, $consoleStream, $emitRealtimeSignal);
            }
            // A widget the owner answers (a `doneRequiresWidgetAnswered` leaf) in the final text is stamped now,
            // before the next turn renders the plan.
            $aIConversationTaskPlanRecordService->stampRenderedNonBlockingOffersAndPersist($conversation);
            $aIConversationTurnSignalsService->attachOwnerQuestionWidget($agentRun, $conversation, $finalText);
            return AgentTurnOutcome::finalAnswer(trim($finalText) !== '');
        }

        // Assistant turn that issues tool calls. The model's accompanying prose ("let me read the bookings" — the
        // status line) persists as its own TEXT head ONLY when non-empty: a prose-less step gets NO empty TEXT row — the step's bookkeeping (usage stamp + request
        // snapshot) moves onto its FIRST TOOL_CALL row below. INVARIANT: exactly ONE row per model-call step
        // carries the usage stamp — the prose head XOR the first call row, never both (a double stamp would
        // double-count the step and double-bill the cost Σ).
        $turnProse = $conversation->localizeProductNamesInAnswer(AIConversationMessage::stripModelCitationMarkers((string)($modelResponse->text ?? '')));
        $proseHeadMessage = null;
        if ($turnProse !== '') {
            $proseHeadMessage = $aIConversationMessagesService->appendNewMessage(
                $conversation,
                $transientMessageIds,
                AIConversationMessage::MESSAGE_TEXT,
                $turnProse,
                role: AIConversationMessage::ROLE_ASSISTANT,
                authorType: AIConversationMessage::AUTHOR_ASSISTANT,
                requestSnapshot: $modelResponse->requestSnapshot,
                aiModelName: $modelName
            );
            $proseHeadMessage->applyUsageAsStepHead($modelResponse);
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $proseHeadMessage, $consoleStream, $emitRealtimeSignal);
        }

        $repeatedToolCallAbort = false;
        $stepHeadCallPending = $proseHeadMessage === null;
        // Emission-batch provenance (admin visibility): all calls the model emitted in THIS one step share a batch
        // id (the step's first toolCallId), so the inspector can render them as ONE "emitted in parallel" group —
        // and, via each call's executionStartedAt + durationSeconds, show that the loop EXECUTES them sequentially.
        $emittedBatchToolCalls = $toolCalls->getElements();
        $emittedBatchSize = count($emittedBatchToolCalls);
        // call first, then the report: only the forced closing call binds at once — to the final text it
        // follows; every other call binds the report written after it, never the line written beside it
        SendOwnerReportTool::bindReportSourceToCalls($emittedBatchToolCalls, $aIConversationOwnerReportDeliveryService->takeReportSourceOfForcedStep($agentRun, $conversation));

        $emittedBatchId = $emittedBatchSize > 1
            ? (($emittedBatchToolCalls[0]->toolCallId ?? null) ?: uniqid('toolBatch_', true))
            : null;

        // FORK-JOIN: the parallel-safe calls of THIS
        // response (`#[MCPToolParallelSafe]`, two or more, none the guards below would intercept) run at once, each
        // in its own child process, while the loop runs the other calls of the response inline. Their rows are
        // created PENDING here, BEFORE the children start — from then on the `toolCall` column of each such row
        // belongs to its child until the loop adopts the outcome (adoptForkedToolCallOutcome). The loop then visits
        // the inline calls first (they overlap with the children) and joins the forked ones after; the order of the
        // calls of one response carries no meaning (the model emitted them together). The fork is this step's: it
        // ends with the step.
        $forkJoinService = null;
        $agentToolCallFork = null;
        if ($emittedBatchSize > 1 && isset($conversation->id)) {
            $forkCandidates = [];
            foreach ($emittedBatchToolCalls as $candidateToolCall) {
                // a call the dispatch's guards answer without executing never forks ({@see AgentRepeatedToolCallCheck})
                if (!AgentRepeatedToolCallCheck::forToolCall($candidateToolCall, $conversation)->wouldInterceptBeforeAppend(
                    isset($conversation->messages) ? ($conversation->messages->getNewestLoadedCapabilities()?->getLoadedToolNames() ?? []) : []
                )) {
                    $forkCandidates[] = $candidateToolCall;
                }
            }
            /** @var AgentToolCallForkJoinService $forkJoinService */
            $forkJoinService = DDDService::instance()->getService(AgentToolCallForkJoinService::class);
            $forkableToolCalls = $forkJoinService->selectForkableToolCalls($forkCandidates, $conversation);
            if ($forkableToolCalls === []) {
                $forkJoinService = null;
            } else {
                $agentToolCallFork = AgentToolCallFork::forToolCallsOfEmittedBatch($forkableToolCalls, (string)$emittedBatchId);
                foreach ($forkableToolCalls as $forkableToolCall) {
                    $forkableToolCall->emittedBatchId = $emittedBatchId;
                    $forkableToolCall->emittedBatchSize = $emittedBatchSize;
                    // The step head stamp rides the FIRST persisted call row of the step (the invariant below): with
                    // forked rows persisted first, that is the first forked row.
                    $forkedCallMessage = $aIConversationMessagesService->appendPendingToolCallRow($conversation, $transientMessageIds, $forkableToolCall, $modelName, $stepHeadCallPending ? $modelResponse : null);
                    $stepHeadCallPending = false;
                    $aIConversationMessagesService->persistMessageUnderRun($agentRun, $forkedCallMessage, $consoleStream, $emitRealtimeSignal);
                    // the committed step for a reader of the root (no reader → nothing; fail-soft)
                    $agentActivityLinesService->recordStepForReaders($conversation);
                    $forkedToolCallId = (string)$forkableToolCall->toolCallId;
                    $agentToolCallFork->callMessagesByToolCallId[$forkedToolCallId] = $forkedCallMessage;
                    $agentToolCallFork->callMessageIdsByToolCallId[$forkedToolCallId] = (int)$forkedCallMessage->id;
                    $agentToolCallFork->declaredTimeoutSecondsByToolCallId[$forkedToolCallId] = $agentToolCallDispatchService->toolExecutionTimeoutSeconds((string)($forkableToolCall->name ?? ''), $conversation);
                }
                // The children start NOW when every inline call of the response only reads; with an inline call that
                // may WRITE (a non-admitted writer may write a whole row from its own load and revert a child's fresh
                // columns), they start after the inline calls completed — at the first forked call of the loop below.
                if ($forkJoinService->responseHasInlineWriteBesideTheFork($forkCandidates, $forkableToolCalls)) {
                    $agentToolCallFork->childrenStartDeferredBehindInlineWrites = true;
                } else {
                    $forkJoinService->startChildrenOfFork($agentToolCallFork, $conversation);
                }
            }
        }
        $toolCallsInProcessingOrder = $agentToolCallFork?->orderInlineCallsBeforeForkedCalls($emittedBatchToolCalls) ?? $emittedBatchToolCalls;
        try {
        foreach ($toolCallsInProcessingOrder as $toolCall) {
            $isForkedToolCall = $agentToolCallFork !== null && $agentToolCallFork->isForkedToolCall($toolCall);
            if ($isForkedToolCall && $agentToolCallFork->childrenStartDeferredBehindInlineWrites) {
                // the inline calls are done (they precede the forked ones in the processing order): start the children
                $agentToolCallFork->childrenStartDeferredBehindInlineWrites = false;
                $forkJoinService->startChildrenOfFork($agentToolCallFork, $conversation);
            }
            if ($emittedBatchId !== null) {
                $toolCall->emittedBatchId = $emittedBatchId;
                $toolCall->emittedBatchSize = $emittedBatchSize;
            }
            $loopOverheadMs = [];
            if ($isForkedToolCall) {
                $callMessage = $agentToolCallFork->callMessagesByToolCallId[(string)$toolCall->toolCallId];
            } else {
            // STEP HEAD of a prose-less step: the FIRST call row carries the step's usage
            // stamp + request snapshot — it is simultaneously the deterministic STEP-BOUNDARY MARKER the wire fold
            // and the clearing seen-boundary key on (`inputTokens > 0`). Persisted IMMEDIATELY, BEFORE the dispatch
            // below — a worker killed during a (up to 60s) tool call must not lose the stamp, or the derived
            // step/cost budget undercounts (the crash-safety contract in countModelCallSteps's
            // docblock). The post-dispatch persistMessage below re-persists the same row with its result.
            $callMessage = $aIConversationMessagesService->appendPendingToolCallRow($conversation, $transientMessageIds, $toolCall, $modelName, $stepHeadCallPending ? $modelResponse : null);
            $stepHeadCallPending = false;
            // EVERY call row is persisted PENDING right before its own execution: a customer who watches
            // reads what is being done for them while it runs, not only afterwards. The row is re-persisted with its
            // result below; the guards see what they saw before (the repeat guard counts rows of this turn, a row is
            // appended one per iteration; the read dedup and the completed-work detection read COMPLETED rows only);
            // a worker death leaves one PENDING row, as it did for the step head.
            $overheadStampAt = hrtime(true);
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $callMessage, $consoleStream, $emitRealtimeSignal);
            $loopOverheadMs['persistBefore'] = (int)round((hrtime(true) - $overheadStampAt) / 1e6);
            $loopOverheadMs['persistBeforeBreakdown'] = $agentRun->turnTimings->lastPersistBreakdownMs;
            // the committed PENDING step for a reader of the root (no reader → nothing; fail-soft)
            $agentActivityLinesService->recordStepForReaders($conversation);
            }
            // The result persist of the PREVIOUS call is stamped after its own row write, so it lands on THIS
            // call — forked calls included (a forked call has no PENDING persist, but it still carries its
            // predecessor's stamp; the field is cleared on consumption so no stamp lands twice).
            $loopOverheadMs = $agentRun->turnTimings->mergePreviousPersistAfterIntoLoopOverhead($loopOverheadMs);

            $toolStart = hrtime(true);
            // Every tool call reads and writes on a RENEWED
            // database session — a long-lived worker's connection otherwise carries a snapshot from before the
            // previous call's writes, so a read-then-write tool computes its
            // baseline from stale rows. Closing reconnects lazily on the tool's first statement; never inside a
            // transaction (none is expected to span tool calls).
            $overheadStampAt = hrtime(true);
            AppMessageHandler::renewDatabaseConnection();
            $loopOverheadMs['renew'] = (int)round((hrtime(true) - $overheadStampAt) / 1e6);
            $overheadStampAt = hrtime(true);
            // the post-write re-sweep gate: a READ earns the sweep only when THIS call wrote a widget — counted
            // as tool-emitted rows before and after the dispatch, never by row indexes (sibling calls of a parallel
            // step append rows too)
            $toolEmittedRowCountBeforeDispatch = $conversation->messages->countToolEmittedRows();
            if ($isForkedToolCall) {
                // every child exits before the first forked outcome is adopted and persisted: a persist re-encodes the
                // step's later rows, which a still-running child writes
                $forkJoinService->waitForAllChildrenOfFork($agentToolCallFork, $conversation);
                $forkJoinService->adoptForkedToolCallOutcome($agentToolCallFork, $conversation, $toolCall, $callMessage);
            } else {
            $agentToolCallAnswer = $agentToolCallDispatchService->dispatchToolCall(
                $agentRun,
                $this,
                $conversation,
                $toolCall,
                $callMessage,
                $allowThreads,
                $modelNameOverride,
                $transientMessageIds,
                $consoleStream,
                $asyncDelegations
            );
            $repeatedToolCallAbort = $repeatedToolCallAbort || $agentToolCallAnswer->repeatedToolCallAbort;
            $awaitingDelegates = $awaitingDelegates || $agentToolCallAnswer->awaitingDelegates;
            $forceToolLessAnswerStep = $forceToolLessAnswerStep || $agentToolCallAnswer->forceToolLessAnswerStep;
            }

            // the task-plan COLUMN persist happens HERE at loop level — column FIRST, then the tool-call
            // message (a missing tool message is regenerated by a re-driven turn; the reverse would leave a persisted
            // claim of an update that never landed). Token-fenced via the conversation's held claim; a claim-lost
            // throw from persistTaskPlanSlice propagates to the loop's single claim-lost exit (never swallowed as a
            // FAILED tool result, which dispatchToolCall's catch would do).
            // the plan persist of a plan-tool call, the text-less turn end, the owner report send and the work after a
            // completed call ({@see AgentCompletedToolCallFollowUpsService::runAfterStepWorkOfToolCall()})
            $agentToolCallFollowUpsOutcome = $agentCompletedToolCallFollowUpsService->runAfterStepWorkOfToolCall($agentRun, $this, $conversation, $toolCall, $callMessage, $toolEmittedRowCountBeforeDispatch, $transientMessageIds, $modelNameOverride, $consoleStream, $emitRealtimeSignal, $asyncDelegations);
            $endsTurnWithoutText = $endsTurnWithoutText || $agentToolCallFollowUpsOutcome->endsTurnWithoutText;
            $awaitingDelegates = $awaitingDelegates || $agentToolCallFollowUpsOutcome->awaitingDelegates;
            if ($agentToolCallFollowUpsOutcome->reSweepMs !== null) {
                $loopOverheadMs['reSweep'] = $agentToolCallFollowUpsOutcome->reSweepMs;
                $loopOverheadMs['planNote'] = $agentToolCallFollowUpsOutcome->planNoteMs;
            }
            // Loop-overhead stamps: the dispatch block minus the tool's own execution, then the result persist.
            $loopOverheadMs['dispatchExtra'] = max(0, (int)round((hrtime(true) - $overheadStampAt) / 1e6) - (int)round(((float)($toolCall->durationSeconds ?? 0)) * 1000)
                - ($loopOverheadMs['reSweep'] ?? 0) - ($loopOverheadMs['planNote'] ?? 0));
            $overheadStampAt = hrtime(true);
            $toolCall->loopOverheadMs = $loopOverheadMs;
            $aIConversationMessagesService->persistMessageUnderRun($agentRun, $callMessage, $consoleStream, $emitRealtimeSignal);
            $agentRun->turnTimings->stampToolCallPersistAfter($toolCall, $overheadStampAt);
            // the step's terminal state is a second line for a reader of the root
            $agentActivityLinesService->recordStepForReaders($conversation);
            $consoleStream?->writeTiming(($toolCall->name ?? '') !== '' ? $toolCall->name : 'tool', (hrtime(true) - $toolStart) / 1e9);
        }
        } finally {
            // A turn that leaves the loop early (a claim-lost throw, a repeat abort) leaves no orphan child: nobody
            // would read its row.
            if ($agentToolCallFork !== null) {
                $forkJoinService->stopAllChildren($agentToolCallFork);
            }
        }
        if ($agentToolCallFork !== null) {
            $forkJoinService->recordBatchFinished($agentToolCallFork, $conversation);
        }
        // The sub-agents this step ran inline (sync) ended already — their notifications follow the
        // step's call rows (never between a call and its result), and the next model call of this turn reads them.
        $aIConversationDelegationNotificationsService->appendNotificationsOfRunsEndedInThisStep($agentRun, $conversation, $transientMessageIds, $consoleStream, $emitRealtimeSignal);
        // The step is over — every call ran (inline and forked): the PURPOSE may now declare background warm-ups that
        // see the whole batch of writes (e.g. a read that is possible once a step stored what it reads).
        $agentCompletedToolCallFollowUpsService->startBackgroundSubAgents($agentRun, $this, $conversation, $aIConversationPurposeBackgroundWorkService->buildPurposeWarmSubAgentRequestsAfterStep(
            $agentRun,
            $conversation,
            $toolCallsInProcessingOrder,
            $asyncDelegations
        ), $transientMessageIds, $consoleStream);

        $agentTurnOutcome = AgentTurnOutcome::toolCallsIssued($repeatedToolCallAbort, $awaitingDelegates, $forceToolLessAnswerStep);
        return $endsTurnWithoutText ? $agentTurnOutcome->withTurnEndWithoutText() : $agentTurnOutcome;
    }

    /**
     * @var AgentWorkerJobReceipt|null When the worker RECEIVED the last resume job of this process (set by the Messenger handler
     *      before anything is loaded) — the origin of the turn-start stamps: everything between the owner's message and
     *      the first model call that no other stamp covers (the question it answers: "why does pickup + load take 2 s").
     */
    public static ?AgentWorkerJobReceipt $lastWorkerJobReceipt = null;

    /**
     * How the rows persisted OUTSIDE a run do their side work: the options
     * of the last {@see generateAgentReply()} entry on this instance — the default before the first one. A run carries
     * its own ({@see AgentRun::$turnOptions}).
     */
    protected ?AIConversationTurnOptions $turnOptionsOutsideARun = null;

    protected function turnOptionsOutsideARun(): AIConversationTurnOptions
    {
        return $this->turnOptionsOutsideARun ??= new AIConversationTurnOptions();
    }

    /** What a row persisted outside a run reads ({@see AgentRun::outsideARun()}): this instance's options, fresh timings. */
    protected function buildRunOutsideARun(): AgentRun
    {
        return AgentRun::outsideARun($this->turnOptionsOutsideARun());
    }

    /**
     * The reaper's UNATTENDED settle: a purposed conversation that sits IDLE with its goal reached but was never
     * closed (a failed finalize deferred the close and no further owner turn arrived — the turn that reaches the goal
     * can be the last turn) re-runs the close path of a finished turn: reconcile → finalize → close. Idempotent.
     */
    public function settlePurposeConversationIfGoalReached(AIConversation $conversation): void
    {
        /** @var AIConversationPurposeRunsService $aIConversationPurposeRunsService */
        $aIConversationPurposeRunsService = DDDService::instance()->getService(AIConversationPurposeRunsService::class);
        // an intervention the supervisor accepted but never got delivered blocks the close forever — deliver it first;
        // its turn's follow-ups close
        /** @var SupervisorCheckpointRunsService $supervisorCheckpointRunsService */
        $supervisorCheckpointRunsService = DDDService::instance()->getService(SupervisorCheckpointRunsService::class);
        if ($supervisorCheckpointRunsService->resumeSupervisedConversationIfAnInterventionWaits($conversation)) {
            return;
        }
        $aIConversationPurposeRunsService->closeConversationIfPurposeGoalReached($this->buildRunOutsideARun(), $conversation);
    }

    /** {@see AgentModelCallRunnerInterface} — the model call of the loop's own collaborators (the supervisor), through {@see performTurn()}. */
    public function performModelCall(AIConversation $conversation, array $toolSchemas, string|array|null $forceToolChoice = null, ?string $modelNameOverride = null, string $baseSystemPrompt = ''): ModelResponse
    {
        /** @var AIConversationModelCallsService $aIConversationModelCallsService */
        $aIConversationModelCallsService = DDDService::instance()->getService(AIConversationModelCallsService::class);
        return $aIConversationModelCallsService->performModelCallOfStep($conversation, $toolSchemas, $forceToolChoice, $modelNameOverride, $baseSystemPrompt);
    }

    /**
     * The ONE method that applies a worker turn's release ({@see AgentRunRelease}), in today's order: the stall
     * give-up reply, the turn-end event, the token-guarded release, the schedule's run-terminal record, the lost-wakeup
     * probe that decides the status, the realtime status (only when the release wrote the row), the lost-wakeup probe that decides only the re-fire, the async re-fire, the
     * promotion of queued children. Every probe runs AFTER the release (a signal injected between a probe and the
     * release would be lost) and every re-fire after the publish. A delegation child that finished into its batch gets
     * only the status — its terminal ({@see finishDelegationChild()}, written by the caller) owns the release;
     * `$childYieldedForAcceptedInput` is what that terminal answered. Returns whether the turn re-fired (the caller
     * runs the settled-turn follow-ups when it did not and the release asks for them).
     */
    protected function applyRunRelease(AgentRun $agentRun, AIConversation $conversation, string $runToken, AgentRunRelease $runRelease, bool $childYieldedForAcceptedInput = false): bool
    {
        /** @var AIConversationRunClaimsService $aIConversationRunClaimsService */
        $aIConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        if ($runRelease->appendsStallGiveUpReply) {
            // emitRealtimeSignal: true — this IS the async worker path, the give-up reply must tick the frontend live.
            $this->appendStallGiveUpReply($agentRun, $conversation, true);
        }
        if ($runRelease->turnEndOutcome !== null) {
            $aIConversationTurnEventsService->recordTurnEndEventOfRun($agentRun, $conversation, $runToken, $runRelease->turnEndOutcome, $runRelease->turnEndRecordedLate);
        }
        // a token-guarded release that wrote nothing (the claim was lost or already released — a second release after a
        // throw in an answered turn's follow-up) publishes no status: the row's owner, or the earlier release, already did
        $releaseWritten = true;
        if ($runRelease->runState !== null) {
            $releaseWritten = $aIConversationRunClaimsService->releaseRun($conversation, $runToken, $runRelease->runState, $runRelease->resumeAttempts, $runRelease->agentTurnOwedAt);
        }
        if ($runRelease->scheduleRunCompleted !== null) {
            $aIConversationSchedulesService->recordRunTerminalOfConversation($conversation, $runRelease->scheduleRunCompleted);
        }
        $signalWaiting = false;
        if ($runRelease->waitingSignalProbe === AgentRunRelease::WAITING_SIGNAL_PROBE_BEFORE_PUBLISH) {
            $signalWaiting = $this->isSignalWaitingAfterRelease($agentRun, $conversation, $runRelease);
        }
        $realtimeStatus = $signalWaiting ? AIConversationRealtimeSignalService::STATUS_RUNNING : $runRelease->getRealtimeStatus($childYieldedForAcceptedInput);
        if ($realtimeStatus !== null && $releaseWritten) {
            $aIConversationRealtimeSignalService->publishChangeSignal($conversation, $realtimeStatus, $agentRun->pushConfigMemo);
        }
        if ($runRelease->waitingSignalProbe === AgentRunRelease::WAITING_SIGNAL_PROBE_AFTER_PUBLISH) {
            $signalWaiting = $this->isSignalWaitingAfterRelease($agentRun, $conversation, $runRelease);
        }
        $reFired = $runRelease->reFiresAfterRelease || $signalWaiting;
        if ($reFired) {
            $this->resumeConversationIfReady($conversation, async: true);
        }
        // Level-triggered promotion: a QUEUED child stranded by a missed edge-trigger promote gets dispatched once a
        // slot frees (QUEUED is invisible to the reaper otherwise).
        if ($runRelease->promotesQueuedDelegations) {
            $aIConversationDelegationSpawnService->promoteQueuedDelegations($conversation->getTreeRootConversationId());
        }
        return $reFired;
    }

    /** The lost-wakeup probe of a release: a signal injected while the claim was held (no resume dispatched) still waits. */
    protected function isSignalWaitingAfterRelease(AgentRun $agentRun, AIConversation $conversation, AgentRunRelease $runRelease): bool
    {
        /** @var AIConversationTurnSignalsService $aIConversationTurnSignalsService */
        $aIConversationTurnSignalsService = DDDService::instance()->getService(AIConversationTurnSignalsService::class);
        $signalWaiting = $aIConversationTurnSignalsService->hasSignalWaitingForRunningTurnAboveIndex($conversation, (int)$runRelease->waitingSignalProbeAboveIndex);
        if ($signalWaiting && $runRelease->waitingSignalProbeTraceBranch !== null) {
            $this->aiTrace('turn.signalsDuringTurn.reFiredAfterRelease', ['conversationId' => $conversation->id ?? null, 'branch' => $runRelease->waitingSignalProbeTraceBranch]);
        }
        return $signalWaiting;
    }

    /**
     * A delegation child reached a terminal verdict: the run-end transaction
     * ({@see AIConversationDelegationResultsService::recordSubAgentRunEnd()} — a legacy child's terminal record and slice
     * for a row stored before per-run sub-agent records existed), the freed slot's promotion, then the run's one notification into the parent
     * ({@see AIConversationDelegationResultsService::notifyParentAfterRunEnd()} — the legacy parent resume for a legacy
     * child). Returns whether the child YIELDED to an input accepted after its last look (it continues; nothing written).
     */
    protected function finishDelegationChild(
        AIConversation $childConversation,
        string $runToken,
        string $terminalRunState,
        AIConversationDelegationBatchesService $batchesService,
        AgentRun $agentRun
    ): bool {
        /** @var AIConversationDelegationSpawnService $aIConversationDelegationSpawnService */
        $aIConversationDelegationSpawnService = DDDService::instance()->getService(AIConversationDelegationSpawnService::class);
        /** @var AIConversationDelegationResultsService $aIConversationDelegationResultsService */
        $aIConversationDelegationResultsService = DDDService::instance()->getService(AIConversationDelegationResultsService::class);
        $agentSubAgentRunEnd = $aIConversationDelegationResultsService->recordSubAgentRunEnd($agentRun, $childConversation, $runToken, $terminalRunState, $batchesService);
        if ($agentSubAgentRunEnd->yieldedForAcceptedInput) {
            return true;
        }
        if ($agentSubAgentRunEnd->rootConversationIdWithFreedSlot !== null) {
            $aIConversationDelegationSpawnService->promoteQueuedDelegations($agentSubAgentRunEnd->rootConversationIdWithFreedSlot);
        }
        $aIConversationDelegationResultsService->notifyParentAfterRunEnd($agentSubAgentRunEnd, $batchesService);
        return false;
    }

    /** Run an inline (sync) delegation child's loop — no further nesting (the one-level delegation policy). */
    public function runInlineChildTurn(AIConversation $childConversation, AgentTransientMessageIds $transientMessageIds, ?string $modelNameOverride, ?AgentConsoleStream $consoleStream, ?AgentRun $parentRun = null): void
    {
        // the child's own run: own timings, the parent's options — outside a parent run, this instance's
        $childRun = $parentRun !== null ? AgentRun::forInlineChild($parentRun) : $this->buildRunOutsideARun();
        $this->runConversationLoop($childRun, $childConversation, false, $transientMessageIds, $modelNameOverride, $consoleStream);
    }


    /**
     * The open AI usage envelopes of this process (budget-bypass grants, booked-cost recordings); the application's
     * subclass when it registers one under the `ddd-ai` id.
     *
     * @return AIUsageEnvelopesService
     */
    protected function aIUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        return $aIUsageEnvelopesService;
    }

    /**
     * The automation eligibility service (its audited support override is per process: the instance methods of the
     * resolved service forward to the application's state; the core default has none).
     */
    protected function agentAutomationEligibilityService(): AgentAutomationEligibilityService
    {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        return $agentAutomationEligibilityService;
    }
}
