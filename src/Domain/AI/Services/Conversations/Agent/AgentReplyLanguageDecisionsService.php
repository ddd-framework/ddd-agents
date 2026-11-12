<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\AI\Traits\Conversations\AITraceLogTrait;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The reply-language decision site: decide the REPLY LANGUAGE of the current
 * owner turn, once per owner message, before step 1. The decision model answers three narrow typed questions over the
 * owner's own recent words and the rendered standing directives — the language the owner writes in, the one the owner
 * asks for, and the one the directives prescribe; the precedence (by recency) is resolved in PHP and stamped on the
 * working state, which every context build of the turn reads. A user-absent run gets the platform's stamp instead.
 */
class AgentReplyLanguageDecisionsService extends Service
{
    use AITraceLogTrait;

    /**
     * The reply-language site's part of the turn-start request, or null when the site does not run for
     * this turn: site `off`, a delegation, no owner words (turn 1, a signal-started run), a chunk resume of an already
     * decided owner message, or a USER-ABSENT run — which the platform stamps itself here
     * ({@see stampPlatformReplyLanguageForUserAbsentRun()}). Otherwise the configured and supported codes, the standing
     * directives exactly as the context build renders them, the sticky anchor and the decision before the model answered.
     *
     * @throws AgentRunClaimLostException the run claim was lost while a user-absent stamp was persisted
     */
    public function buildRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings): ?AgentReplyLanguageDecisionQuestion
    {
        $languageEligible = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, $conversation) !== AgentDecisionModelSettings::MODE_OFF && !$conversation->isDelegation();
        $newestOwnerMessage = $conversation->getNewestUserMessage();
        if ($languageEligible && (
            $newestOwnerMessage === null
            || ($newestOwnerMessage->messageType ?? null) !== AIConversationMessage::MESSAGE_TEXT
            || trim((string)($newestOwnerMessage->message ?? '')) === ''
        )) {
            $languageEligible = false; // no owner words → no language decision; the configured chain decides, as before
        }
        $ownerMessageIndex = $newestOwnerMessage?->indexInConversation ?? null;
        if ($languageEligible && $ownerMessageIndex === null) {
            $languageEligible = false;
        }
        $agentWorkingState = $conversation->getAgentWorkingState();
        $previousDecision = $agentWorkingState?->replyLanguageDecision;
        if ($languageEligible && $previousDecision !== null && $previousDecision->ownerMessageIndex === $ownerMessageIndex) {
            $languageEligible = false; // a chunk resume of the same owner turn — already decided
        }
        if ($languageEligible && !$conversation->isUserPresentRun()) {
            // A user-absent run (a schedule, an admin task, a run of the application) has no owner message to measure: its
            // seed is platform-composed text, and measuring it answered German customers' scheduled reports in
            // English. The platform stamps the configured language itself (the one of the conversation's space, the
            // application's default without one); the model is not asked.
            $this->stampPlatformReplyLanguageForUserAbsentRun($conversation, (int)$ownerMessageIndex);
            $languageEligible = false;
        }
        $ownerWords = $languageEligible ? $conversation->messages->buildOwnerTurns()->buildRecentOwnerWords() : '';
        if ($languageEligible && $ownerWords === '') {
            $languageEligible = false;
        }
        if (!$languageEligible) {
            return null;
        }
        $standingDirectivesBlock = '';
        $newestDirectiveValidFrom = null;
        $anchorLanguageCode = null;
        $anchorDecidedAt = null;
        $anchorInstant = null;
        $configuredLanguageCode = $conversation->resolveUserLanguageCode();
        $supportedLanguageCodes = $this->getActiveInterfaceLanguageCodes();
        if (!in_array($configuredLanguageCode, $supportedLanguageCodes, true)) {
            $supportedLanguageCodes[] = $configuredLanguageCode;
        }

        // The standing directives, exactly as the context build injects them (the same fetch, the same render): those
        // that apply to the conversation's space, without a space the account-wide ones only.
        if (isset($conversation->accountId) && $conversation->accountId) {
            /** @var AgentMemoriesService $agentMemoriesService */
            $agentMemoriesService = DDDService::instance()->getService(AgentMemoriesService::class);
            $standingDirectives = $agentMemoriesService->findStandingDirectivesForAgentSpace((int)$conversation->accountId, $conversation->agentSpace ?? null);
            if ($standingDirectives !== null) {
                $standingDirectivesBlock = $standingDirectives->directivesBlock;
                $newestDirectiveValidFrom = $standingDirectives->newestValidFrom;
            }
        }

        // The sticky ANCHOR = the previous turn's decision, but only when it came from owner evidence (a
        // config-sourced fallback is not the owner's signal and must not gain an instant). An anchor that itself
        // carried an older anchor keeps that original instant — a thin turn does not rejuvenate it.
        if ($previousDecision !== null && in_array($previousDecision->source, [
            AgentReplyLanguageDecision::SOURCE_REQUEST, AgentReplyLanguageDecision::SOURCE_WRITTEN,
            AgentReplyLanguageDecision::SOURCE_ANCHOR, AgentReplyLanguageDecision::SOURCE_DIRECTIVE,
        ], true)) {
            $anchorLanguageCode = $previousDecision->languageCode;
            // A directive-sourced stamp keeps the DIRECTIVE's instant (its validFrom), never the turn's wall clock —
            // else an edited directive would lose to its own rejuvenated self.
            $anchorDecidedAt = match ($previousDecision->source) {
                AgentReplyLanguageDecision::SOURCE_ANCHOR => $previousDecision->anchorDecidedAt ?? $previousDecision->decidedAt,
                AgentReplyLanguageDecision::SOURCE_DIRECTIVE => $previousDecision->directiveValidFrom ?? $previousDecision->decidedAt,
                default => $previousDecision->decidedAt,
            };
        }
        if ($anchorDecidedAt !== null) {
            try {
                $anchorInstant = new DateTime($anchorDecidedAt);
            } catch (Throwable) {
                $anchorInstant = null;
            }
        }

        $replyLanguageDecision = new AgentReplyLanguageDecision();
        $replyLanguageDecision->ownerMessageIndex = $ownerMessageIndex;
        $replyLanguageDecision->decidedAt = (new DateTime())->format('Y-m-d H:i:s');
        $replyLanguageDecision->configuredLanguageCode = $configuredLanguageCode;
        $replyLanguageDecision->anchorLanguageCode = $anchorLanguageCode;
        $replyLanguageDecision->anchorDecidedAt = $anchorDecidedAt;
        $replyLanguageDecision->directiveValidFrom = $newestDirectiveValidFrom?->format('Y-m-d H:i:s');
        $replyLanguageQuestion = new AgentReplyLanguageDecisionQuestion();
        $replyLanguageQuestion->ownerMessageIndex = $ownerMessageIndex;
        $replyLanguageQuestion->ownerWords = $ownerWords;
        $replyLanguageQuestion->configuredLanguageCode = $configuredLanguageCode;
        $replyLanguageQuestion->supportedLanguageCodes = $supportedLanguageCodes;
        $replyLanguageQuestion->standingDirectivesBlock = $standingDirectivesBlock;
        $replyLanguageQuestion->newestDirectiveValidFrom = $newestDirectiveValidFrom;
        $replyLanguageQuestion->anchorLanguageCode = $anchorLanguageCode;
        $replyLanguageQuestion->anchorInstant = $anchorInstant;
        $replyLanguageQuestion->replyLanguageDecision = $replyLanguageDecision;
        return $replyLanguageQuestion;
    }

    /**
     * Parse the language answers of the folded turn-start request and stamp the site's slice: the
     * precedence by RECENCY is resolved here ({@see AgentReplyLanguageDecision::resolveByRecency()}); a fallback whose
     * chain resolves to the conversation's own anchor is applied as the anchor under `on`. Records the decision.
     *
     * @param string|null $decidedBy the batch's own fallback, null when the request answered
     * @throws AgentRunClaimLostException the run claim was lost while the slice was persisted
     */
    public function applyDecision(
        AIConversation $conversation,
        AgentReplyLanguageDecisionQuestion $replyLanguageQuestion,
        ?ArgusAgentTurnStartDecision $argusReplyLanguageDecision,
        ?string $decidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?int $elapsedMs,
        ?string $transportFailureReason,
        ?string $rawResponseExcerpt
    ): void {
        $ownerMessageIndex = $replyLanguageQuestion->ownerMessageIndex;
        $ownerWords = $replyLanguageQuestion->ownerWords;
        $configuredLanguageCode = $replyLanguageQuestion->configuredLanguageCode;
        $supportedLanguageCodes = $replyLanguageQuestion->supportedLanguageCodes;
        $newestDirectiveValidFrom = $replyLanguageQuestion->newestDirectiveValidFrom;
        $anchorLanguageCode = $replyLanguageQuestion->anchorLanguageCode;
        $anchorInstant = $replyLanguageQuestion->anchorInstant;
        $replyLanguageDecision = $replyLanguageQuestion->replyLanguageDecision;
        $agentWorkingState = $conversation->getAgentWorkingState();
        $languageDecidedBy = $decidedBy;
        if ($languageDecidedBy === null) {
            if ($argusReplyLanguageDecision === null || !$argusReplyLanguageDecision->languageAnswersUsable()) {
                $languageDecidedBy = AgentReplyLanguageDecision::FALLBACK_UNPARSABLE;
            } else {
                $replyLanguageDecision->requestedLanguageCode = $argusReplyLanguageDecision->requestedLanguageCode;
                $replyLanguageDecision->requestedConfidence = $argusReplyLanguageDecision->requestedConfidence;
                $replyLanguageDecision->writtenLanguageCode = $argusReplyLanguageDecision->writtenLanguageCode;
                $replyLanguageDecision->writtenConfidence = $argusReplyLanguageDecision->writtenConfidence;
                $replyLanguageDecision->directiveLanguageCode = $argusReplyLanguageDecision->directiveLanguageCode;
                $replyLanguageDecision->directiveConfidence = $argusReplyLanguageDecision->directiveConfidence;
                $replyLanguageDecision->modelVersion = $argusReplyLanguageDecision->modelVersion;
                $languageDecidedBy = $decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, $conversation) === AgentDecisionModelSettings::MODE_ON
                    ? AgentReplyLanguageDecision::DECIDED_BY_DECISION_MODEL
                    : AgentReplyLanguageDecision::DECIDED_BY_SHADOW;
            }
        }
        $replyLanguageDecision->decidedBy = $languageDecidedBy;
        // a first message of one bare token is inconclusive: the record keeps the model's
        // written answer, the chain does not act on it
        $writtenLanguageCodeForChain = AgentReplyLanguageDecision::writtenEvidenceIsInconclusiveForFirstMessage($anchorLanguageCode, $ownerWords)
            ? AgentReplyLanguageDecision::ANSWER_UNDECIDABLE
            : $replyLanguageDecision->writtenLanguageCode;
        $replyLanguageResolution = AgentReplyLanguageDecision::resolveByRecency(
            $replyLanguageDecision->requestedLanguageCode,
            $replyLanguageDecision->requestedConfidence ?? 0.0,
            $writtenLanguageCodeForChain,
            $replyLanguageDecision->writtenConfidence ?? 0.0,
            $anchorLanguageCode,
            $anchorInstant,
            $replyLanguageDecision->directiveLanguageCode,
            $replyLanguageDecision->directiveConfidence ?? 0.0,
            $newestDirectiveValidFrom,
            $configuredLanguageCode,
            $supportedLanguageCodes
        );
        $replyLanguageDecision->languageCode = $replyLanguageResolution->languageCode;
        $replyLanguageDecision->source = $replyLanguageResolution->source;
        // A fallback turn whose chain resolved to the conversation's own ANCHOR needs no model answer: the anchor
        // is an earlier applied decision. In mode `on` it is applied — a provider timeout must never flip the
        // owner back to the configured language (the failure reason stays on the record for the inspector).
        if ($decisionModelSettings->siteModeForConversation(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, $conversation) === AgentDecisionModelSettings::MODE_ON
            && $replyLanguageResolution->source === AgentReplyLanguageDecision::SOURCE_ANCHOR
            && $replyLanguageDecision->isFallback()
        ) {
            $replyLanguageDecision->decidedBy = AgentReplyLanguageDecision::DECIDED_BY_ANCHOR;
        }

        if ($agentWorkingState === null) {
            $agentWorkingState = new AgentWorkingState();
            $conversation->agentWorkingState = $agentWorkingState;
        }
        $agentWorkingState->replyLanguageDecision = $replyLanguageDecision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'replyLanguageDecision');
        $this->decisionCallsService()->recordDecision($conversation, $replyLanguageDecision->buildRecord(
            AgentDecisionModelSettings::SITE_REPLY_LANGUAGE,
            $replyLanguageDecision->isApplied(),
            $elapsedMs,
            $transportFailureReason,
            $argusReplyLanguageDecision?->stateForSite(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE),
            $argusReplyLanguageDecision?->answersForSite(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE),
            [
                'applied' => $replyLanguageDecision->isApplied(),
                'languageCode' => $replyLanguageResolution->languageCode,
                'source' => $replyLanguageResolution->source,
                'configuredLanguageCode' => $configuredLanguageCode,
                'anchorLanguageCode' => $anchorLanguageCode,
                'directiveValidFrom' => $replyLanguageDecision->directiveValidFrom,
            ],
            $argusReplyLanguageDecision?->questionsForSite(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE)
        ));
        $this->aiTrace('turn.replyLanguage.decided', [
            'conversationId' => $conversation->id ?? null,
            'ownerMessageIndex' => $ownerMessageIndex,
            'languageCode' => $replyLanguageResolution->languageCode,
            'source' => $replyLanguageResolution->source,
            'decidedBy' => $replyLanguageDecision->decidedBy,
            'configured' => $configuredLanguageCode,
            'written' => $replyLanguageDecision->writtenLanguageCode,
            'requested' => $replyLanguageDecision->requestedLanguageCode,
            'directive' => $replyLanguageDecision->directiveLanguageCode,
            'modelVersion' => $replyLanguageDecision->modelVersion,
            'transportFailureReason' => $transportFailureReason,
            'rawResponseExcerpt' => $rawResponseExcerpt,
            'elapsedMs' => $elapsedMs,
        ]);
    }

    /**
     * The reply-language stamp of a USER-ABSENT run: the configured language of the conversation, decided by the platform
     * ({@see AgentReplyLanguageDecision::forUserAbsentRun()}), persisted as the working state's slice and recorded as
     * a decision of the `replyLanguage` site without a model call. The context build names it in the reply-language
     * cue like a model decision.
     */
    protected function stampPlatformReplyLanguageForUserAbsentRun(AIConversation $conversation, int $ownerMessageIndex): void
    {
        $configuredLanguageCode = $conversation->resolveUserLanguageCode();
        $replyLanguageDecision = AgentReplyLanguageDecision::forUserAbsentRun($ownerMessageIndex, $configuredLanguageCode, new DateTime());
        $agentWorkingState = $conversation->getOrCreateAgentWorkingState();
        $agentWorkingState->replyLanguageDecision = $replyLanguageDecision;
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = AIConversation::getService();
        $aIConversationsService->persistAgentWorkingStateSlice($conversation, 'replyLanguageDecision');
        $this->decisionCallsService()->recordDecision($conversation, $replyLanguageDecision->buildRecord(
            AgentDecisionModelSettings::SITE_REPLY_LANGUAGE,
            true,
            null,
            null,
            null,
            null,
            [
                'applied' => true,
                'languageCode' => $configuredLanguageCode,
                'source' => AgentReplyLanguageDecision::SOURCE_CONFIG,
                'configuredLanguageCode' => $configuredLanguageCode,
                'anchorLanguageCode' => null,
                'directiveValidFrom' => null,
                'reason' => 'user-absent run: no owner message to measure, the configured language applies',
            ]
        ));
        $this->aiTrace('turn.replyLanguage.decided', [
            'conversationId' => $conversation->id ?? null,
            'ownerMessageIndex' => $ownerMessageIndex,
            'languageCode' => $configuredLanguageCode,
            'source' => AgentReplyLanguageDecision::SOURCE_CONFIG,
            'decidedBy' => AgentReplyLanguageDecision::DECIDED_BY_PLATFORM,
            'configured' => $configuredLanguageCode,
            'initiatorType' => $conversation->initiatorType ?? null,
        ]);
    }

    /**
     * The ACTIVE app language codes (lowercase) — the closed answer set of the reply-language decision; the same
     * source the application's interface-language confirmation uses. [] when the locales service is unavailable (the
     * caller then adds the configured code and decides over that alone).
     *
     * @return string[]
     */
    protected function getActiveInterfaceLanguageCodes(): array
    {
        try {
            /** @var AgentSpacesService $agentSpacesService */
            $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
            return $agentSpacesService->getActiveInterfaceLanguageCodes();
        } catch (Throwable) {
            return [];
        }
    }

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        /** @var AgentDecisionCallsService $agentDecisionCallsService */
        $agentDecisionCallsService = DDDService::instance()->getService(AgentDecisionCallsService::class);
        return $agentDecisionCallsService;
    }
}
