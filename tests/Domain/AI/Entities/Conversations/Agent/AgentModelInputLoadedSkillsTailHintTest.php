<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The first model call of a turn is told which skills are loaded right now; from the turn's first tool call on the
 * entry is gone, and without a loaded skill there is none.
 */
class AgentModelInputLoadedSkillsTailHintTest extends UnitTestCase
{
    protected function buildOwnerTextMessage(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $ownerTextMessage */
        $ownerTextMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerTextMessage->role = AIConversationMessage::ROLE_USER;
        $ownerTextMessage->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerTextMessage->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerTextMessage->message = $text;
        return $ownerTextMessage;
    }

    protected function buildCompletedToolCallMessage(string $toolName): AIConversationMessage
    {
        /** @var AIConversationMessage $toolCallMessage */
        $toolCallMessage = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $toolCallMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = static::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCallMessage->toolCall = $toolCall;
        return $toolCallMessage;
    }

    protected function buildModelInputWithLoadedSkills(AIConversationMessage ...$messages): AgentModelInput
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Images' => ['name' => 'Images', 'type' => LoadedCapability::TYPE_SKILL],
            'Reviews' => ['name' => 'Reviews', 'type' => LoadedCapability::TYPE_SKILL],
            'listReviews' => ['name' => 'listReviews', 'type' => LoadedCapability::TYPE_TOOL],
        ]);
        $agentModelInput->messages = $messages;
        return $agentModelInput;
    }

    public function testTheFirstCallOfATurnNamesTheLoadedSkills(): void
    {
        $agentModelInput = $this->buildModelInputWithLoadedSkills(
            $this->buildOwnerTextMessage('earlier question'),
            $this->buildCompletedToolCallMessage('listReviews'),
            $this->buildOwnerTextMessage('Which image model fits portraits?')
        );

        self::assertSame(
            '[SIGNAL:skills] Loaded right now: Images, Reviews — their tools are in your tool list. load_skills is only'
                . ' for a skill that is not named here.',
            $agentModelInput->loadedSkillsTailHint()
        );
        $tailEntryKinds = array_map(
            static fn (AgentModelInputTailEntry $agentModelInputTailEntry): string => $agentModelInputTailEntry->kind,
            $agentModelInput->buildTailEntries(null, false)->getElements()
        );
        self::assertContains(AgentModelInputTailEntry::KIND_LOADED_SKILLS, $tailEntryKinds);
        self::assertSame(AgentModelInputTailEntry::KIND_REPLY_LANGUAGE_CUE, $tailEntryKinds[array_key_last($tailEntryKinds)], 'the reply-language cue stays last');
    }

    public function testAfterTheTurnsFirstToolCallTheEntryIsGone(): void
    {
        $agentModelInput = $this->buildModelInputWithLoadedSkills(
            $this->buildOwnerTextMessage('Which image model fits portraits?'),
            $this->buildCompletedToolCallMessage('listImageModels')
        );

        self::assertNull($agentModelInput->loadedSkillsTailHint());
    }

    public function testWithoutALoadedSkillThereIsNoEntry(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [$this->buildOwnerTextMessage('hello')];
        self::assertNull($agentModelInput->loadedSkillsTailHint());

        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'listReviews' => ['name' => 'listReviews', 'type' => LoadedCapability::TYPE_TOOL],
        ]);
        self::assertNull($agentModelInput->loadedSkillsTailHint(), 'a loaded tool is no loaded skill');
    }
}
