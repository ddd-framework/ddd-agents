<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Characterization of the AIConversationToolCallHistory questions that had no test: the newest
 * resolved call across names (the tie by message index, the argument filters incl. list containment), the oldest one,
 * the completed-call count, the message index by identity, and the eval counts (work calls exclude load_skills only).
 */
class AIConversationToolCallHistoryTest extends UnitTestCase
{
    public function testTheNewestResolvedCallAcrossNamesIsTheOneWithTheHigherIndexAndRespectsTheFilters(): void
    {
        $addCompetitors = $this->call('addCompetitors', AIConversationMessage::STATUS_COMPLETED, 4, ['names' => ['a']]);
        $updateCompetitors = $this->call('updateCompetitors', AIConversationMessage::STATUS_COMPLETED, 2, ['names' => ['b']]);
        $proposal = $this->call('addGoal', AIConversationMessage::STATUS_COMPLETED, 6, ['statedByOwner' => false, 'topics' => ['website']]);
        $ownerGoal = $this->call('addGoal', AIConversationMessage::STATUS_COMPLETED, 5, ['statedByOwner' => true, 'topics' => ['google_listing', 'website']]);
        $failed = $this->call('addCompetitors', AIConversationMessage::STATUS_FAILED, 8, []);
        $history = $this->messages($updateCompetitors, $addCompetitors, $ownerGoal, $proposal, $failed)->buildToolCallHistory();

        self::assertSame($addCompetitors->toolCall, $history->getNewestResolvedToolCallByAnyToolName(['updateCompetitors', 'addCompetitors']));
        self::assertSame($updateCompetitors->toolCall, $history->getOldestResolvedToolCallByAnyToolName(['addCompetitors', 'updateCompetitors']));
        self::assertSame($ownerGoal->toolCall, $history->getNewestResolvedToolCallByAnyToolName(['addGoal'], ['statedByOwner' => [false]]), 'a disqualified newer call is skipped');
        self::assertSame($ownerGoal->toolCall, $history->getNewestResolvedToolCallByAnyToolName(['addGoal'], null, ['topics' => ['google_listing']]), 'a list argument qualifies by containment');
        self::assertNull($history->getNewestResolvedToolCallByAnyToolName(['addGoal'], null, ['topics' => ['email']]));
        self::assertSame(4, $history->getMessageIndexOfToolCall($addCompetitors->toolCall));
        self::assertNull($history->getMessageIndexOfToolCall($this->call('addCompetitors', AIConversationMessage::STATUS_COMPLETED, 4, ['names' => ['a']])->toolCall), 'identity, not equality');
        self::assertSame(2, $history->countCompletedToolCallsNamed(['addCompetitors', 'updateCompetitors']));
    }

    public function testTheEvalCountsReadEveryToolCallRow(): void
    {
        $history = $this->messages(
            $this->call(LoadSkillsTool::NAME, AIConversationMessage::STATUS_COMPLETED, 0, []),
            $this->call('getRankings', AIConversationMessage::STATUS_FAILED, 1, []),
            $this->call('getRankings', AIConversationMessage::STATUS_COMPLETED, 2, [])
        )->buildToolCallHistory();
        self::assertSame([LoadSkillsTool::NAME, 'getRankings', 'getRankings'], $history->getCalledToolNames());
        self::assertSame(3, $history->countToolCalls());
        self::assertSame(2, $history->countWorkToolCalls(), 'only load_skills is no work here (a third "work" definition, D3)');
        self::assertCount(3, $history->getToolCallRows());
    }

    /** @param array<string, mixed> $arguments */
    protected function call(string $toolName, string $status, int $index, array $arguments): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $message->indexInConversation = $index;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = $status;
        $toolCall->arguments = $arguments;
        $toolCall->result = $status === AIConversationMessage::STATUS_COMPLETED ? '{"content":[{"type":"text","text":"ok"}]}' : 'failed';
        $message->toolCall = $toolCall;
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
