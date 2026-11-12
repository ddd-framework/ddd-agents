<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionAnswerProbabilities;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionChoiceAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionDistributionPick;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDirectWidgetDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortCatalogEfforts;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecision;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationRecentTurnTexts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The values the turn-start decisions return are value objects with named properties: the choice answer of the
 * decision request, the pick over a validated distribution (skill preload and direct widget), the recent turn texts
 * and the catalog efforts.
 */
class AgentTurnStartDecisionValueObjectsTest extends UnitTestCase
{
    public function testAChoiceAnswerCarriesTheChoiceTheConfidenceAndTheReportedProbabilities(): void
    {
        $choiceAnswer = AgentDecisionChoiceAnswer::forChoice('seo', 0.82, ['seo' => 0.8, 'none' => 0.2]);
        self::assertSame('seo', $choiceAnswer->choice);
        self::assertSame(0.82, $choiceAnswer->confidence);
        self::assertSame(['seo' => 0.8, 'none' => 0.2], $choiceAnswer->reportedProbabilities);

        $missingAnswer = AgentDecisionChoiceAnswer::forChoice(null, 0.0);
        self::assertNull($missingAnswer->choice);
        self::assertSame([], $missingAnswer->reportedProbabilities);
    }

    public function testThePreloadPickCarriesTheMarginAndTheTopTokenAlsoWhenItAbstains(): void
    {
        $picked = AgentSkillPreloadDecision::resolvePreloadFromDistribution(
            AgentDecisionAnswerProbabilities::fromReported(['seo' => 0.7, 'reviews' => 0.2, 'none' => 0.1], ['seo', 'reviews', 'none'])
        );
        self::assertTrue($picked->isPicked);
        self::assertNull($picked->skipReason);
        self::assertSame('seo', $picked->topToken);
        self::assertEqualsWithDelta(0.5, (float)$picked->marginOverSecond, 1e-9);

        $abstained = AgentSkillPreloadDecision::resolvePreloadFromDistribution(
            AgentDecisionAnswerProbabilities::fromReported(['seo' => 0.45, 'none' => 0.55], ['seo', 'none'])
        );
        self::assertFalse($abstained->isPicked);
        self::assertSame(AgentSkillPreloadDecision::SKIP_ABSTAINED, $abstained->skipReason);
        self::assertSame('seo', $abstained->topToken);

        $lowConfidence = AgentSkillPreloadDecision::resolvePreloadFromDistribution(
            AgentDecisionAnswerProbabilities::fromReported(['seo' => 0.35, 'reviews' => 0.3, 'none' => 0.35], ['seo', 'reviews', 'none'])
        );
        self::assertSame(AgentSkillPreloadDecision::SKIP_LOW_CONFIDENCE, $lowConfidence->skipReason);

        $invalid = AgentSkillPreloadDecision::resolvePreloadFromDistribution(null);
        self::assertSame(AgentSkillPreloadDecision::SKIP_INVALID_DISTRIBUTION, $invalid->skipReason);
        self::assertNull($invalid->topToken);
        self::assertNull($invalid->marginOverSecond);
    }

    public function testTheDirectWidgetPickReadsNoMargin(): void
    {
        $picked = AgentDirectWidgetDecision::resolveFromDistribution(
            AgentDecisionAnswerProbabilities::fromReported(['clock' => 0.9, 'none' => 0.1], ['clock', 'none'])
        );
        self::assertInstanceOf(AgentDecisionDistributionPick::class, $picked);
        self::assertTrue($picked->isPicked);
        self::assertSame('clock', $picked->topToken);
        self::assertNull($picked->marginOverSecond);

        $onlyNone = AgentDirectWidgetDecision::resolveFromDistribution(
            AgentDecisionAnswerProbabilities::fromReported(['none' => 1.0], ['clock', 'none'])
        );
        self::assertFalse($onlyNone->isPicked);
        self::assertSame(AgentDirectWidgetDecision::SKIP_ABSTAINED, $onlyNone->skipReason);
        self::assertNull($onlyNone->topToken);
    }

    public function testTheRecentTurnTextsAndTheCatalogEffortsCarryWhatTheyWereBuiltWith(): void
    {
        $recentTurnTexts = AIConversationRecentTurnTexts::forAssistantTextAndRecentTurns('Done.', ['owner: Hello', 'assistant: Done.']);
        self::assertSame('Done.', $recentTurnTexts->assistantTextBefore);
        self::assertSame(['owner: Hello', 'assistant: Done.'], $recentTurnTexts->recentTurns);

        $catalogEfforts = AgentReasoningEffortCatalogEfforts::forConfiguredAndCeiling(null, AgentReasoningEffortDecision::DEFAULT_CEILING);
        self::assertNull($catalogEfforts->configuredEffort);
        self::assertSame(AgentReasoningEffortDecision::DEFAULT_CEILING, $catalogEfforts->ceilingEffort);
    }
}
