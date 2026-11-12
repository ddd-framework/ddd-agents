<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnClosingDecision;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The turn-closing questions and answer parsing: every question backticks its state path, both
 * branches are situations, the negative branch names the traps, the state is capped, a `noul` answer is a probability.
 */
class ArgusAgentTurnClosingDecisionTest extends UnitTestCase
{
    public function testQuestionsFollowTheWritingRules(): void
    {
        $questions = ArgusAgentTurnClosingDecision::buildQuestions();
        self::assertSame(
            [ArgusAgentTurnClosingDecision::QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS, ArgusAgentTurnClosingDecision::QUESTION_REPORTS_COMPLETED_WRITES],
            array_keys($questions)
        );
        foreach ($questions as $name => $question) {
            self::assertSame('noul', $question['type'], $name);
            self::assertStringContainsString('`assistantAnswer`', $question['instructions'], $name);
            self::assertSame(['true', 'false'], array_keys($question['criteria']), $name);
        }
        $announceFalseBranch = $questions[ArgusAgentTurnClosingDecision::QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS]['criteria']['false'];
        // rule 4: the traps — the interview question, the plain "not done", the conversational reply
        self::assertStringContainsString('asks the owner a question', $announceFalseBranch);
        self::assertStringContainsString('NOT done', $announceFalseBranch);
        self::assertStringContainsString('conversational reply', $announceFalseBranch);
        // rule 5: the blind spot is named
        self::assertStringContainsString('not evidence of what the text says', $questions[ArgusAgentTurnClosingDecision::QUESTION_ANNOUNCES_INSTEAD_OF_DELIVERS]['instructions']);
    }

    public function testTheStateIsTheAnswerAndTheCappedOpenItems(): void
    {
        $argus = new ArgusAgentTurnClosingDecision();
        $argus->assistantAnswer = 'Ich lade jetzt die Daten.';
        $argus->openWorkItems = array_map(static fn (int $i): string => "item $i", range(1, 12));
        $state = $argus->buildState();
        self::assertSame(['assistantAnswer', 'openWorkItems'], array_keys($state));
        self::assertCount(ArgusAgentTurnClosingDecision::MAX_OPEN_WORK_ITEMS_IN_STATE, $state['openWorkItems']);
        self::assertSame('item 1', $state['openWorkItems'][0]);
    }

    public function testANoulAnswerIsAProbabilityAndAMissingOneIsNull(): void
    {
        $argus = new ArgusAgentTurnClosingDecision();
        $answers = json_decode('{"announces_instead_of_delivers":{"type":"noul","noul":0.94},"reports_completed_writes":{"type":"noul","noul":"1.7"}}');
        self::assertTrue(self::invokeMethod($argus, 'applyAnswers', [$answers]));
        self::assertSame(0.94, $argus->announcesInsteadOfDeliversProbability);
        self::assertSame(1.0, $argus->reportsCompletedWritesProbability);
        $argus = new ArgusAgentTurnClosingDecision();
        self::assertFalse(self::invokeMethod($argus, 'applyAnswers', [json_decode('{"reports_completed_writes":{"type":"noul","noul":0.2}}')]));
        self::assertNull($argus->announcesInsteadOfDeliversProbability);
    }
}
