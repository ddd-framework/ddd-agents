<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AgentReplyLanguageDecisionsService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionCallsService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\AIConversationsServiceRecordingSliceWrites;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * What applying a reply-language decision leaves behind when the decision model gave no usable answer: the language
 * and its source resolved by recency stand on the working state's slice and on the recorded decision, and a turn
 * whose chain resolved to the conversation's own earlier language is applied as that language while the site is on.
 */
class AgentReplyLanguageDecisionsServiceApplyDecisionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->replaceService(AIConversationsService::class, new AIConversationsServiceRecordingSliceWrites());
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    public function testAFallbackThatResolvesToTheEarlierLanguageIsAppliedAsThatLanguageWhileTheSiteIsOn(): void
    {
        $replyLanguageDecisionsService = new AgentReplyLanguageDecisionsServiceRecordingDecisions();
        $conversation = $this->conversation();

        $replyLanguageDecisionsService->applyDecision(
            $conversation,
            $this->questionWithEarlierLanguage('de'),
            null,
            AgentReplyLanguageDecision::FALLBACK_TRANSPORT,
            $this->decisionModelSettingsInMode(AgentDecisionModelSettings::MODE_ON),
            1200,
            'timeout',
            null
        );

        $replyLanguageDecision = $conversation->agentWorkingState->replyLanguageDecision;
        self::assertSame('de', $replyLanguageDecision->languageCode);
        self::assertSame(AgentReplyLanguageDecision::SOURCE_ANCHOR, $replyLanguageDecision->source);
        self::assertSame(AgentReplyLanguageDecision::DECIDED_BY_ANCHOR, $replyLanguageDecision->decidedBy);
        self::assertCount(1, $replyLanguageDecisionsService->recordedDecisionRecords);
        $recordedOutcome = $replyLanguageDecisionsService->recordedDecisionRecords[0]->outcome;
        self::assertSame('de', $recordedOutcome['languageCode']);
        self::assertSame(AgentReplyLanguageDecision::SOURCE_ANCHOR, $recordedOutcome['source']);
        self::assertSame('en', $recordedOutcome['configuredLanguageCode']);
        self::assertSame('timeout', $replyLanguageDecisionsService->recordedDecisionRecords[0]->failureReason);
    }

    public function testInShadowModeTheSameFallbackStaysAFallback(): void
    {
        $replyLanguageDecisionsService = new AgentReplyLanguageDecisionsServiceRecordingDecisions();
        $conversation = $this->conversation();

        $replyLanguageDecisionsService->applyDecision(
            $conversation,
            $this->questionWithEarlierLanguage('de'),
            null,
            AgentReplyLanguageDecision::FALLBACK_TRANSPORT,
            $this->decisionModelSettingsInMode(AgentDecisionModelSettings::MODE_SHADOW),
            1200,
            'timeout',
            null
        );

        $replyLanguageDecision = $conversation->agentWorkingState->replyLanguageDecision;
        self::assertSame('de', $replyLanguageDecision->languageCode);
        self::assertSame(AgentReplyLanguageDecision::FALLBACK_TRANSPORT, $replyLanguageDecision->decidedBy);
    }

    public function testWithoutAnEarlierLanguageTheConfiguredLanguageIsRecorded(): void
    {
        $replyLanguageDecisionsService = new AgentReplyLanguageDecisionsServiceRecordingDecisions();
        $conversation = $this->conversation();

        $replyLanguageDecisionsService->applyDecision(
            $conversation,
            $this->questionWithEarlierLanguage(null),
            null,
            AgentReplyLanguageDecision::FALLBACK_TRANSPORT,
            $this->decisionModelSettingsInMode(AgentDecisionModelSettings::MODE_ON),
            null,
            null,
            null
        );

        $replyLanguageDecision = $conversation->agentWorkingState->replyLanguageDecision;
        self::assertSame('en', $replyLanguageDecision->languageCode);
        self::assertSame(AgentReplyLanguageDecision::SOURCE_CONFIG, $replyLanguageDecision->source);
        self::assertSame(AgentReplyLanguageDecision::FALLBACK_TRANSPORT, $replyLanguageDecision->decidedBy);
        $recordedOutcome = $replyLanguageDecisionsService->recordedDecisionRecords[0]->outcome;
        self::assertSame('en', $recordedOutcome['languageCode']);
        self::assertSame(AgentReplyLanguageDecision::SOURCE_CONFIG, $recordedOutcome['source']);
    }

    protected function conversation(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->id = 912951;
        return $conversation;
    }

    protected function questionWithEarlierLanguage(?string $earlierLanguageCode): AgentReplyLanguageDecisionQuestion
    {
        $replyLanguageDecision = new AgentReplyLanguageDecision();
        $replyLanguageDecision->ownerMessageIndex = 4;
        $replyLanguageQuestion = new AgentReplyLanguageDecisionQuestion();
        $replyLanguageQuestion->ownerMessageIndex = 4;
        $replyLanguageQuestion->ownerWords = 'Bitte zeig mir die Bewertungen der letzten Woche.';
        $replyLanguageQuestion->configuredLanguageCode = 'en';
        $replyLanguageQuestion->supportedLanguageCodes = ['de', 'en', 'es'];
        $replyLanguageQuestion->anchorLanguageCode = $earlierLanguageCode;
        $replyLanguageQuestion->anchorInstant = $earlierLanguageCode !== null ? new DateTime('2026-10-01 09:00:00') : null;
        $replyLanguageQuestion->replyLanguageDecision = $replyLanguageDecision;
        return $replyLanguageQuestion;
    }

    protected function decisionModelSettingsInMode(string $mode): AgentDecisionModelSettings
    {
        $decisionModelSettings = new AgentDecisionModelSettings();
        $decisionModelSettings->mode = $mode;
        $decisionModelSettings->enabledSites = [strtolower(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE)];
        return $decisionModelSettings;
    }
}

/** The reply-language decisions with a decision log that keeps the records in memory. */
class AgentReplyLanguageDecisionsServiceRecordingDecisions extends AgentReplyLanguageDecisionsService
{
    /** @var AgentDecisionRecord[] */
    public array $recordedDecisionRecords = [];

    protected function decisionCallsService(): AgentDecisionCallsService
    {
        $decisionCallsServiceRecordingDecisions = new AgentDecisionCallsServiceRecordingDecisions();
        $decisionCallsServiceRecordingDecisions->replyLanguageDecisionsService = $this;
        return $decisionCallsServiceRecordingDecisions;
    }
}

/** A decision log that hands every record to the service under test instead of writing it. */
class AgentDecisionCallsServiceRecordingDecisions extends AgentDecisionCallsService
{
    public AgentReplyLanguageDecisionsServiceRecordingDecisions $replyLanguageDecisionsService;

    public function recordDecision(AIConversation $conversation, AgentDecisionRecord $agentDecisionRecord): void
    {
        $this->replyLanguageDecisionsService->recordedDecisionRecords[] = $agentDecisionRecord;
    }
}
