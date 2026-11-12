<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentOwnerMessageInjectionDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The owner-message injection screen of the turn start: which owner message
 * the check reads, its one-child request (a parallel child of the turn-start batch), and the verdict — under `on` a
 * blocked message is marked on the working state, so the egress shows the model the filter notice instead of its text.
 */
class AIConversationOwnerMessageScreeningService extends Service
{
    use AITraceLogTrait;

    /**
     * The owner message the turn-start injection check reads — the newest owner TEXT row, or the basics
     * form's submit signal when it started the current turn (screened on its `Label: value` lines,
     * {@see AIConversationMessage::getOwnerTextForInjectionCheck()}), not yet checked; null when the site is off, the conversation is a delegation child, or the turn has no owner words (a
     * signal-started run). A chunk resume of the same owner message finds its record and asks nothing.
     */
    public function findOwnerMessageForInjectionCheck(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AIConversationMessage
    {
        if ($decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, $conversation) === AgentDecisionModelSettings::MODE_OFF
            || $conversation->isDelegation()
        ) {
            return null;
        }
        // A form widget's submit is the owner's typed words under a SIGNAL row: when it started the current turn it is
        // the message to screen (the owner's previous USER row was screened in its own turn already).
        $newestOwnerMessage = $conversation->messages->buildCurrentTurn()->getInitiatorWhenOwnerFormSubmit() ?? $conversation->getNewestUserMessage();
        if ($newestOwnerMessage === null
            || (($newestOwnerMessage->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT && !AIConversationMessages::isOwnerFormSubmitInitiator($newestOwnerMessage))
            || trim($newestOwnerMessage->getOwnerTextForInjectionCheck()) === ''
            || ($newestOwnerMessage->indexInConversation ?? null) === null
        ) {
            return null;
        }
        $agentWorkingState = $conversation->getAgentWorkingState();
        foreach ($agentWorkingState->decisions ?? [] as $agentDecisionRecord) {
            if (($agentDecisionRecord->site ?? null) === AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION
                && ($agentDecisionRecord->ownerMessageIndex ?? null) === $newestOwnerMessage->indexInConversation
            ) {
                return null;
            }
        }
        return $newestOwnerMessage;
    }

    /** The one-child request of the injection check of an owner message, screened on its owner text. */
    public function buildInjectionCheckRequest(AIConversation $conversation, AIConversationMessage $ownerMessage): ArgusAgentOwnerMessageInjectionDecision
    {
        $argusOwnerMessageInjectionDecision = new ArgusAgentOwnerMessageInjectionDecision();
        $argusOwnerMessageInjectionDecision->ownerMessage = $ownerMessage->getOwnerTextForInjectionCheck();
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $argusOwnerMessageInjectionDecision->billingEntity = $agentSpacesService->findLoadedBillingEntityOfConversation($conversation);
        $argusOwnerMessageInjectionDecision->budgetAccount = isset($conversation->account) ? $conversation->account : null;
        return $argusOwnerMessageInjectionDecision;
    }

    /**
     * Apply the verdict of the turn-start injection check. Under `on` a BLOCKED message is marked on the
     * working state (the egress shows the model the filter notice instead of the text) and the slice is persisted;
     * under `shadow` the verdict is recorded only. No answer (breaker open, our own gate's refusal, timeout, malformed)
     * = the message passes. `$requestRefused`: our own gate refused the batch — stamped `fallback:refused` like every
     * site; every other outcome is read from the check's own request.
     *
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function applyDecision(
        AIConversation $conversation,
        AIConversationMessage $ownerMessage,
        ?ArgusAgentOwnerMessageInjectionDecision $argusOwnerMessageInjectionDecision,
        AgentDecisionModelSettings $decisionModelSettings,
        ?int $elapsedMs,
        bool $requestRefused = false
    ): void {
        try {
            $siteMode = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, $conversation);
            $blocksMessage = $argusOwnerMessageInjectionDecision?->blocksMessage() ?? false;
            $applied = $blocksMessage && $siteMode === AgentDecisionModelSettings::MODE_ON;
            if ($applied) {
                $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
                $agentWorkingState->markOwnerMessageSecurityFiltered((int)$ownerMessage->indexInConversation);
                /** @var AIConversationsService $aIConversationsService */
                $aIConversationsService = AIConversation::getService();
                $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'securityFilteredOwnerMessageIndexes');
            }
            $decidedBy = match (true) {
                $requestRefused => AgentDecision::FALLBACK_REFUSED,
                $argusOwnerMessageInjectionDecision === null => AgentDecision::FALLBACK_BREAKER,
                $argusOwnerMessageInjectionDecision->transportFailed => AgentDecision::FALLBACK_TRANSPORT,
                $argusOwnerMessageInjectionDecision->passProbability === null => AgentDecision::FALLBACK_UNPARSABLE,
                $siteMode === AgentDecisionModelSettings::MODE_ON => AgentDecision::DECIDED_BY_DECISION_MODEL,
                default => AgentDecision::DECIDED_BY_SHADOW,
            };
            $this->aiTrace('turn.ownerMessageInjection.decided', [
                'conversationId' => $conversation->id ?? null,
                'ownerMessageIndex' => $ownerMessage->indexInConversation ?? null,
                'passProbability' => $argusOwnerMessageInjectionDecision?->passProbability,
                'filtered' => $applied,
                'decidedBy' => $decidedBy,
            ]);
            $this->decisionCallsService()->recordDecision($conversation, AgentDecisionRecord::build(
                site: AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION,
                ownerMessageIndex: $ownerMessage->indexInConversation ?? null,
                decidedBy: $decidedBy,
                applied: $applied,
                elapsedMs: $elapsedMs,
                modelVersion: $argusOwnerMessageInjectionDecision?->modelVersion,
                failureReason: $argusOwnerMessageInjectionDecision?->transportFailureReason,
                state: $argusOwnerMessageInjectionDecision?->buildState(),
                answers: $argusOwnerMessageInjectionDecision?->rawAnswers,
                outcome: [
                    'passProbability' => $argusOwnerMessageInjectionDecision?->passProbability,
                    'attackProbability' => $argusOwnerMessageInjectionDecision?->getAttackProbability(),
                    'blocked' => $blocksMessage,
                    'filteredForTheModel' => $applied,
                ]
            ));
        } catch (AgentRunClaimLostException $claimLost) {
            throw $claimLost; // the loop's one claim-lost exit — never a swallowed failure
        } catch (Throwable $decisionError) {
            $this->aiTrace('turn.ownerMessageInjection.failed', ['conversationId' => $conversation->id ?? null, 'message' => $decisionError->getMessage()]);
        }
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }
}
