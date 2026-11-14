<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A `load_skills` call that loads nothing while the tool for the request is already loaded: what the call is answered
 * with (the loaded skills and their tools by name), and how the turn's rows tell whether the repeat guard already
 * answered such a call in an earlier step.
 */
class LoadSkillsCallWithoutEffectTest extends UnitTestCase
{
    public function testACallNamingNoSkillIsAnsweredWithTheLoadedSkillsAndTheirTools(): void
    {
        self::assertSame(
            'Nothing was loaded: `skill_names` named no skill. Loaded right now: Shop.Catalog, Shop.Orders — their tools are ALREADY '
                . 'in your tool list (getProducts, updateProduct, getOrders). Call the tool the request needs directly NOW. `load_skills` '
                . 'takes only a skill that is not loaded yet, named exactly as "Available skills" or the sub-skill list of a loaded skill '
                . 'states it; never call it with an empty list.',
            LoadSkillsTool::buildResultOfCallNamingNoSkill(['Shop.Catalog', 'Shop.Orders'], ['getProducts', 'updateProduct', 'getOrders', 'getProducts'])
        );
    }

    public function testWithoutALoadedSkillOrACallableToolTheCallNamingNoSkillAsksForAName(): void
    {
        self::assertSame(LoadSkillsTool::RESULT_WITHOUT_SKILL_NAMES, LoadSkillsTool::buildResultOfCallNamingNoSkill([], []));
        self::assertSame(LoadSkillsTool::RESULT_WITHOUT_SKILL_NAMES, LoadSkillsTool::buildResultOfCallNamingNoSkill(['Shop.Catalog'], []));
        self::assertSame(LoadSkillsTool::RESULT_WITHOUT_SKILL_NAMES, LoadSkillsTool::buildResultOfCallNamingNoSkill([], ['getProducts']));
    }

    public function testTheRepeatedCallIsAnsweredWithTheLoadedSkillsAndTheirToolsOrLeftToTheRepeatGuard(): void
    {
        self::assertSame(
            'NOT executed: you have called `load_skills` with these exact arguments 3 times and it loads nothing more. Loaded right '
                . 'now: Shop.Catalog — their tools are ALREADY in your tool list (getProducts, updateProduct). Call the tool the request '
                . 'needs directly NOW, or answer if the request needs no tool. Do not repeat this `load_skills` call.',
            LoadSkillsTool::buildResultOfRepeatedCall(3, ['Shop.Catalog'], ['getProducts', 'updateProduct'])
        );
        self::assertNull(LoadSkillsTool::buildResultOfRepeatedCall(3, [], []), 'no loaded skill: the repeat guard\'s own text applies');
        self::assertNull(LoadSkillsTool::buildResultOfRepeatedCall(3, ['Shop.Catalog'], []), 'no callable tool: the repeat guard\'s own text applies');
    }

    public function testAnAnswerListsTwelveToolNamesAndCountsTheRest(): void
    {
        $fifteenToolNames = [];
        for ($toolNumber = 1; $toolNumber <= 15; $toolNumber++) {
            $fifteenToolNames[] = 'getReport' . $toolNumber;
        }
        $result = LoadSkillsTool::buildResultOfCallNamingNoSkill(['Shop.Reports'], $fifteenToolNames);
        self::assertSame(12, LoadSkillsTool::MAX_TOOL_NAMES_LISTED_IN_A_RESULT);
        self::assertStringContainsString('getReport12 and 3 more).', $result);
        self::assertStringNotContainsString('getReport13', $result);
    }

    public function testACallTheRepeatGuardAnsweredInAnEarlierModelStepIsFound(): void
    {
        $owner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'add the product', 0);
        $loadWithoutEffect = $this->firstRowOfAModelStep($this->skillLoadCall(1));
        $loadWithoutEffect->toolCall->loadSkillsCallLoadedNothing = true;
        $answeredByTheRepeatGuard = $this->firstRowOfAModelStep($this->skillLoadCall(2));
        $answeredByTheRepeatGuard->toolCall->wasRefusedAsDuplicate = true;
        $statusLine = $this->firstRowOfAModelStep($this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Let me load the skill first.', 3));
        $workCall = $this->call('updateProduct', AIConversationMessage::STATUS_COMPLETED, 4);
        $newCall = $this->firstRowOfAModelStep($this->skillLoadCall(5));

        self::assertFalse(
            $this->messages($owner, $loadWithoutEffect, $newCall)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'a call that ran is no call the guard answered'
        );
        self::assertTrue(
            $this->messages($owner, $loadWithoutEffect, $answeredByTheRepeatGuard, $newCall)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep()
        );
        self::assertTrue(
            $this->messages($owner, $answeredByTheRepeatGuard, $statusLine, $workCall, $newCall)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'a status line or another call between the two does not hide the earlier one'
        );
    }

    public function testCallsOfTheNewestModelStepAnotherToolAndAnEarlierTurnDoNotCount(): void
    {
        $owner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'add the product', 0);
        $firstOfTheStep = $this->firstRowOfAModelStep($this->skillLoadCall(1));
        $firstOfTheStep->toolCall->wasRefusedAsDuplicate = true;
        $secondOfTheStep = $this->skillLoadCall(2);
        self::assertFalse(
            $this->messages($owner, $firstOfTheStep, $secondOfTheStep)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'the model wrote both calls before it read either answer'
        );

        $callOfTheNextStep = $this->firstRowOfAModelStep($this->skillLoadCall(3));
        self::assertTrue(
            $this->messages($owner, $firstOfTheStep, $secondOfTheStep, $callOfTheNextStep)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep()
        );

        $statusLineOfTheStep = $this->firstRowOfAModelStep($this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Loading the skill.', 1));
        $answeredBesideTheStatusLine = $this->skillLoadCall(2);
        $answeredBesideTheStatusLine->toolCall->wasRefusedAsDuplicate = true;
        $callBesideTheStatusLine = $this->skillLoadCall(3);
        self::assertFalse(
            $this->messages($owner, $statusLineOfTheStep, $answeredBesideTheStatusLine, $callBesideTheStatusLine)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'a step that begins with a status line holds its calls too'
        );

        $answeredItself = $this->firstRowOfAModelStep($this->skillLoadCall(1));
        $answeredItself->toolCall->wasRefusedAsDuplicate = true;
        self::assertFalse($this->messages($owner, $answeredItself)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep());

        $repeatOfAnotherTool = $this->firstRowOfAModelStep($this->call('getProducts', AIConversationMessage::STATUS_COMPLETED, 1));
        $repeatOfAnotherTool->toolCall->wasRefusedAsDuplicate = true;
        $newCall = $this->firstRowOfAModelStep($this->skillLoadCall(2));
        self::assertFalse($this->messages($owner, $repeatOfAnotherTool, $newCall)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep());

        $answeredInTheEarlierTurn = $this->firstRowOfAModelStep($this->skillLoadCall(1));
        $answeredInTheEarlierTurn->toolCall->wasRefusedAsDuplicate = true;
        $nextOwner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'and the price?', 2);
        $callOfTheNewTurn = $this->firstRowOfAModelStep($this->skillLoadCall(3));
        self::assertFalse(
            $this->messages($owner, $answeredInTheEarlierTurn, $nextOwner, $callOfTheNewTurn)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'an earlier turn\'s call never counts'
        );
    }

    public function testCallsOfTwoModelStepsThatCarryTheSameCallIdsAreToldApart(): void
    {
        $owner = $this->text(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'add the product', 0);
        $answeredInTheFirstStep = $this->firstRowOfAModelStep($this->skillLoadCall(1));
        $answeredInTheFirstStep->toolCall->wasRefusedAsDuplicate = true;
        $answeredInTheFirstStep->toolCall->toolCallId = 'tool_0_load_skills';
        $answeredInTheFirstStep->toolCall->emittedBatchId = 'tool_0_load_skills';
        $callOfTheSecondStep = $this->firstRowOfAModelStep($this->skillLoadCall(2));
        $callOfTheSecondStep->toolCall->toolCallId = 'tool_0_load_skills';
        $callOfTheSecondStep->toolCall->emittedBatchId = 'tool_0_load_skills';

        self::assertTrue(
            $this->messages($owner, $answeredInTheFirstStep, $callOfTheSecondStep)->buildCurrentTurn()->hasSkillLoadCallAnsweredByTheRepeatGuardBeforeTheNewestModelStep(),
            'the step is read from its first row, not from the ids the model provider sends'
        );
    }

    /** Marks the row as the one that carries the usage of its model call, as the loop does for the first row of a step. */
    protected function firstRowOfAModelStep(AIConversationMessage $message): AIConversationMessage
    {
        $message->inputTokens = 1;
        return $message;
    }

    protected function text(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    protected function call(string $toolName, string $status, int $index): AIConversationMessage
    {
        $message = $this->text(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = $status;
        $toolCall->arguments = [];
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function skillLoadCall(int $index): AIConversationMessage
    {
        $message = $this->call(LoadSkillsTool::NAME, AIConversationMessage::STATUS_COMPLETED, $index);
        $message->toolCall->arguments = ['skill_names' => []];
        return $message;
    }

    protected function messages(AIConversationMessage ...$rows): AIConversationMessages
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $row) {
            $messages->add($row);
        }
        return $messages;
    }
}
