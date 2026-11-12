<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The direct-widget decision site: is the owner's message answered by one widget
 * the model writes directly? Its question rides the folded turn-start request ({@see buildRequestInput()} →
 * {@see applyDecision()}); the rule, the thresholds and the signal text are {@see AgentDirectWidgetDecision}'s.
 */
class AgentDirectWidgetDecisionsService extends Service
{
    use AITraceLogTrait;

    /**
     * The site's turn-start request input, or null when the site does not run for this turn: site `off`, a delegation,
     * a conversation whose reader is not taught the widgets written without a skill (the phone line, a platform run —
     * {@see AIConversation::isTaughtTheComposedViewKit()}), a scheduled run or a purpose that writes its own owner text,
     * a turn whose initiator carries no owner words, a chunk resume of an already decided owner turn, or no widget class
     * with a line. Fail-open (null).
     */
    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentDirectWidgetDecisionQuestion
    {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_DIRECT_WIDGET, $conversation);
            if ($siteMode === AgentDecisionModelSettings::MODE_OFF || $conversation->isDelegation() || !isset($conversation->id)) {
                return null;
            }
            if ($conversation->isScheduleBound() || $conversation->findPurpose()?->usesSynthesizedOwnerText === true || !$conversation->isTaughtTheComposedViewKit()) {
                return null;
            }
            $initiatorMessageIndex = $conversation->messages->getNewestInitiatorMessageIndex();
            $initiatorMessage = $conversation->messages->getNewestInitiatorMessage();
            if ($initiatorMessageIndex === null || !AIConversationMessages::initiatorCarriesOwnerWords($initiatorMessage)
                || trim((string)($initiatorMessage->message ?? '')) === ''
            ) {
                return null;
            }
            $previousDecision = $conversation->getAgentWorkingState()?->directWidgetDecision;
            if ($previousDecision !== null && $previousDecision->ownerMessageIndex === $initiatorMessageIndex) {
                return null; // a chunk resume of the same owner turn — the decision stands
            }
            $widgetWhenLines = ConversationWidgetClasses::getDirectWidgetWhenLinesByWidgetType();
            if ($widgetWhenLines === []) {
                return null;
            }
            $recentTurnTexts = $conversation->messages->buildOwnerTurns()->buildRecentTurnTextsBefore($initiatorMessageIndex);
            $directWidgetQuestion = new AgentDirectWidgetDecisionQuestion();
            $directWidgetQuestion->mode = $siteMode;
            $directWidgetQuestion->initiatorMessageIndex = $initiatorMessageIndex;
            $directWidgetQuestion->ownerMessage = (string)$initiatorMessage->message;
            $directWidgetQuestion->assistantTextBefore = $recentTurnTexts->assistantTextBefore;
            $directWidgetQuestion->recentTurns = $recentTurnTexts->recentTurns;
            $directWidgetQuestion->widgetWhenLines = $widgetWhenLines;
            return $directWidgetQuestion;
        } catch (Throwable $inputError) {
            $this->aiTrace('turn.directWidget.inputFailed', ['conversationId' => $conversation->id ?? null, 'message' => $inputError->getMessage()]);
            return null;
        }
    }

    /**
     * Parse the widget answer of the folded turn-start request, resolve the pick, read whether the skill preload of the
     * same turn loaded a skill (it runs first), stamp the slice (applied only under `on`), record the decision and its
     * event.
     *
     * @param string|null $requestDecidedBy the batch's own fallback, null when the request answered
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function applyDecision(
        AIConversation $conversation,
        AgentDirectWidgetDecisionQuestion $directWidgetQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        ?string $runToken,
        ?int $elapsedMs,
        ?string $transportFailureReason
    ): void {
        $initiatorMessageIndex = $directWidgetQuestion->initiatorMessageIndex;
        $decision = AgentDirectWidgetDecision::build($initiatorMessageIndex);
        foreach (array_keys($directWidgetQuestion->widgetWhenLines) as $widgetType) {
            $decision->widgetTokens[strtolower((string)$widgetType)] = (string)$widgetType;
        }
        $decidedBy = $requestDecidedBy;
        if ($decidedBy !== null) {
            $decision->skipReason = $decidedBy; // a FALLBACK_* — the request never answered
        } elseif ($argusDecision === null || !$argusDecision->directWidgetAnswersUsable()) {
            $decidedBy = AgentDirectWidgetDecision::FALLBACK_UNPARSABLE;
            $decision->skipReason = $decidedBy;
        } else {
            $validated = AgentDirectWidgetDecision::validateReportedProbabilities($argusDecision->directWidgetProbabilities, array_keys($decision->widgetTokens));
            $decision->probabilities = $validated?->toArray() ?? [];
            $distributionPick = AgentDirectWidgetDecision::resolveFromDistribution($validated);
            $decision->wouldShow = $distributionPick->isPicked;
            $decision->skipReason = $distributionPick->skipReason;
            $pickedToken = $distributionPick->topToken;
            $decision->pickedWidgetType = $decision->wouldShow && $pickedToken !== null ? ($decision->widgetTokens[$pickedToken] ?? null) : null;
            $decision->modelVersion = $argusDecision->modelVersion;
            $decidedBy = $directWidgetQuestion->mode === AgentDecisionModelSettings::MODE_ON
                ? AgentDirectWidgetDecision::DECIDED_BY_DECISION_MODEL
                : AgentDirectWidgetDecision::DECIDED_BY_SHADOW;
        }
        $decision->decidedBy = $decidedBy;
        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        // the roots pass of the skill preload applied before this site; an applied preload defers the widget (a shadow
        // record loaded nothing and defers nothing)
        $decision->skillPreloaded = $agentWorkingState->skillPreloadDecision?->isAppliedPreloadForOwnerMessage($initiatorMessageIndex) === true;
        if ($decision->wouldShow && $decision->skillPreloaded) {
            $decision->skipReason = AgentDirectWidgetDecision::SKIP_SKILL_PRELOADED;
        }
        $agentWorkingState->directWidgetDecision = $decision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'directWidgetDecision');
        $outcome = $decision->buildOutcome($directWidgetQuestion->mode);
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        $agentDecisionCallsService->recordDecision($conversation, $decision->buildRecord(
            AgentDecisionModelSettings::SITE_DIRECT_WIDGET,
            $decision->getDecidedWidgetType() !== null,
            $elapsedMs,
            $transportFailureReason,
            $argusDecision?->stateForSite(AgentDecisionModelSettings::SITE_DIRECT_WIDGET),
            $argusDecision?->answersForSite(AgentDecisionModelSettings::SITE_DIRECT_WIDGET),
            $outcome,
            $argusDecision?->questionsForSite(AgentDecisionModelSettings::SITE_DIRECT_WIDGET)
        ));
        /** @var AIConversationTurnEventsService $aIConversationTurnEventsService */
        $aIConversationTurnEventsService = DDDService::instance()->getService(AIConversationTurnEventsService::class);
        $aIConversationTurnEventsService->recordEventOfConversation(
            $conversation,
            AIConversationTurnEvent::KIND_DECISION,
            AgentDecisionModelSettings::SITE_DIRECT_WIDGET,
            $initiatorMessageIndex,
            null,
            null,
            $outcome + ['decidedBy' => $decision->decidedBy, 'transportFailureReason' => $transportFailureReason],
            $runToken,
            $elapsedMs
        );
        $this->aiTrace('turn.directWidget.decided', ['conversationId' => $conversation->id ?? null, 'decidedBy' => $decision->decidedBy] + $outcome);
    }
}
