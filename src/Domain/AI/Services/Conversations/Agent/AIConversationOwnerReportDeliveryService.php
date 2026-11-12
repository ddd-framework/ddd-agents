<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentWireConfiguration;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRunTerminalReport;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SendOwnerReportTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentLoopCorrectionState;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewKitSpec;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\AIModelCallFailedException;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Domain\AI\MessageHandlers\AIConversationReportEmailSendMessage;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Presentation\Mcp\Agent\AgentConsoleStream;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentEligibilityLogContext;
use DDD\Domain\AI\Services\Conversations\Eligibility\AgentAutomationEligibilityService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The owner report of a run (the report cluster of the conversations service
 * lives here): the obligation stamps (owed, re-armed, delivered, deferred, suppressed), the report the
 * run OWES the owner delivered inline at its terminal or composed and appended late when the run ended without one, the
 * `send_owner_report` call executed at loop level, a report written after its call delivered, the delivery claim
 * (claimed exactly once, a stale claim reverted), the report e-mail (composed from persisted state, sent, retried, given
 * up) and the obligation's fate on an ineligible scope. Rows of a run are written under that run
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::persistMessageUnderRun()}).
 */
class AIConversationOwnerReportDeliveryService extends Service
{
    use AITraceLogTrait;

    /**
     * How old a CONTENTLESS report-delivery claim must be (seconds) before the send tool takes it back. A live
     * deliverer appends its content within a few seconds of claiming; a claim older than this with no content behind
     * it was left by a killed process or a merged message row and will never be completed by anyone else.
     */
    protected const int OWNER_REPORT_CLAIM_STALE_AFTER_SECONDS = 10;

    /**
     * The note the forced `send_owner_report` step reads ({@see armForcedOwnerReportCallIfOwed()}): the call has no
     * accompanying message, so the platform states which message REPORT sends — the one the model just wrote.
     */
    public const string FORCED_OWNER_REPORT_CALL_NOTE = 'Your previous message IS the report: REPORT emails exactly that '
        . 'message to the owner — give only the subject. NOTHING_TO_REPORT only when the task\'s check found nothing worth '
        . 'reporting; a summary, an overview or a draft the task asked for is worth reporting.';

    /**
     * Deliver the run's report NOW, inline at its terminal (the one place for the
     * loop's stall give-up, budget, step / cost / repeat and provider-error terminals): stamp the obligation OWED first
     * (crash-safe: whoever dies from here on, the owed-reports pass delivers late), compose the report after the stamp,
     * append it as the run's OWNER_REPORT chat message, persist it under the run, stamp DELIVERED (which fires the
     * e-mail leg). The closing step's two-phase stamps and the late delivery stay with their callers.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function deliverRunReportNow(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        AgentRunTerminalReport $agentRunTerminalReport,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): AIConversationMessage {
        $this->stampReportOwed($conversation);
        $reportMessage = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TEXT,
            $agentRunTerminalReport->composeChatMessageText($conversation),
            role: AIConversationMessage::ROLE_ASSISTANT,
            authorType: AIConversationMessage::AUTHOR_ASSISTANT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT
        );
        $this->messagesService()->persistMessageUnderRun($agentRun, $reportMessage, $consoleStream, $emitRealtimeSignal);
        $this->stampReportDelivered($conversation);
        return $reportMessage;
    }

    /**
     * Fail FAST when the model call returned a provider/proxy ERROR (e.g. a Google 400 INVALID_ARGUMENT on a malformed
     * tool schema) instead of a completion (moved from the agent loop): persist a
     * `MESSAGE_ERROR` carrying the error body (so it is inspect-visible by default — no `--store-snapshot` needed; the
     * admin sees the full error), deliver the honest report inline (what stood / remains + "a technical
     * problem stopped me", stamped OWED then DELIVERED) and throw {@see AIModelCallFailedException}, which the drive's
     * catch turns into runState=FAILED with NO stall-recovery nudge, NO retry, NO Messenger redelivery (a deterministic
     * provider error won't fix itself). No-op on a normal response.
     *
     * @throws AIModelCallFailedException the model call returned a provider error
     */
    public function failFastOnProviderError(
        AgentRun $agentRun,
        ModelResponse $modelResponse,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        ?string $modelName,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        if (($modelResponse->providerError ?? null) === null) {
            return;
        }
        $errorMessage = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_ERROR,
            'Model call failed — provider error: ' . $modelResponse->providerError,
            authorType: AIConversationMessage::AUTHOR_AGENT,
            aiModelName: $modelName
        );
        $this->messagesService()->persistMessageUnderRun($agentRun, $errorMessage, $consoleStream, $emitRealtimeSignal);
        $this->aiTrace('turn.modelCall.providerError', ['conversationId' => $conversation->id ?? null, 'error' => mb_substr((string)$modelResponse->providerError, 0, 300)]);
        $this->deliverRunReportNow(
            $agentRun,
            $conversation,
            $transientMessageIds,
            AgentRunTerminalReport::forOwnerFacingStopSentence(AgentRunReport::STOP_REASON_AI_SERVICE_ERROR),
            $consoleStream,
            $emitRealtimeSignal
        );
        throw new AIModelCallFailedException((string)$modelResponse->providerError);
    }

    /**
     * A run whose wire configuration the matrix rejects ends before its first model call: the reason
     * as the run's ERROR row, the owner-facing service-error report, then {@see AIModelCallFailedException} — the
     * release path ends the run FAILED without a retry (the combination stays invalid until a setting changes).
     *
     * @throws AIModelCallFailedException always
     */
    public function failRunOnRejectedAgentWireConfiguration(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentTransientMessageIds $transientMessageIds,
        AgentWireConfiguration $agentWireConfiguration,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        $errorText = 'Agent wire configuration rejected (' . $agentWireConfiguration->wireApi . ' + ' . $agentWireConfiguration->skillLoadingStrategy . '): '
            . $agentWireConfiguration->rejectionReason;
        $errorMessage = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_ERROR,
            $errorText,
            authorType: AIConversationMessage::AUTHOR_AGENT
        );
        $this->messagesService()->persistMessageUnderRun($agentRun, $errorMessage, $consoleStream, $emitRealtimeSignal);
        $this->deliverRunReportNow(
            $agentRun,
            $conversation,
            $transientMessageIds,
            AgentRunTerminalReport::forOwnerFacingStopSentence(AgentRunReport::STOP_REASON_AI_SERVICE_ERROR),
            $consoleStream,
            $emitRealtimeSignal
        );
        throw new AIModelCallFailedException($errorText);
    }

    /**
     * The claimed late delivery's body — split out so {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::deliverOwedReportLate()} can revert the claim on failure.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function composeAndAppendOwedReport(AgentRun $agentRun, AIConversation $conversation): void
    {
        // The newest rows — the run's end, never the context tail (empty once finalize compacted
        // the run to completion: the existing report would be missed and appended twice) nor the whole history.
        $conversation->messages = $this->messagesService()->findNewestMessages($conversation, self::OWNER_REPORT_NEWEST_ROWS);
        // Append idempotency: this run's report message may ALREADY exist — an
        // email-leg failure reverts the delivered-claim AFTER the chat append (tool path / a prior sweep
        // pass), so a retry lands here again. A second chat append would double the report for the customer;
        // only the EMAIL leg needs the retry → skip the append, re-dispatch the mail, done.
        // Content-aware on purpose: an OWNER_REPORT row without a body (a merged call row) is NOT an existing report —
        // skipping the append there would re-mail the template and leave the owner without the real report.
        if ($this->messagesService()->findOwnerReportsOfCurrentRun($conversation)->hasOwnerReportWithContentForRun($conversation->reportOwedAt ?? null)) {
            $this->composeAndSendReportEmail($conversation, async: true);
            return;
        }
        // NORMAL end without an outcome (the model never called send_owner_report; owed-at-spawn made this run a
        // sweep candidate anyway). Formerly a recurring run sent NOTHING here (and every schedule run counts
        // as recurring) while the claim stood: 51 of 318 EMAIL runs in ten days lost a written report that way.
        if ($conversation->lastWireMessageIsFinalAssistantText()) {
            // A run that declared REPORT and wrote the report AFTER the call, but died before the loop bound them:
            // the final text IS the report — mark it and mail it, recurring or not.
            $pendingReportCall = $this->findPendingReportCallOfCurrentRun($conversation);
            if ($pendingReportCall !== null) {
                foreach (array_reverse($conversation->messages->getElements()) as $finalTextCandidate) {
                    if ($finalTextCandidate->isAssistantText()
                        && trim((string)$finalTextCandidate->message) !== ''
                    ) {
                        $finalTextCandidate->signalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT;
                        $this->messagesService()->persistMessageUnderRun($agentRun, $finalTextCandidate, null, false);
                        $pendingReportCall->toolCall->ownerReportMessageId = $finalTextCandidate->id;
                        $this->messagesService()->persistMessageUnderRun($agentRun, $pendingReportCall, null, false);
                        break;
                    }
                }
                $this->aiTrace('reportEmail.lateBoundReport', ['conversationId' => $conversation->id ?? null]);
                $this->composeAndSendReportEmail($conversation, async: true);
                return;
            }
            // The run's own, unattended turn ended without an outcome: a written report is never dropped. The text is
            // chosen by its place in the turn (the final text, or the one the forced closing step was about), marked as
            // the run's report and mailed under the template subject. Only NOTHING_TO_REPORT sends nothing.
            if (!$conversation->messages->currentTurnFollowsAnEarlierTurn()) {
                $reportTextMessage = $conversation->messages->getReportTextOfTurnWithoutOutcome();
                if ($reportTextMessage !== null) {
                    $reportTextMessage->signalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT;
                    $this->messagesService()->persistMessageUnderRun($agentRun, $reportTextMessage, null, false);
                    $this->aiTrace('reportEmail.reportWithoutOutcomeMailed', ['conversationId' => $conversation->id ?? null, 'messageId' => $reportTextMessage->id ?? null]);
                    $this->composeAndSendReportEmail($conversation, async: true);
                    return;
                }
            } else {
                // A later turn answered an owner who wrote into the run: the answer is read in the chat.
                if (($conversation->scheduleId ?? null) === null) {
                    $this->composeAndSendReportEmail($conversation, async: true);
                }
                return;
            }
        }
        $stopReason = AgentRunReport::STOP_REASON_RUN_ENDED_UNEXPECTEDLY;
        // the derive tail is ordered — walk it newest-first for the last ERROR.
        // The stop reason maps from the error's STRUCTURED code (signalSource), NEVER its raw text:
        // persisted MESSAGE_ERROR texts are internal diagnostics — a provider 4xx/5xx body carries
        // endpoint/model/request detail that must not render into the customer's chat. Unknown code → the generic
        // sentence; the raw text stays admin-inspectable on the ERROR message itself.
        foreach (array_reverse($conversation->messages->getElements()) as $message) {
            if (($message->messageType ?? null) === AIConversationMessage::MESSAGE_ERROR) {
                if (($message->signalSource ?? null) === AiBudgetExceededException::ERROR_CODE) {
                    $stopReason = 'the monthly AI budget of your plan is used up — it resets at the start of next month.';
                } elseif (($message->signalSource ?? null) === AIScopeNotEligibleException::ERROR_CODE) {
                    $stopReason = AgentRunReport::buildStopReasonAutomationPaused();
                }
                break;
            }
        }
        $reportText = AgentRunReport::composeFromConversation($conversation, $stopReason)->toChatMessageText();
        $transientMessageIds = AgentTransientMessageIds::startingAt($conversation->messages->getHighestMessageId());
        $reportMessage = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_TEXT,
            $reportText,
            role: AIConversationMessage::ROLE_ASSISTANT,
            authorType: AIConversationMessage::AUTHOR_ASSISTANT,
            // the OWNER-REPORT marker — FE delivery chip, email body derivation, append-idempotency.
            signalSource: AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT
        );
        // Last look before the append lands: composing above may have outlasted the send tool's stale-claim
        // threshold, in which case a live run took the claim back and delivered its own report meanwhile — a fresh
        // persisted read (the in-memory set predates the compose) decides; a report present means this append
        // would be the customer's second copy, and only the email leg is still owed.
        if ($this->messagesService()->findOwnerReportsOfCurrentRun($conversation)->hasOwnerReportWithContentForRun($conversation->reportOwedAt ?? null)) {
            $conversation->messages->remove($reportMessage);
            $this->composeAndSendReportEmail($conversation, async: true);
            return;
        }
        $this->messagesService()->persistMessageUnderRun($agentRun, $reportMessage, null, false);
        // The DELIVERED stamp already sits on the row — the atomic claim in deliverOwedReportLate() wrote it
        // before this append (claim-first: the crash window trades a rare retried delivery for never-double).
        // a late-delivered report means the run's own terminal never ran its bookkeeping (worker
        // crash / set-based reaper expiry) — the recovery pass records the failure on the owning schedule here.
        $this->schedulesService()->recordRunTerminalOfConversation($conversation, false);
        // the report content is now PERSISTED — fire the email leg (channel-guarded inside;
        // the sweep path claims via claimReportDelivery, which deliberately does NOT dispatch — content first).
        $this->composeAndSendReportEmail($conversation, async: true);
        $this->realtimeSignalService()->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_IDLE, $agentRun->pushConfigMemo);
    }

    /**
     * The LOOP-LEVEL half of `send_owner_report` — runs after {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::dispatchToolCall()} returned
     * with the call VALIDATED + COMPLETED: exactly-once claim → materialize the body as the VISIBLE
     * OWNER_REPORT chat message (the {@see \DDD\Domain\AI\Services\Conversations\AIConversationAgentService::appendStallGiveUpReply()} harness-author precedent) → dispatch the
     * email leg (content persisted first) → definitive steering result. Outside the tool branch on purpose:
     * an `AgentRunClaimLostException` from the append must reach the loop's single claim-lost exit — with the
     * delivered-claim REVERTED first, so the new owner's re-drive gets a truthful "not yet sent" state.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function executeOwnerReportSendAtLoopLevel(
        AgentRun $agentRun,
        AIConversation $conversation,
        ToolCall $toolCall,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        if (($toolCall->arguments['outcome'] ?? null) === SendOwnerReportTool::OUTCOME_NOTHING_TO_REPORT) {
            // NOTHING_TO_REPORT closes the obligation WITHOUT a mail: the delivery claim is consumed so neither the
            // owed-reports sweep nor a one-off run's final-text fallback mails anything, and the send schema leaves
            // the wire next step. Nothing is appended — the model's one closing sentence is the chat's record.
            if (isset($conversation->id)) {
                if (!$this->claimReportDelivery($conversation)) {
                    $toolCall->result = 'Already closed: this run\'s report obligation was settled before. Close with ONE short sentence.';
                    return;
                }
                $this->aiTrace('ownerReport.nothingToReport', ['conversationId' => $conversation->id]);
            } else {
                $conversation->reportDeliveredAt = new DateTime();
            }
            $toolCall->result = 'Recorded: nothing to report — no email goes out. Your NEXT message is the run\'s LAST: '
                . 'ONE short sentence saying what was checked and that nothing new was found. Do not mention emails.';
            return;
        }
        $ownerReportMessage = null;
        foreach ($conversation->messages->getElements() as $candidateMessage) {
            if ($toolCall->ownerReportMessageId !== null && $candidateMessage->id === $toolCall->ownerReportMessageId
                && $candidateMessage->isAssistantText()
                && trim((string)$candidateMessage->message) !== '') {
                $ownerReportMessage = $candidateMessage;
                break;
            }
        }
        if ($ownerReportMessage === null) {
            // The report is written AFTER the call (the contract — call first, then the report): accepted, not failed —
            // the next step is tool-less (dispatchToolCall set the breaker) and the final text of this turn becomes
            // the report through deliverOwnerReportWrittenAfterTheCall(); a failed call left runs without a report
            // (the owner had to ask for the mail). Nothing is claimed yet — the sweep's normal-end
            // rule mails the final text of a REPORT run when this process dies before the binding.
            $toolCall->result = 'Accepted. Write the COMPLETE report NOW as your next message — prose and widgets, '
                . 'self-contained; the platform emails exactly that message under your subject. '
                . ComposedViewKitSpec::EMAIL_REPORT_KEY_FIGURES_RULE . ' Text you wrote next to this call is not sent. '
                . 'Do not call send_owner_report again and do not add a closing sentence after the report.';
            $this->aiTrace('ownerReport.awaitingReportText', ['conversationId' => $conversation->id ?? null]);
            return;
        }
        $this->deliverOwnerReportMessage($agentRun, $conversation, $toolCall, $ownerReportMessage, $consoleStream, $emitRealtimeSignal);
    }

    /**
     * The turn's FINAL text written after a `send_owner_report(REPORT)` that found no report yet IS the report:
     * bind it to that call and deliver (mark OWNER_REPORT, claim, mail). Called from appendTurn's final-text branch;
     * a no-op when no such call is pending in this run or the run already has report content.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function deliverOwnerReportWrittenAfterTheCall(
        AgentRun $agentRun,
        AIConversation $conversation,
        AIConversationMessage $finalTextMessage,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        if (trim((string)$finalTextMessage->message) === '') {
            return;
        }
        $pendingReportCall = $this->findPendingReportCallOfCurrentRun($conversation);
        if ($pendingReportCall !== null) {
            $pendingReportCall->toolCall->ownerReportMessageId = $finalTextMessage->id;
            $this->deliverOwnerReportMessage($agentRun, $conversation, $pendingReportCall->toolCall, $finalTextMessage, $consoleStream, $emitRealtimeSignal);
            // the call row carries the binding and the definitive result for the inspector and the email composer
            $this->messagesService()->persistMessageUnderRun($agentRun, $pendingReportCall, $consoleStream, $emitRealtimeSignal);
            $this->aiTrace('ownerReport.boundToFinalText', ['conversationId' => $conversation->id ?? null, 'messageId' => $finalTextMessage->id ?? null]);
            return;
        }
    }

    /**
     * An unattended EMAIL run ended its turn with a final text
     * and no `send_owner_report` call — the report obligation would close without the run's own outcome (a recurring run
     * sends nothing at all). The loop then runs ONE more step pinned to `send_owner_report`: the text just written
     * becomes the report through {@see SendOwnerReportTool::bindReportSourceToCalls()} (REPORT), or the model states
     * NOTHING_TO_REPORT and the run closes quietly. The step reads one note that names the text just written as the report
     * REPORT sends ({@see FORCED_OWNER_REPORT_CALL_NOTE}). Arms `$agentLoopCorrectionState` and returns true when the step is
     * owed; false when the run is no unattended EMAIL run, its report is delivered, the turn already holds a
     * `send_owner_report` call, the turn is a later, owner-written one, the final text is empty, no step is left before
     * the budget closing step (`$hasStepLeft`, the caller's budget read), or the call was forced once in this invocation
     * already (logged — never a second forced step).
     */
    public function armForcedOwnerReportCallIfOwed(
        AgentRun $agentRun,
        AIConversation $conversation,
        AgentLoopCorrectionState $agentLoopCorrectionState,
        string $finalText,
        bool $hasStepLeft,
        AgentTransientMessageIds $transientMessageIds,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): bool {
        if (!$this->owesOwnerReportCallAfterFinalText($conversation, $finalText)) {
            return false;
        }
        if ($agentLoopCorrectionState->ownerReportCallForcedInThisInvocation || !$hasStepLeft) {
            $this->aiTrace('ownerReport.forcedCallNotMade', [
                'conversationId' => $conversation->id ?? null,
                'reason' => $hasStepLeft ? 'forcedStepAnsweredWithText' : 'noStepLeft',
            ]);
            return false;
        }
        $agentLoopCorrectionState->armForcedOwnerReportCall(SendOwnerReportTool::NAME, $finalText);
        $agentRun->ownerReportSourceMessageIdOfForcedStep = $this->findNewestNonEmptyAssistantTextMessage($conversation)?->id;
        $forcedCallNote = $this->messagesService()->appendNewMessage(
            $conversation,
            $transientMessageIds,
            AIConversationMessage::MESSAGE_SIGNAL,
            self::FORCED_OWNER_REPORT_CALL_NOTE,
            role: AIConversationMessage::ROLE_SYSTEM,
            authorType: AIConversationMessage::AUTHOR_AGENT,
            signalSource: AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT_FORCED_CALL
        );
        $this->messagesService()->persistMessageUnderRun($agentRun, $forcedCallNote, $consoleStream, $emitRealtimeSignal);
        $this->aiTrace('ownerReport.closingCallForced', ['conversationId' => $conversation->id ?? null]);
        return true;
    }

    /** The newest assistant text row with content among the loaded rows — the final text just appended. */
    protected function findNewestNonEmptyAssistantTextMessage(AIConversation $conversation): ?AIConversationMessage
    {
        foreach (array_reverse($conversation->messages->getElements()) as $candidateMessage) {
            if ($candidateMessage->isAssistantText() && trim((string)$candidateMessage->message) !== '') {
                return $candidateMessage;
            }
        }
        return null;
    }

    /**
     * The final text the forced `send_owner_report` step follows ({@see armForcedOwnerReportCallIfOwed()}) — the report
     * source of that step's call, whatever the step writes beside it; null on every other step. Used up by the call.
     */
    public function takeReportSourceOfForcedStep(AgentRun $agentRun, AIConversation $conversation): ?AIConversationMessage
    {
        $reportSourceMessageId = $agentRun->ownerReportSourceMessageIdOfForcedStep;
        $agentRun->ownerReportSourceMessageIdOfForcedStep = null;
        if ($reportSourceMessageId === null) {
            return null;
        }
        foreach ($conversation->messages->getElements() as $candidateMessage) {
            if (($candidateMessage->id ?? null) === $reportSourceMessageId) {
                return $candidateMessage;
            }
        }
        return null;
    }

    /**
     * Whether a final text of `$conversation` leaves the EMAIL run without its closing call: an unattended EMAIL run (an
     * initiator stamped, not a delegation child) whose report is not delivered, a non-empty text, no `send_owner_report`
     * call in the current turn (any status — a refused call is the model's own attempt), and an unattended TURN: the run's
     * own first turn. A later turn of the same conversation answers an owner who wrote into it — an interactive turn,
     * whose answer is read in the chat. Pure over the loaded rows.
     */
    public function owesOwnerReportCallAfterFinalText(AIConversation $conversation, string $finalText): bool
    {
        return ($conversation->reportChannel ?? null) === AIConversationSchedule::REPORT_CHANNEL_EMAIL
            && ($conversation->initiatorType ?? null) !== null
            && !$conversation->isDelegation()
            && !$conversation->getReportObligation()->isDelivered()
            && trim($finalText) !== ''
            && !$conversation->messages->currentTurnFollowsAnEarlierTurn()
            && $conversation->messages->buildCurrentTurn()->countToolCallsNamed(SendOwnerReportTool::NAME) === 0;
    }

    /**
     * After the forced step: the turn ends with the text the call followed once the call settled the report
     * — delivered as REPORT, or the obligation closed as NOTHING_TO_REPORT. False while the obligation stands (a call the
     * validation refused gets the model's own next step, never a second pin).
     */
    public function settlesTurnAfterForcedOwnerReportCall(AIConversation $conversation, AgentLoopCorrectionState $agentLoopCorrectionState): bool
    {
        return $agentLoopCorrectionState->ownerReportCallForcedInThisInvocation
            && $agentLoopCorrectionState->finalTextBeforeForcedOwnerReportCall !== null
            && $conversation->messages->buildCurrentTurn()->countToolCallsNamed(SendOwnerReportTool::NAME, completedOnly: true) > 0
            && $conversation->getReportObligation()->isDelivered();
    }

    /**
     * The newest COMPLETED `send_owner_report` call of the CURRENT run that declared REPORT but has no report message
     * bound yet — while the run has no report content. Null otherwise.
     */
    public function findPendingReportCallOfCurrentRun(AIConversation $conversation): ?AIConversationMessage
    {
        $newestInitiatorIndex = $conversation->messages->getNewestInitiatorMessageIndex() ?? -1;
        foreach (array_reverse($conversation->messages->getElements()) as $message) {
            if (($message->indexInConversation ?? -1) <= $newestInitiatorIndex) {
                break;
            }
            $toolCall = $message->toolCall ?? null;
            if ($toolCall === null || ($toolCall->name ?? null) !== SendOwnerReportTool::NAME) {
                continue;
            }
            if ($toolCall->status !== AIConversationMessage::STATUS_COMPLETED
                || ($toolCall->arguments['outcome'] ?? null) !== SendOwnerReportTool::OUTCOME_REPORT
                || $toolCall->ownerReportMessageId !== null
            ) {
                return null; // the newest send call is settled (bound, failed, or NOTHING_TO_REPORT) — nothing pending
            }
            return $this->findOwnerReportWithContentForCurrentRun($conversation) === null ? $message : null;
        }
        return null;
    }

    /**
     * Deliver ONE assistant message as this run's owner report: exactly-once claim → mark it OWNER_REPORT → persist →
     * dispatch the email leg → the steering result on the call. Shared by the call that found its report (the
     * accompanying or preceding message) and by the binding of a report written after the call.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a claim-fenced write ran
     */
    public function deliverOwnerReportMessage(
        AgentRun $agentRun,
        AIConversation $conversation,
        ToolCall $toolCall,
        AIConversationMessage $ownerReportMessage,
        ?AgentConsoleStream $consoleStream,
        bool $emitRealtimeSignal
    ): void {
        if (!isset($conversation->id)) {
            $ownerReportMessage->signalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT;
            $conversation->reportDeliveredAt = new DateTime();
            $toolCall->result = 'Report recorded in this test conversation; no email is sent in ephemeral mode.';
            return;
        }
        if (!$this->claimReportDelivery($conversation)) {
            // A consumed claim proves delivery ONLY together with the report CONTENT. The claim is one conditional
            // UPDATE on the conversation row, the content a separate message INSERT — a hard process kill between the
            // two (revert-on-throw never runs), or a second writer whose message merged onto the report's index (the
            // message upsert is INSERT … ON DUPLICATE KEY UPDATE over UNIQUE(conversation, index): the rows MERGE, no
            // exception fires), leaves the claim consumed and the owner with nothing. Trusting the claim alone then
            // makes the run close with "the report was sent" for a mail that never existed.
            $alreadySentResult = 'Already sent: this run\'s report was already delivered — do not send again. '
                . 'Close with ONE short sentence.';
            if ($this->findOwnerReportWithContentForCurrentRun($conversation) !== null) {
                $toolCall->result = $alreadySentResult;
                return;
            }
            // Contentless claim: take it back ONLY when it is stale. A fresh claim belongs to a writer that is
            // appending its content right now (the owed-report sweep, another driver) — stealing it would make two
            // reports land. A crash or a merged row leaves the stamp untouched for good, so its age separates the
            // two cases. Compare-and-set on the age, then re-claim; a fresh claim → it is being delivered elsewhere.
            if (!$this->revertStaleReportDeliveryClaim($conversation, self::OWNER_REPORT_CLAIM_STALE_AFTER_SECONDS)
                || !$this->claimReportDelivery($conversation)
            ) {
                $toolCall->result = $alreadySentResult;
                return;
            }
            // Between the content check and the take-back the other writer may have finished: content present now
            // means the obligation IS met — keep the re-won claim consumed, never append a second report.
            if ($this->findOwnerReportWithContentForCurrentRun($conversation) !== null) {
                $toolCall->result = $alreadySentResult;
                return;
            }
            $this->aiTrace('ownerReport.reclaimedAfterContentlessClaim', ['conversationId' => $conversation->id ?? null]);
        }
        try {
            $ownerReportMessage->signalSource = AIConversationMessage::SIGNAL_SOURCE_OWNER_REPORT;
            $this->messagesService()->persistMessageUnderRun($agentRun, $ownerReportMessage, $consoleStream, $emitRealtimeSignal);
            // Email leg AFTER the content persist (the composer derives body/subject from persisted state).
            $this->composeAndSendReportEmail($conversation, async: true);
            $toolCall->result = 'Report visible in chat and queued for email. Your NEXT message is the run\'s LAST: '
                . 'exactly ONE short closing sentence. Do NOT restate ANY report content — the report is already '
                . 'delivered and visible to the owner.';
        } catch (AgentRunClaimLostException | MessageIndexLockUnavailableException $turnEndingError) {
            // a lost claim or an unavailable index lock (lever E) ends the turn through the loop's own exits
            $this->revertReportDeliveryClaim($conversation);
            throw $turnEndingError;
        } catch (Throwable $ownerReportDeliveryError) {
            $this->revertReportDeliveryClaim($conversation);
            $toolCall->result = 'Tool error: the report could not be delivered — '
                . mb_substr($ownerReportDeliveryError->getMessage(), 0, 200) . '. Try once more.';
            $toolCall->markFailed();
        }
    }

    /**
     * This run's owner report AS CONTENT (see {@see AIConversationMessages::getNewestOwnerReportWithContentForRun()}),
     * or null. Checked on the loop's in-memory set first (this process appended it), then against the persisted
     * rows — another process may have appended it. `find…`: the second leg crosses into the repository. The persisted
     * read goes into a LOCAL set on purpose — never onto `$conversation->messages`: replacing the live set mid-loop
     * would drop the not-yet-persisted call message and hand its index out a second time.
     */
    public function findOwnerReportWithContentForCurrentRun(AIConversation $conversation): ?AIConversationMessage
    {
        $runObligationStampedAt = $conversation->reportOwedAt ?? null;
        $ownerReportMessage = $conversation->messages->getNewestOwnerReportWithContentForRun($runObligationStampedAt);
        if ($ownerReportMessage !== null) {
            return $ownerReportMessage;
        }
        // the persisted leg reads the current run's report rows only, never the whole history
        return $this->messagesService()->findOwnerReportsOfCurrentRun($conversation)->getNewestOwnerReportWithContentForRun($runObligationStampedAt);
    }

    /**
     * How long a failing report e-mail keeps being retried (measured from the run's start) before the obligation is
     * closed as undeliverable: 288 five-minute attempts cover any transport outage; beyond that the recipient is
     * unreachable for good (a test account's @example.com address, a bounced mailbox) and every further tick is waste.
     */
    public const int REPORT_EMAIL_GIVE_UP_AFTER_SECONDS = 86400;

    /**
     * How long a report obligation of a SUSPENDED agent space / account is deferred before it is closed: the run
     * completed and was paid for and the agent space resumes by itself, so the report waits for the unlock — but not
     * forever. Measured from the run's start.
     */
    public const int REPORT_SUPPRESSION_DEFER_CEILING_SECONDS = 30 * 86400;

    /**
     * Stamp the DELIVERY OBLIGATION: this run's terminal owes the customer a report. Targeted
     * two-column-safe persist; idempotent (an already-owed conversation keeps its original stamp — the recovery pass
     * orders by it). Ephemeral conversations (no id) no-op.
     */
    public function stampReportOwed(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || $conversation->getReportObligation()->isOwed()) {
            return;
        }
        $conversation->reportOwedAt = new DateTime();
        $conversation->updatePartialIgnoringRights('reportOwedAt');
    }

    /**
     * RE-ARM the delivery obligation for a NEW RUN in an existing unattended EMAIL conversation (a
     * per-RUN obligation): a fresh inbound message starts a new run whose report must be claimable and
     * deliverable INDEPENDENTLY of the previous run's — `reportOwedAt` is force-restamped (unlike the idempotent
     * {@see stampReportOwed()}) and `reportDeliveredAt` cleared in ONE targeted persist. No-ops on ephemeral
     * conversations and on conversations whose previous obligation is still open (owed set, delivered NULL —
     * the open obligation simply carries over to the new run).
     */
    public function rearmReportObligationForNewRun(AIConversation $conversation): void
    {
        if (!isset($conversation->id) || !$conversation->getReportObligation()->isDelivered()) {
            return;
        }
        $conversation->reportOwedAt = new DateTime();
        $conversation->reportDeliveredAt = null;
        $conversation->updatePartialIgnoringRights('reportOwedAt', 'reportDeliveredAt');
    }

    /**
     * Stamp the report as DELIVERED — called BEFORE the external sends (webhook idempotency: a
     * crash-after-send redelivery finds the stamp and skips re-sending) and immediately after an INLINE chat
     * delivery. Self-clears the owed-reports candidate set.
     */
    public function stampReportDelivered(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->reportDeliveredAt = new DateTime();
        $conversation->updatePartialIgnoringRights('reportDeliveredAt');
        // the ONE central delivery point: every inline terminal that stamps delivered has its
        // report content ALREADY appended (the stamp follows the append at all sites), so the email leg can
        // fire here. Channel-guarded inside; async dispatch — never blocks the terminal.
        $this->composeAndSendReportEmail($conversation, async: true);
    }

    /**
     * The OWNER REPORT of one run purely from PERSISTED state (the redelivery-safety anchor the mail composer and the
     * agent's `getTaskRunReport` share): reloads the messages fresh (never a prior-turn in-memory clone), subject =
     * the newest resolved `send_owner_report` call's stored args, body = the OWNER_REPORT chat message → the final
     * assistant text → the {@see AgentRunReport} template as last resort. Untruncated — the caller caps for its
     * channel. Pure with respect to the conversation row (it only populates `$conversation->messages`).
     *
     * @throws \Throwable
     */
    /** How many of the newest rows the owner report of a run reads — the report, the final text and the report call. */
    public const int OWNER_REPORT_NEWEST_ROWS = 60;

    public function composeOwnerReportOfRun(AIConversation $conversation): AIConversationOwnerReport
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = AIConversationMessage::getService();
        // the run's report, its final answer and its report call lie at the END of the conversation: the newest rows,
        // never the context tail (empty once finalize compacted the run to completion) nor the whole history
        $conversation->messages = $aIConversationMessagesService->findNewestMessages($conversation, self::OWNER_REPORT_NEWEST_ROWS);
        if ($conversation->messages->getNewestOwnerReportMessage() === null) {
            // a report row further back (a long run after its report): the current run's report rows by their key
            foreach ($aIConversationMessagesService->findOwnerReportsOfCurrentRun($conversation, 1)->getElements() as $ownerReportRow) {
                $conversation->messages->add($ownerReportRow);
                $conversation->messages->sortByIndexInConversation();
            }
        }

        $ownerReport = new AIConversationOwnerReport();
        $ownerReport->conversationId = (int)$conversation->id;
        $ownerReportMessage = $conversation->messages->getNewestOwnerReportMessage();
        if ($ownerReportMessage !== null && trim((string)$ownerReportMessage->message) !== '') {
            $ownerReport->bodyMarkdown = (string)$ownerReportMessage->message;
            $ownerReport->bodySource = AIConversationOwnerReport::BODY_SOURCE_OWNER_REPORT_MESSAGE;
        } elseif (trim((string)($conversation->messages->getFinalAssistantText() ?? '')) !== '') {
            $ownerReport->bodyMarkdown = (string)$conversation->messages->getFinalAssistantText();
            $ownerReport->bodySource = AIConversationOwnerReport::BODY_SOURCE_FINAL_ASSISTANT_TEXT;
        } else {
            $ownerReport->bodyMarkdown = AgentRunReport::composeFromConversation($conversation, AgentRunReport::STOP_REASON_RUN_ENDED_UNEXPECTEDLY)->toChatMessageText();
            $ownerReport->bodySource = AIConversationOwnerReport::BODY_SOURCE_TEMPLATE;
        }
        $sendOwnerReportToolCall = $conversation->messages->buildToolCallHistory()->getNewestResolvedToolCallByToolName(SendOwnerReportTool::NAME);
        if ($ownerReportMessage !== null) {
            foreach (array_reverse($conversation->messages->getElements()) as $callMessage) {
                $reportToolCall = $callMessage->toolCall ?? null;
                if ($reportToolCall?->name === SendOwnerReportTool::NAME
                    && $reportToolCall->ownerReportMessageId === $ownerReportMessage->id) {
                    $sendOwnerReportToolCall = $reportToolCall;
                    break;
                }
            }
        }
        $ownerReport->subject = trim((string)($sendOwnerReportToolCall->arguments['subject'] ?? ''));
        return $ownerReport;
    }

    /**
     * The report a run's MODEL wrote for the owner ({@see composeOwnerReportOfRun()} with its body from the run's
     * OWNER_REPORT message, and that message written by a model call), or null: a run that reported nothing, ended on a
     * platform template (the stop notice, a run-ended notice) or never marked a report. What
     * `getPreviousTaskRunReports` gives the next run of the schedule to build on —
     * a template must never become the structure the next report copies.
     *
     * @throws \Throwable
     */
    public function findModelWrittenOwnerReportOfRun(AIConversation $runConversation): ?AIConversationOwnerReport
    {
        $ownerReport = $this->composeOwnerReportOfRun($runConversation);
        if ($ownerReport->bodySource !== AIConversationOwnerReport::BODY_SOURCE_OWNER_REPORT_MESSAGE) {
            return null;
        }
        $ownerReportMessage = $runConversation->messages->getNewestOwnerReportMessage();
        return ($ownerReportMessage->aiModelName ?? null) !== null ? $ownerReport : null;
    }

    /**
     * Compose and send the OWNER-REPORT EMAIL of an unattended run — the ONE place email
     * delivery happens; every path (tool send, inline terminal, sweep late-delivery, fallback) converges here
     * through {@see stampReportDelivered()} / the sweep's post-append dispatch. Channel-guarded: a non-EMAIL
     * conversation no-ops. `$async` dispatches the ids-only Messenger message; the sync half hands the run to the
     * report mail channel ({@see AgentOwnerReportEmailsService::sendReportEmail()}, which composes the mail from
     * PERSISTED state — redelivery-safe, a replay composes exactly what the run persisted) and owns the failure
     * handling. Without a mail channel the send answers false and the retry window below gives up after a day.
     *
     * FAILURE CONTRACT: an unsent mail is an ERROR — `send()` returning false
     * (e.g. NO resolvable recipient), or composition/sending throwing, reverts the delivery claim
     * (owed stays set), so the owed-reports recovery pass retries with its natural backoff. Never throws to
     * the caller; returns whether the mail left (async dispatch returns true).
     */
    public function composeAndSendReportEmail(AIConversation $conversation, bool $async = false): bool
    {
        if (!isset($conversation->id)
            || ($conversation->reportChannel ?? null) !== AIConversationSchedule::REPORT_CHANNEL_EMAIL
        ) {
            return false;
        }
        // An eval run's row carries the case's report channel: its report stays in the chat, no owner mail ever leaves —
        // checked here, so neither the queued message nor the late delivery of the recovery cron can send one.
        if ($this->belongsToAnEvalRunTree($conversation)) {
            $this->aiTrace('reportEmail.skippedForEvalRun', ['conversationId' => $conversation->id]);
            return false;
        }
        // ELIGIBILITY on the MAIL LEG itself: evaluated immediately before the dispatch AND —
        // this same branch — inside the handler before the send, so a message already queued when the agent space was
        // deleted comes out as a suppressed no-op, never as a mail. The delivery claim is held on both paths.
        if ($this->handleReportObligationForIneligibleScope($conversation, claimHeld: true)) {
            $this->aiTrace('reportEmail.suppressed', ['conversationId' => $conversation->id, 'reason' => $conversation->suppressionReason ?? null]);
            return false;
        }
        if ($async) {
            (new AIConversationReportEmailSendMessage((int)$conversation->id))->dispatch();
            return true;
        }

        $emailWasSent = false;
        $sendFailure = null;
        try {
            $emailWasSent = $this->getOwnerReportEmailsService()->sendReportEmail($conversation);
        } catch (Throwable $sendFailure) {
            // fall through to the revert below; the sweep retries
        }
        if (!$emailWasSent) {
            $failureReason = $sendFailure?->getMessage() ?? 'send() returned false (no recipient / transport refused)';
            if (self::reportEmailRetryWindowExpired($conversation->changeHistory->createdTime ?? null)) {
                // Undeliverable for good: KEEP the won claim so the row leaves the owed set — a day of five-minute
                // retries against an unreachable recipient (eleven test-account runs looped for a week) is enough.
                $this->aiTrace('reportEmail.givenUp', ['conversationId' => $conversation->id, 'reason' => $failureReason]);
                return false;
            }
            $this->revertReportDeliveryClaim($conversation);
            // Starvation guard (the documented sweep-ordering trap): the retry goes to the BACK of the
            // owed-reports queue — a permanently failing mail (e.g. no resolvable recipient) must not hold
            // the oldest-first head slot on every pass while newer reports starve.
            $this->deferOwedReport($conversation);
            $this->aiTrace('reportEmail.sendFailed', ['conversationId' => $conversation->id, 'reason' => $failureReason]);
            return false;
        }
        return true;
    }

    /**
     * The FATE of a report obligation on a non-eligible scope: ENDED / ERASED
     * → CLOSED with a durable suppression reason; SUSPENDED → DEFERRED via the existing back-off (the run completed
     * and was paid for, the agent space resumes by itself) until the 30-day ceiling, then closed. Returns true when the
     * obligation was handled here and the caller must NOT deliver; false when it is eligible. `$claimHeld`: the caller already won the delivery claim (the mail leg); a deferral
     * then reverts it so the sweep retries, a suppression keeps it as the close.
     */
    public function handleReportObligationForIneligibleScope(AIConversation $conversation, bool $claimHeld, ?AgentAutomationEligibility $eligibility = null): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $eligibility ??= $this->conversationsService()->findAutomationEligibilityForConversation($conversation, useCache: false);
        $agentAutomationEligibilityService = $this->getAutomationEligibilityService();
        if (!$agentAutomationEligibilityService->refusesAutomation($eligibility, 'reportDelivery', AgentEligibilityLogContext::forConversation((int)$conversation->id))) {
            return false;
        }
        $suppressionReason = (string)$eligibility->getAutomationStateReason();
        if ($eligibility->isSuspended()
            && !self::reportDeferralCeilingReached($conversation->changeHistory->createdTime ?? null)
        ) {
            if ($claimHeld) {
                $this->revertReportDeliveryClaim($conversation);
            }
            $this->deferOwedReport($conversation);
            $this->aiTrace('report.deferredForSuspendedScope', ['conversationId' => $conversation->id, 'reason' => $suppressionReason]);
            return true;
        }
        $this->suppressReportObligation($conversation, $suppressionReason);
        return true;
    }

    /**
     * The in-flight half of a schedule CLOSE: cancel the schedule's NON-TERMINAL run conversations —
     * terminal CANCELLED + the run-token cleared, so a running worker loses its claim at the next heartbeat/append
     * and stops (the `cancelUnfinishedChildren` precedent) — and close EVERY still-open owed report obligation of the
     * schedule's runs with the suppression reason, so nothing is delivered afterwards. Two set-based UPDATEs; PHP
     * clock for the stamps like every other writer. Returns the number of rows touched.
     */
    public function cancelRunsAndCloseReportObligationsOfSchedule(int $scheduleId, string $suppressionReason): int
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $conversationsTable = DBAIConversationModel::getTableName();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $cancelledRuns = (int)$connection->executeStatement(
            "UPDATE `$conversationsTable` SET `runState` = :cancelled, `runToken` = NULL,"
            . ' `suppressionReason` = COALESCE(`suppressionReason`, :reason), `suppressedAt` = COALESCE(`suppressedAt`, :now)'
            . ' WHERE `scheduleId` = :scheduleId AND `runState` IN (:nonTerminal)',
            ['cancelled' => AIConversationRunState::CANCELLED, 'reason' => $suppressionReason, 'now' => $now, 'scheduleId' => $scheduleId, 'nonTerminal' => AIConversationRunState::NON_TERMINAL],
            ['nonTerminal' => \Doctrine\DBAL\ArrayParameterType::STRING]
        );
        $closedObligations = (int)$connection->executeStatement(
            "UPDATE `$conversationsTable` SET `reportDeliveredAt` = :now,"
            . ' `suppressionReason` = COALESCE(`suppressionReason`, :reason), `suppressedAt` = COALESCE(`suppressedAt`, :suppressedAt)'
            . ' WHERE `scheduleId` = :scheduleId AND `reportOwedAt` IS NOT NULL AND `reportDeliveredAt` IS NULL',
            ['now' => $now, 'reason' => $suppressionReason, 'suppressedAt' => $now, 'scheduleId' => $scheduleId]
        );
        $this->aiTrace('schedule.runsCancelledForState', ['scheduleId' => $scheduleId, 'reason' => $suppressionReason, 'cancelledRuns' => $cancelledRuns, 'closedObligations' => $closedObligations]);
        return $cancelledRuns + $closedObligations;
    }

    /**
     * CLOSE a report obligation without delivering: stamp the durable suppression reason and moment, and
     * consume the delivery claim (`reportDeliveredAt`) so the owed-reports sweep never re-selects the row. ONE
     * targeted persist; idempotent on an already-suppressed row.
     */
    public function suppressReportObligation(AIConversation $conversation, string $suppressionReason): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $now = new DateTime();
        $conversation->suppressionReason = $suppressionReason;
        $conversation->suppressedAt = $now;
        if (!isset($conversation->reportDeliveredAt)) {
            $conversation->reportDeliveredAt = $now;
        }
        $conversation->updatePartialIgnoringRights('suppressionReason', 'suppressedAt', 'reportDeliveredAt');
        $this->aiTrace('report.suppressed', ['conversationId' => $conversation->id, 'reason' => $suppressionReason]);
    }

    /**
     * Whether a deferred report of a SUSPENDED scope has waited long enough — the run started more than
     * {@see REPORT_SUPPRESSION_DEFER_CEILING_SECONDS} ago. Pure; an unknown start (no change history) closes at once
     * rather than deferring forever.
     */
    public static function reportDeferralCeilingReached(?\DateTimeInterface $runStartedAt, ?\DateTimeInterface $now = null): bool
    {
        if ($runStartedAt === null) {
            return true;
        }
        $now ??= new DateTime();
        return $now->getTimestamp() - $runStartedAt->getTimestamp() > self::REPORT_SUPPRESSION_DEFER_CEILING_SECONDS;
    }

    /**
     * Whether a report e-mail that keeps failing has been retried long enough — the run started more than
     * {@see REPORT_EMAIL_GIVE_UP_AFTER_SECONDS} ago. Pure; an unknown start (no change history) never gives up.
     */
    public static function reportEmailRetryWindowExpired(?\DateTimeInterface $runStartedAt, ?\DateTimeInterface $now = null): bool
    {
        if ($runStartedAt === null) {
            return false;
        }
        $now ??= new DateTime();
        return $now->getTimestamp() - $runStartedAt->getTimestamp() > self::REPORT_EMAIL_GIVE_UP_AFTER_SECONDS;
    }

    /**
     * ATOMICALLY claim the LATE report delivery (the recovery pass) — the {@see \DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService::claimRun()} pattern:
     * one conditional UPDATE (`WHERE reportDeliveredAt IS NULL`) so of two overlapping recovery runs exactly ONE
     * wins and appends the report; the loser's in-memory `isset()` pre-check raced and it must SKIP (the plain {@see stampReportDelivered()} let both passes append, and the customer saw the run
     * report twice). Returns true iff THIS caller won the claim.
     *
     * @phpstan-impure a DB write — two calls in a row may legitimately return different results
     */
    public function claimReportDelivery(AIConversation $conversation): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $now = new DateTime();
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName()
            . '` SET `reportDeliveredAt` = :now WHERE `id` = :id AND `reportDeliveredAt` IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $conversation->id]
        );
        $won = $affectedRows === 1;
        if ($won) {
            $conversation->reportDeliveredAt = $now;
        }
        return $won;
    }

    /**
     * REVERT a won report-delivery claim whose append then FAILED — sets `reportDeliveredAt` back to NULL so a
     * later recovery pass retries the delivery (a claim without a delivered report would otherwise silently
     * swallow the report obligation). Best-effort companion of {@see claimReportDelivery()}.
     */
    public function revertReportDeliveryClaim(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName()
            . '` SET `reportDeliveredAt` = NULL WHERE `id` = :id',
            ['id' => $conversation->id]
        );
        $conversation->reportDeliveredAt = null;
    }

    /**
     * Take back a report-delivery claim that ANOTHER writer holds — ONLY if it is older than `$staleAfterSeconds`.
     * Compare-and-set on the age: a fresh claim (a deliverer appending its content this very moment) is left alone,
     * a claim that old with no content behind it was abandoned (a killed process, a merged message row) and would
     * otherwise block the report forever. Returns whether THIS caller took it back (exactly one caller wins).
     * The unconditional {@see revertReportDeliveryClaim()} stays for the claim's OWN failure path.
     * The in-memory stamp is deliberately NOT cleared: the caller re-claims right away and {@see claimReportDelivery()}
     * sets it; if that re-claim loses, the stamp must stay set so the send tool's once-per-run gate stays closed.
     *
     * @phpstan-impure a DB write — two calls in a row may legitimately return different results
     */
    public function revertStaleReportDeliveryClaim(AIConversation $conversation, int $staleAfterSeconds): bool
    {
        if (!isset($conversation->id)) {
            return false;
        }
        $staleBefore = (new DateTime())->modify('-' . max(0, $staleAfterSeconds) . ' seconds');
        $affectedRows = (int)EntityManagerFactory::getInstance()->getConnection()->executeStatement(
            'UPDATE `' . DBAIConversationModel::getTableName()
            . '` SET `reportDeliveredAt` = NULL WHERE `id` = :id AND `reportDeliveredAt` IS NOT NULL AND `reportDeliveredAt` < :staleBefore',
            ['id' => $conversation->id, 'staleBefore' => $staleBefore->format('Y-m-d H:i:s')]
        );
        return $affectedRows === 1;
    }

    /**
     * Push a FAILED owed-report candidate to the BACK of the recovery ordering (the
     * pass selects `ORDER BY reportOwedAt ASC LIMIT n`, so a permanently-failing conversation retried every 5
     * minutes holds a head slot forever — 50 such rows and no newer owed report is ever delivered). Advancing
     * `reportOwedAt` to NOW keeps the report owed but makes every other candidate go first — a natural back-off
     * with no extra column.
     */
    public function deferOwedReport(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        $conversation->reportOwedAt = new DateTime();
        $conversation->updatePartialIgnoringRights('reportOwedAt');
    }

    /**
     * Whether the conversation belongs to an eval run's tree: its own run settings carry `evalRun`, or — for a delegation
     * child — its root's do (the root is loaded with the rights off: a system read of the tree the caller holds).
     */
    public function belongsToAnEvalRunTree(AIConversation $conversation): bool
    {
        if ((isset($conversation->runSettings) ? $conversation->runSettings->evalRun : null) === true) {
            return true;
        }
        $rootConversationId = $conversation->rootConversationId ?? null;
        if ($rootConversationId === null || $rootConversationId === ($conversation->id ?? null)) {
            return false;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $rootConversation = $this->conversationsService()->find($rootConversationId, useEntityRegistrCache: false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $rootConversation instanceof AIConversation
            && (isset($rootConversation->runSettings) ? $rootConversation->runSettings->evalRun : null) === true;
    }

    /** The conversations service (the conversation rows, the automation eligibility of a conversation's scope). */
    protected function conversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        return $aIConversationsService;
    }

    /** The eligibility service, resolved from the container — the override point of the container-less unit tests. */
    protected function getAutomationEligibilityService(): AgentAutomationEligibilityService
    {
        /** @var AgentAutomationEligibilityService $agentAutomationEligibilityService */
        $agentAutomationEligibilityService = DDDService::instance()->getService(AgentAutomationEligibilityService::class);
        return $agentAutomationEligibilityService;
    }

    /** The report mail channel, resolved from the container — the override point of the container-less unit tests. */
    protected function getOwnerReportEmailsService(): AgentOwnerReportEmailsService
    {
        /** @var AgentOwnerReportEmailsService $agentOwnerReportEmailsService */
        $agentOwnerReportEmailsService = DDDService::instance()->getService(AgentOwnerReportEmailsService::class);
        return $agentOwnerReportEmailsService;
    }

    protected function schedulesService(): AIConversationSchedulesService
    {
        /** @var AIConversationSchedulesService $aIConversationSchedulesService */
        $aIConversationSchedulesService = DDDService::instance()->getService(AIConversationSchedulesService::class);
        return $aIConversationSchedulesService;
    }

    /** The messages service — resolved inline (DI rule); a test double replaces it. */
    protected function messagesService(): AIConversationMessagesService
    {
        /** @var AIConversationMessagesService $aIConversationMessagesService */
        $aIConversationMessagesService = DDDService::instance()->getService(AIConversationMessagesService::class);
        return $aIConversationMessagesService;
    }

    /** The realtime change-signal service — resolved inline (DI rule); a test double replaces it. */
    protected function realtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }
}
