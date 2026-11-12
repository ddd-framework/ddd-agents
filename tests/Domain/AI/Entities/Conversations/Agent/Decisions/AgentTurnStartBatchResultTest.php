<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartBatchResult;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSiteInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSubSkillDecisions;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What the folded turn-start request carries, and how each sub-skill child of the batch is applied —
 * an answered child stands on its own, an unanswered one takes the batch's fallback or its own transport failure.
 */
class AgentTurnStartBatchResultTest extends UnitTestCase
{
    public function testTheSiteInputsSayWhatTheRequestCarries(): void
    {
        $siteInputs = new AgentTurnStartSiteInputs();
        self::assertTrue($siteInputs->asksNothing());

        $siteInputs->ownerMessageForInjectionCheck = new AIConversationMessage();
        self::assertFalse($siteInputs->asksNothing());

        $siteInputs->replyLanguageQuestion = new AgentReplyLanguageDecisionQuestion();
        $siteInputs->planDynamicsQuestion = new AgentPlanDynamicsDecisionQuestion();
        self::assertTrue($siteInputs->primaryRequestCarriesASite());
        self::assertSame(['replyLanguage', AgentDecisionModelSettings::SITE_PLAN_DYNAMICS], $siteInputs->getRequestedSiteNames());
    }

    public function testASubSkillChildStandsOnItsOwnAnswerElseTakesTheBatchFallbackOrItsOwnFailure(): void
    {
        $answered = new ArgusAgentTurnStartDecision();
        $answered->decided = true;
        $failed = new ArgusAgentTurnStartDecision();
        $failed->transportFailed = true;
        $failed->transportFailureReason = 'timeout';
        $silent = new ArgusAgentTurnStartDecision();

        $result = new AgentTurnStartBatchResult();
        $result->subSkillDecisions->addDecisionOfLoadedSkill('A', $answered);
        $result->subSkillDecisions->addDecisionOfLoadedSkill('B', $failed);
        $result->subSkillDecisions->addDecisionOfLoadedSkill('C', $silent);
        self::assertNull($result->getSubSkillDecidedBy('A'));
        self::assertNull($result->getSubSkillFailureReason('A'));
        self::assertSame(AgentSkillPreloadDecision::FALLBACK_TRANSPORT, $result->getSubSkillDecidedBy('B'));
        self::assertSame('timeout', $result->getSubSkillFailureReason('B'));
        self::assertNull($result->getSubSkillDecidedBy('C'), 'an unanswered child without a failure is parsed by its site');

        $result->decidedBy = AgentReplyLanguageDecision::FALLBACK_BREAKER;
        $result->transportFailureReason = 'breaker';
        self::assertNull($result->getSubSkillDecidedBy('A'), 'an answer stands even under a batch fallback');
        self::assertSame(AgentReplyLanguageDecision::FALLBACK_BREAKER, $result->getSubSkillDecidedBy('C'));
        self::assertSame('breaker', $result->getSubSkillFailureReason('C'));
        self::assertSame('timeout', $result->getSubSkillFailureReason('B'), 'its own reason before the batch reason');
    }

    /**
     * Only a PARSABLE answer of the primary request (when it carries a
     * site) or of a sub-skill child closes the breaker — never the injection check's, never an unparsable one.
     */
    public function testOnlyAParsableAnswerOfABreakerRelevantChildClosesTheBreaker(): void
    {
        $siteInputs = new AgentTurnStartSiteInputs();
        $siteInputs->ownerMessageForInjectionCheck = new AIConversationMessage();
        $result = new AgentTurnStartBatchResult();
        $result->primaryDecision = new ArgusAgentTurnStartDecision();
        $result->primaryDecision->decided = true;
        self::assertFalse($result->hasAParsableBreakerRelevantAnswer($siteInputs), 'the primary carries no site: only the injection check rode the batch');

        $siteInputs->replyLanguageQuestion = new AgentReplyLanguageDecisionQuestion();
        self::assertTrue($result->hasAParsableBreakerRelevantAnswer($siteInputs));
        $result->primaryDecision->decided = false;
        self::assertFalse($result->hasAParsableBreakerRelevantAnswer($siteInputs), 'an unparsable primary answer');

        $unparsableChild = new ArgusAgentTurnStartDecision();
        $parsableChild = new ArgusAgentTurnStartDecision();
        $parsableChild->decided = true;
        $result->subSkillDecisions->addDecisionOfLoadedSkill('A', $unparsableChild);
        self::assertFalse($result->hasAParsableBreakerRelevantAnswer($siteInputs));
        $result->subSkillDecisions->addDecisionOfLoadedSkill('B', $parsableChild);
        self::assertTrue($result->hasAParsableBreakerRelevantAnswer($siteInputs), 'a parsable sub-skill child');

        $result->primaryDecision = null;
        $result->subSkillDecisions = new AgentTurnStartSubSkillDecisions();
        self::assertFalse($result->hasAParsableBreakerRelevantAnswer($siteInputs), 'the breaker was open: no request');
    }

    /**
     * The folded request is not `decided` while ONE of its sites went unanswered (the direct widget),
     * yet one usable site is a parsable answer and closes the breaker.
     */
    public function testOneUsableSiteOfThePrimaryRequestClosesTheBreaker(): void
    {
        $siteInputs = new AgentTurnStartSiteInputs();
        $siteInputs->planDynamicsQuestion = new AgentPlanDynamicsDecisionQuestion();
        $result = new AgentTurnStartBatchResult();
        $result->primaryDecision = new ArgusAgentTurnStartDecision();
        $result->primaryDecision->fillPlanDynamicsQuestions($siteInputs->planDynamicsQuestion);
        self::assertFalse($result->hasAParsableBreakerRelevantAnswer($siteInputs), 'the plan question went unanswered');

        $result->primaryDecision->needsWorkingPlanProbability = 0.3;
        self::assertFalse($result->primaryDecision->decided);
        self::assertTrue($result->hasAParsableBreakerRelevantAnswer($siteInputs));
    }

    public function testTheRequestEventNamesItsSitesAndTheAnswerState(): void
    {
        $siteInputs = new AgentTurnStartSiteInputs();
        $siteInputs->skillPreloadQuestion = new AgentSkillPreloadDecisionQuestion();
        $result = new AgentTurnStartBatchResult();
        $result->batchId = 'b1';

        self::assertSame(
            ['sites' => ['skillPreload'], 'batchId' => 'b1', 'decidedBy' => 'answered', 'transportFailureReason' => null, 'modelVersion' => null],
            $result->buildRequestEventPayload($siteInputs)
        );
    }
}
