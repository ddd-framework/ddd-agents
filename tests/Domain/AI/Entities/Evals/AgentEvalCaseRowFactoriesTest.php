<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunFigures;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The row factories write the keys of every kind of evaluated case in the order the stored case files have always
 * had them. The expected key lists are the array literals of the rows the tools, selection and recall evaluations
 * write (deterministic run, judged run, the error rows of both flavors, the not-applicable row, the selection hit row,
 * the recall row, the rows of a timed-out or silent child process), copied key by key; the values are read through
 * the same message accessors those statements call.
 */
class AgentEvalCaseRowFactoriesTest extends UnitTestCase
{
    public function testTheDeterministicRowKeepsItsKeyOrderAndValues(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $measurement = AgentEvalCaseRunMeasurement::forConversation($conversation, 1234);
        $measurement->messagesTrace = ['the trace'];

        $row = AgentEvalCaseRow::buildForDeterministicRun(
            AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'),
            new AgentEvalResult(true, [], 'listItems', $conversation),
            $measurement,
            AgentEvalRunOutcome::forConversation($conversation, ['reloaded' => true]),
            false,
            null,
            null
        );

        self::assertSame(
            ['skill', 'case', 'tool', 'passed', 'matchedTool', 'conversationId', 'skillPreload', 'planDynamics', 'taskPlanSize', 'reload', 'performance', 'loadedSkills', 'calledTools', 'failures', 'finalText', 'widgetBlocks', 'toolCallCount', 'workToolCallCount', 'inputTokens', 'outputTokens', 'totalTokens', 'costUsd', 'durationMs', 'messages'],
            array_keys($row->toArray())
        );
        $rowFields = $row->toArray();
        self::assertSame(['Catalog.Items', 'catalog.list', 'listItems', true, 'listItems', null, null, null], array_slice(array_values($rowFields), 0, 8));
        self::assertNull($rowFields['reload'], 'a transient run diagnoses no reload');
        self::assertSame(AgentEvalRunFigures::fromConversation($conversation)->toArray(), $rowFields['performance']);
        self::assertSame(['listItems'], $rowFields['calledTools']);
        self::assertSame('Here are your items.', $rowFields['finalText']);
        self::assertSame(1, $rowFields['toolCallCount']);
        self::assertSame(300, $rowFields['inputTokens']);
        self::assertSame(1234, $rowFields['durationMs']);
        self::assertSame(['the trace'], $rowFields['messages']);

        $storedRunRow = AgentEvalCaseRow::buildForDeterministicRun(
            AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'),
            new AgentEvalResult(false, ['no tool'], null, $conversation),
            $measurement,
            AgentEvalRunOutcome::forConversation($conversation, ['reloaded' => true]),
            true,
            null,
            null
        );
        self::assertSame(['reloaded' => true], $storedRunRow->getValue('reload'), 'a stored run carries its reload diagnostics');
        self::assertFalse($storedRunRow->isPassed());
        self::assertSame(['no tool'], $storedRunRow->getValue('failures'));
    }

    public function testTheJudgedRowKeepsItsKeyOrderAndAddsTheEvaluationError(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $measurement = AgentEvalCaseRunMeasurement::forConversation($conversation, 50);
        $verdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'PASS', 'goalMet' => true, 'reasons' => 'ok']);

        $passedRow = AgentEvalCaseRow::buildForJudgedRun(
            AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'),
            AgentEvalSimRunResult::forRun($verdict, AgentEvalSimRunResult::STOP_REASON_DONE, 2, $conversation),
            $measurement,
            []
        );

        self::assertSame(
            ['skill', 'case', 'tool', 'passed', 'flavor', 'compactionFired', 'conversationId', 'failures', 'performance', 'verdict', 'turns', 'stopReason', 'loadedSkills', 'calledTools', 'finalText', 'widgetBlocks', 'toolCallCount', 'workToolCallCount', 'inputTokens', 'outputTokens', 'totalTokens', 'costUsd', 'judgeCostUsd', 'driverCostUsd', 'durationMs', 'messages'],
            array_keys($passedRow->toArray())
        );
        self::assertTrue($passedRow->isPassed());
        self::assertSame('judge', $passedRow->getValue('flavor'));
        self::assertFalse($passedRow->getValue('compactionFired'));
        self::assertSame(['verdict' => 'PASS', 'goalMet' => true, 'reasons' => 'ok'], $passedRow->getValue('verdict'));
        self::assertSame(2, $passedRow->getValue('turns'));
        self::assertSame('DONE', $passedRow->getValue('stopReason'));

        $overBudgetRow = AgentEvalCaseRow::buildForJudgedRun(
            AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'),
            AgentEvalSimRunResult::forRun($verdict, AgentEvalSimRunResult::STOP_REASON_DONE, 2, $conversation),
            $measurement,
            ['model calls 9 > 5']
        );
        self::assertFalse($overBudgetRow->isPassed(), 'a pass verdict over its budget fails');

        $errorVerdict = AgentEvalJudgeVerdict::fromArray(['verdict' => 'FAIL', 'reasons' => 'cut off']);
        $errorVerdict->markAsEvaluationError('cut off twice');
        $errorRow = AgentEvalCaseRow::buildForJudgedRun(
            AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'),
            AgentEvalSimRunResult::forRun($errorVerdict, AgentEvalSimRunResult::STOP_REASON_DONE, 2, $conversation),
            $measurement,
            []
        );
        self::assertSame('error', array_key_last($errorRow->toArray()));
        self::assertSame('judge evaluation error: cut off twice', $errorRow->getError());
    }

    public function testTheErrorRowsOfBothFlavorsKeepTheirKeyOrder(): void
    {
        $header = AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems');

        self::assertSame(
            ['skill' => 'Catalog.Items', 'case' => 'catalog.list', 'tool' => 'listItems', 'passed' => false, 'error' => 'boom'],
            AgentEvalCaseRow::buildFailedOfCase($header, null, 'boom')->toArray()
        );
        self::assertSame(
            ['skill' => 'Catalog.Items', 'case' => 'catalog.list', 'tool' => 'listItems', 'passed' => false, 'flavor' => 'judge', 'error' => 'boom'],
            AgentEvalCaseRow::buildFailedOfCase(AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems'), 'judge', 'boom')->toArray()
        );
        self::assertSame(
            ['skill' => 'Selection.Skill', 'case' => 'selection.a', 'passed' => false, 'error' => 'boom'],
            AgentEvalCaseRow::buildFailedOfCase(AgentEvalCaseRowHeader::withoutFields()->addField('skill', 'Selection.Skill')->addField('case', 'selection.a'), null, 'boom')->toArray()
        );
    }

    public function testTheNotApplicableRowNamesFlavorAndScopeAfterTheCase(): void
    {
        $header = AgentEvalCaseRowHeader::forCase('Catalog.Items', 'catalog.list', 'listItems')->addFlavor('call')->addScope(77);
        $reason = AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason('the seed failed');

        $row = AgentEvalCaseRow::buildNotApplicableOfCase($header, $reason);

        self::assertSame(['skill', 'case', 'tool', 'flavor', 'agentSpaceId', 'passed', 'outcome', 'notApplicableReason', 'notApplicableDetail'], array_keys($row->toArray()));
        self::assertSame(77, $row->getValue('agentSpaceId'));
        self::assertTrue($row->isNotApplicable());
        self::assertSame(AgentEvalCaseRow::buildNotApplicable($header->toArray(), $reason)->toArray(), $row->toArray());
    }

    public function testAnApplicationsHeaderNamesTheScopeWithItsOwnKey(): void
    {
        $header = AgentEvalCaseRowHeaderOfAnApplication::forCase('Catalog.Items', 'catalog.list', '?')->addFlavor('judge')->addScope(5);

        self::assertSame(['skill' => 'Catalog.Items', 'case' => 'catalog.list', 'tool' => '?', 'flavor' => 'judge', 'storeId' => 5], $header->toArray());
        self::assertSame('Catalog.Items', $header->getValue('skill'));
        self::assertNull($header->getValue('missing'));
    }

    public function testTheSelectionRowKeepsItsKeyOrderAndTellsAHitFromAMiss(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $measurement = AgentEvalCaseRunMeasurement::forConversation($conversation, 77);
        $messagesWithLoadedSkills = new MessagesWithLoadedSkillsDouble();
        foreach ($measurement->messagesOfTree->getElements() as $message) {
            $messagesWithLoadedSkills->add($message);
        }
        $messagesWithLoadedSkills->loadedSkillNamesOfTest = ['Catalog.Items'];
        $measurement->messagesOfTree = $messagesWithLoadedSkills;
        $header = AgentEvalCaseRowHeader::withoutFields()->addField('skill', 'Other.Skill|Catalog.Items')->addField('case', 'selection.a');

        $hitRow = AgentEvalCaseRow::buildForSelectionRun($header, 'Other.Skill|Catalog.Items', $measurement);
        $missRow = AgentEvalCaseRow::buildForSelectionRun(AgentEvalCaseRowHeader::withoutFields()->addField('skill', 'Other.Skill')->addField('case', 'selection.b'), 'Other.Skill', $measurement);

        self::assertSame(
            ['skill', 'case', 'passed', 'conversationId', 'failures', 'loadedSkills', 'calledTools', 'toolCallCount', 'workToolCallCount', 'totalTokens', 'costUsd', 'durationMs', 'performance'],
            array_keys($hitRow->toArray())
        );
        self::assertTrue($hitRow->isPassed(), 'either skill of `A|B` answers the request');
        self::assertSame([], $hitRow->getValue('failures'));
        self::assertFalse($missRow->isPassed());
        self::assertSame(["skill not loaded: expected 'Other.Skill', loaded [Catalog.Items]"], $missRow->getValue('failures'));
    }

    public function testASelectionCaseCanExpectNoSkillAndForbidSkills(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $measurement = AgentEvalCaseRunMeasurement::forConversation($conversation, 5);
        $messagesWithLoadedSkills = new MessagesWithLoadedSkillsDouble();
        foreach ($measurement->messagesOfTree->getElements() as $message) {
            $messagesWithLoadedSkills->add($message);
        }
        $measurement->messagesOfTree = $messagesWithLoadedSkills;
        $header = AgentEvalCaseRowHeader::withoutFields()->addField('skill', 'none')->addField('case', 'selection.none');

        $messagesWithLoadedSkills->loadedSkillNamesOfTest = [];
        self::assertTrue(AgentEvalCaseRow::buildForSelectionRun($header, AgentEvalCase::EXPECTED_SKILL_NONE, $measurement)->isPassed(), 'no skill loaded: the expectation `none` holds');

        $messagesWithLoadedSkills->loadedSkillNamesOfTest = ['Catalog.Items'];
        $loadedAlthoughNoneRow = AgentEvalCaseRow::buildForSelectionRun($header, AgentEvalCase::EXPECTED_SKILL_NONE, $measurement);
        self::assertFalse($loadedAlthoughNoneRow->isPassed());
        self::assertSame(['skill loaded although none was expected: [Catalog.Items]'], $loadedAlthoughNoneRow->getValue('failures'));

        $forbiddenRow = AgentEvalCaseRow::buildForSelectionRun($header, 'Catalog.Items', $measurement, ['Catalog.Items', 'Other.Skill']);
        self::assertFalse($forbiddenRow->isPassed(), 'the expected skill was loaded, but it is also forbidden');
        self::assertSame(["forbidden skill loaded: 'Catalog.Items'"], $forbiddenRow->getValue('failures'));

        $case = AgentEvalCase::fromArray(['prompt' => 'p', 'expectedSkill' => 'none', 'forbiddenSkills' => [' Catalog.Items ', '']]);
        self::assertSame(['Catalog.Items'], $case->getForbiddenSkillNames());
        self::assertSame(['Other.Skill'], AgentEvalCase::fromArray(['forbiddenSkills' => 'Other.Skill'])->getForbiddenSkillNames());
    }

    public function testTheRecallRowKeepsItsKeyOrderAndItsChecks(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $case = AgentEvalCase::fromArray(['id' => 'recall.a', 'expect' => ['tool' => ['listItems', 'searchConversations']], 'expectMentions' => ['items', 'invoice']]);

        $row = AgentEvalCaseRow::buildForRecallRun($case, $conversation, ['trace'], [11, 12], null, 900);

        self::assertSame(
            ['id', 'case', 'passed', 'failures', 'performance', 'durationMs', 'totalTokens', 'costUsd', 'seededConversationIds', 'calledTools', 'toolOk', 'missingMentions', 'leakedMentions', 'finalText', 'error', 'messages'],
            array_keys($row->toArray())
        );
        self::assertFalse($row->isPassed(), 'one mention is missing');
        self::assertTrue($row->getValue('toolOk'));
        self::assertSame(['invoice'], $row->getValue('missingMentions'));
        self::assertSame(["the answer does not mention 'invoice'"], $row->getValue('failures'));
        self::assertSame([11, 12], $row->getValue('seededConversationIds'));
        self::assertTrue($row->hasKey('error'), 'a row without an error keeps `error: null`');

        $threwRow = AgentEvalCaseRow::buildForRecallRun(AgentEvalCase::fromArray(['expect' => ['tool' => 'searchConversations']]), null, null, [], 'the seed failed', 3);
        self::assertSame('unknown', $threwRow->getValue('id'));
        self::assertNull($threwRow->getValue('performance'));
        self::assertSame(0, $threwRow->getValue('totalTokens'));
        self::assertSame(0.0, $threwRow->getValue('costUsd'));
        self::assertTrue($threwRow->getValue('toolOk'), 'an expected tool given as one name is no list: no tool check, as the recall evaluation has always read it');
        self::assertFalse($threwRow->isPassed());
    }

    public function testTheRowsOfAChildProcessWithoutAResultKeepTheirText(): void
    {
        self::assertSame(
            ['case' => 'catalog.list', 'passed' => false, 'failures' => ["the case's child process exceeded its 600s ceiling and was killed (raise the case's timeoutSeconds when the flow is legitimately that long)"], 'error' => 'child process timed out'],
            AgentEvalCaseRow::buildForTimedOutChildProcess('catalog.list', 600)->toArray()
        );
        self::assertSame(
            ['case' => 'catalog.list', 'passed' => false, 'error' => 'no parseable JSON from child process'],
            AgentEvalCaseRow::buildForUnparseableChildOutput('catalog.list')->toArray()
        );
    }

    public function testARepetitionIsAppendedOnlyForARepeatedCase(): void
    {
        $row = AgentEvalCaseRow::buildForUnparseableChildOutput('catalog.list');
        $row->stampRepetition(0);
        self::assertFalse($row->hasKey('repetition'));
        $row->stampRepetition(3);
        self::assertSame('repetition', array_key_last($row->toArray()));
        self::assertSame(3, $row->getValue('repetition'));
    }

    public function testTheMeasurementReadsTheTreeAndTheId(): void
    {
        $conversation = $this->conversationWithOneToolCall();
        $conversation->id = 4711;
        $startedAt = microtime(true);

        $measurement = AgentEvalCaseRunMeasurement::forConversation($conversation, 10);

        self::assertSame(4711, $measurement->getConversationId());
        self::assertSame(3, $measurement->messagesOfTree->count());
        self::assertNull($measurement->messagesTrace);
        self::assertGreaterThanOrEqual(0, AgentEvalCaseRunMeasurement::measureDurationMsSince($startedAt));
    }

    /** The owner asks, the agent calls one tool and answers. */
    protected function conversationWithOneToolCall(): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        $ownerMessage = $this->message(AIConversationMessage::ROLE_USER, AIConversationMessage::MESSAGE_TEXT, 'Which items do I have?', 0, 0.0);
        $ownerMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $toolCallMessage = $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TOOL_CALL, '', 100, 0.001);
        $toolCall = new ToolCall();
        $toolCall->name = 'listItems';
        $toolCall->arguments = [];
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCallMessage->toolCall = $toolCall;
        $answerMessage = $this->message(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, 'Here are your items.', 200, 0.002);
        $answerMessage->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        foreach ([$ownerMessage, $toolCallMessage, $answerMessage] as $index => $message) {
            $message->indexInConversation = $index;
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function message(string $role, string $messageType, string $text, int $inputTokens, float $costInUSD): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = $role;
        $message->messageType = $messageType;
        $message->message = $text;
        $message->inputTokens = $inputTokens;
        $message->outputTokens = 0;
        $message->cachedInputTokens = 0;
        $message->costInUSD = $costInUSD;
        $message->toolCall = null;
        $message->getChangeHistory()->createdTime = new DateTime('2026-10-01 10:00:0' . $inputTokens % 9);
        return $message;
    }
}
