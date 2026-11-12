<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionAnswerProbabilities;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The validated answer distribution of one `choice` question: what counts as usable, the order
 * it keeps, and the questions the preload, the stall recovery and the plan bookkeeping ask of it.
 */
class AgentDecisionAnswerProbabilitiesTest extends UnitTestCase
{
    public function testOnlyAUsableDistributionIsBuilt(): void
    {
        $allowed = ['call_a_work_tool', 'end_the_turn_with_text', 'none'];
        self::assertSame(
            ['call_a_work_tool' => 0.5, 'end_the_turn_with_text' => 0.3, 'none' => 0.2],
            AgentDecisionAnswerProbabilities::fromReported([' Call_A_Work_Tool ' => 0.5, 'end_the_turn_with_text' => '0.3', 'none' => 0.2], $allowed)?->toArray(),
            'answers are trimmed and lowercased, numeric strings become floats, the reported order stays'
        );
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported([], $allowed), 'an absent distribution');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 0.5, 'load_required_capability' => 0.5], $allowed), 'an answer the question did not allow');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 'x', 'none' => 1.0], $allowed), 'a non-numeric value');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => INF, 'none' => 0.0], $allowed), 'a non-finite value');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 1.2, 'none' => -0.2], $allowed), 'a value outside [0, 1]');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 0.5, 'none' => 0.44], $allowed), 'the sum is farther than 0.05 from 1');
        self::assertNotNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 0.5, 'none' => 0.46], $allowed), 'a sum within the tolerance');
        self::assertNull(AgentDecisionAnswerProbabilities::fromReported(['call_a_work_tool' => 0.5, 'none' => 0.46], $allowed, 0.01), 'a tighter tolerance');
        self::assertSame(
            ['call_a_work_tool' => 0.8],
            AgentDecisionAnswerProbabilities::fromReported(['Call_A_Work_Tool' => 0.2, 'call_a_work_tool' => 0.8], $allowed)?->toArray(),
            'two keys folding to one answer: the last value stays and both count to the sum'
        );
    }

    public function testTheRankingQuestions(): void
    {
        $distribution = AgentDecisionAnswerProbabilities::fromReported(['a' => 0.2, 'b' => 0.5, 'c' => 0.3], ['a', 'b', 'c']);
        self::assertNotNull($distribution);
        self::assertSame('b', $distribution->getTopAnswer());
        self::assertSame(0.5, $distribution->getTopProbability());
        self::assertSame(0.3, $distribution->getSecondProbability());
        self::assertEqualsWithDelta(0.2, $distribution->getMarginOverSecond(), 1e-12);
        self::assertTrue($distribution->isUniqueTop('b'));
        self::assertFalse($distribution->isUniqueTop('c'));
        self::assertSame(0.2, $distribution->getProbabilityOf('a'));
        self::assertNull($distribution->getProbabilityOf('d'));
        self::assertSame(['a' => 0.2, 'b' => 0.5, 'c' => 0.3], $distribution->toArray(), 'ranking never reorders the persisted map');

        $tie = AgentDecisionAnswerProbabilities::fromReported(['x' => 0.4, 'y' => 0.4, 'z' => 0.2], ['x', 'y', 'z']);
        self::assertNotNull($tie);
        self::assertSame('x', $tie->getTopAnswer(), 'a tie keeps the reported order');
        self::assertFalse($tie->isUniqueTop('x'), 'a tie is no unique top');

        $single = AgentDecisionAnswerProbabilities::fromReported(['x' => 1.0], ['x']);
        self::assertNotNull($single);
        self::assertSame(0.0, $single->getSecondProbability());
        self::assertSame(1.0, $single->getMarginOverSecond());
        self::assertTrue($single->isUniqueTop('x'));
    }

    public function testWithoutAnAnswerKeepsTheRestAndCanBecomeEmpty(): void
    {
        $distribution = AgentDecisionAnswerProbabilities::fromReported(['none' => 0.3, 'media.images' => 0.65, 'seo.rankings' => 0.05], ['media.images', 'seo.rankings', 'none']);
        self::assertNotNull($distribution);
        $candidates = $distribution->withoutAnswer('none');
        self::assertSame(['media.images' => 0.65, 'seo.rankings' => 0.05], $candidates->toArray());
        self::assertSame(0.3, $distribution->getProbabilityOf('none'), 'the original stays untouched');
        self::assertFalse($candidates->isEmpty());

        $onlyNone = AgentDecisionAnswerProbabilities::fromReported(['none' => 1.0], ['none']);
        self::assertNotNull($onlyNone);
        $empty = $onlyNone->withoutAnswer('none');
        self::assertTrue($empty->isEmpty());
        self::assertNull($empty->getTopAnswer());
        self::assertSame(0.0, $empty->getTopProbability());
    }

    public function testTheReportedProbabilityOfOneAnswerIsReadClampedWithoutValidation(): void
    {
        self::assertSame(0.7, AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['done' => 0.7, 'open' => 0.9], 'done'), 'a sum far from 1 is not checked');
        self::assertSame(1.0, AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['done' => 1.4], 'done'));
        self::assertSame(0.0, AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['done' => -0.1], 'done'));
        self::assertSame(0.6, AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['done' => '0.6'], 'done'));
        self::assertNull(AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['done' => 'x'], 'done'));
        self::assertNull(AgentDecisionAnswerProbabilities::readClampedReportedProbabilityOf(['open' => 0.9], 'done'));
    }
}
