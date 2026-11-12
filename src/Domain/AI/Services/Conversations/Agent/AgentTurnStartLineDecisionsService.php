<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartLineDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AgentActivityLinesService;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The turn-start line site (layer L2): does the assistant have to read, search or change
 * something before it can answer the owner's message? Its question rides the folded turn-start request
 * ({@see buildRequestInput()} → {@see applyDecision()}); when the answer says yes, the activity stack the customer
 * watches opens with one line on what the assistant does now — written by the activity narrator, not by the decision
 * model, which answers typed questions only. The rule and the floor are {@see AgentTurnStartLineDecision}'s.
 *
 * On a call the same line is SPOKEN instead of shown: the site also runs on the phone line and in an
 * in-app voice call while the voice worker stamps the root conversation as read
 * ({@see AgentActivityLinesService::hasReader()}), and the announced decision schedules the narration for that reader
 * ({@see publishTurnStartLineSignal()}).
 */
class AgentTurnStartLineDecisionsService extends Service
{
    use AITraceLogTrait;

    /**
     * The site's turn-start request input, or null when the site does not run for this turn: site `off`, a delegation,
     * no owner reading the chat or listening for the line (a scheduled or platform run, an external agent —
     * {@see OwnerPresence}; the phone line or an in-app voice call WITHOUT a reader stamp of the root —
     * with the voice worker's stamp the line is spoken on the call), a purpose that writes its own owner text, a turn
     * whose initiator carries no owner words or no persisted id, or a chunk resume of an already decided owner turn.
     * Fail-open (null).
     */
    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentTurnStartLineDecisionQuestion
    {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_TURN_START_LINE, $conversation);
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || !isset($conversation->id)) {
                return null;
            }
            if (!$this->hasOwnerFollowingTheTurn($conversation)
                || $conversation->findPurpose()?->usesSynthesizedOwnerText === true
            ) {
                return null;
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            $initiatorMessage = $conversation->messages->getNewestInitiatorMessage();
            if ($initiatorMessageIndex === null || !AIConversationMessages::initiatorCarriesOwnerWords($initiatorMessage)
                || trim((string)($initiatorMessage->message ?? '')) === '' || !isset($initiatorMessage->id)
            ) {
                return null;
            }
            $previousDecision = $conversation->getAgentWorkingState()?->turnStartLineDecision;
            if ($previousDecision !== null && $previousDecision->ownerMessageIndex === $initiatorMessageIndex) {
                return null; // a chunk resume of the same owner turn — the decision stands
            }
            $recentTurnTexts = $conversation->messages->buildOwnerTurns()->buildRecentTurnTextsBefore($initiatorMessageIndex);
            $turnStartLineQuestion = new AgentTurnStartLineDecisionQuestion();
            $turnStartLineQuestion->mode = $siteMode;
            $turnStartLineQuestion->initiatorMessageIndex = $initiatorMessageIndex;
            $turnStartLineQuestion->initiatorMessageId = (int)$initiatorMessage->id;
            $turnStartLineQuestion->ownerMessage = (string)$initiatorMessage->message;
            $turnStartLineQuestion->assistantTextBefore = $recentTurnTexts->assistantTextBefore;
            $turnStartLineQuestion->recentTurns = $recentTurnTexts->recentTurns;
            return $turnStartLineQuestion;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.turnStartLine.inputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    /**
     * Whether an owner follows this turn's activity lines: in the chat always (the stack is watched), on the phone line
     * or in an in-app voice call only while a reader is attached to the root conversation (the voice worker's
     * stamp) — the line is then spoken. Every other presence has nobody who would see or hear the line.
     */
    protected function hasOwnerFollowingTheTurn(AIConversation $conversation): bool
    {
        $ownerPresence = $conversation->getOwnerPresence();
        if ($ownerPresence === OwnerPresence::PRESENT_IN_CHAT) {
            return true;
        }
        if ($ownerPresence !== OwnerPresence::PRESENT_ON_THE_PHONE && $ownerPresence !== OwnerPresence::PRESENT_IN_APP_VOICE_CALL) {
            return false;
        }
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
        return $agentActivityLinesService->hasReader($conversation->getTreeRootConversationId());
    }

    /**
     * Read the answer of the folded turn-start request, stamp the slice (applied only under `on`), record the decision
     * and its event. Returns whether the activity stack now opens with the line — the caller pings the customer's
     * client once the slice is written ({@see publishTurnStartLineSignal()}).
     *
     * @param string|null $requestDecidedBy the batch's own fallback, null when the request answered
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function applyDecision(
        AIConversation $conversation,
        AgentTurnStartLineDecisionQuestion $turnStartLineQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): bool {
        $initiatorMessageIndex = $turnStartLineQuestion->initiatorMessageIndex;
        $decision = AgentTurnStartLineDecision::build($initiatorMessageIndex, $turnStartLineQuestion->initiatorMessageId);
        $decidedBy = $requestDecidedBy;
        if ($decidedBy !== null) {
            $decision->skipReason = $decidedBy; // a FALLBACK_* — the request never answered
        } elseif ($argusDecision === null || !$argusDecision->turnStartLineAnswersUsable()) {
            $decidedBy = AgentTurnStartLineDecision::FALLBACK_UNPARSABLE;
            $decision->skipReason = $decidedBy;
        } else {
            $decision->resolveFromWorkBeforeAnswerProbability($argusDecision->workBeforeAnswerProbability);
            $decision->modelVersion = $argusDecision->modelVersion;
            $decidedBy = $turnStartLineQuestion->mode === AgentDecisionModelSettings::MODE_ON
                ? AgentTurnStartLineDecision::DECIDED_BY_DECISION_MODEL
                : AgentTurnStartLineDecision::DECIDED_BY_SHADOW;
        }
        $decision->decidedBy = $decidedBy;
        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        $agentWorkingState->turnStartLineDecision = $decision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'turnStartLineDecision');
        $outcome = $decision->buildOutcome($turnStartLineQuestion->mode);
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        $agentDecisionCallsService->recordDecision($conversation, $decision->buildRecord(
            AgentDecisionModelSettings::SITE_TURN_START_LINE,
            $outcome['applied'],
            $elapsedMs,
            $transportFailureReason,
            $argusDecision?->stateForSite(AgentDecisionModelSettings::SITE_TURN_START_LINE),
            $argusDecision?->answersForSite(AgentDecisionModelSettings::SITE_TURN_START_LINE),
            $outcome,
            $argusDecision?->questionsForSite(AgentDecisionModelSettings::SITE_TURN_START_LINE)
        ));
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        $aIConversationTurnEventsService->recordEventOfConversation(
            $conversation,
            AIConversationTurnEvent::KIND_DECISION,
            AgentDecisionModelSettings::SITE_TURN_START_LINE,
            $initiatorMessageIndex,
            null,
            null,
            $outcome + ['decidedBy' => $decision->decidedBy, 'transportFailureReason' => $transportFailureReason],
            $runToken,
            $elapsedMs
        );
        $this->aiTrace('turn.turnStartLine.decided', ['conversationId' => $conversation->id ?? null, 'decidedBy' => $decision->decidedBy] + $outcome);
        return $decision->announcesTheTurnOfOwnerMessage($initiatorMessageIndex);
    }

    /**
     * Tell the customer's client that the activity stack has news (the existing realtime ping: a counter, no content) —
     * after the slice is written, so the client's next activity read finds the line — and count the stamped decision
     * as a step for the root's reader (the narration of the turn-start line is scheduled while the web
     * chat or a call reads; without a reader nothing happens). Best-effort.
     */
    public function publishTurnStartLineSignal(AIConversation $conversation): void
    {
        if (!isset($conversation->id)) {
            return;
        }
        /** @var AgentActivityLinesService $agentActivityLinesService */
        $agentActivityLinesService = DDDService::instance()->getService(AgentActivityLinesService::class);
        $agentActivityLinesService->recordStepForReaders($conversation);
        try {
            /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
            $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
            $aIConversationRealtimeSignalService->publishChangeSignal($conversation, AIConversationRealtimeSignalService::STATUS_RUNNING);
        } catch (Throwable $signalError) {
            $this->aiTrace('turn.turnStartLine.signalFailed', ['conversationId' => $conversation->id, 'message' => $signalError->getMessage()]);
        }
    }
}
