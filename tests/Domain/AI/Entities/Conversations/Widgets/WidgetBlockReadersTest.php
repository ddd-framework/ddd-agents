<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The paused-turn record's widget types and the "answer carries a widget that asks
 * the owner or closes the setup" check read WidgetBlocks — the block's own top-level type (a nested "type" or one in a
 * later non-widget fence no longer counts), any syntax, `…Directive` for the widget check, a truncated block still does not count.
 */
class WidgetBlockReadersTest extends UnitTestCase
{
    public function testThePausedTurnRecordReadsEachBlocksOwnType(): void
    {
        $message = "```widget\n{\"data\":{\"type\":\"Nested\"},\"type\":\"QuickReplies\"}\n```\n~~~widget\n{\"type\":\"X2\"}\n~~~\n```json\n{\"type\":\"NotAWidget\"}\n```\n```widget\n{\"type\":\"QuickReplies\"}\n```";
        self::assertSame(['QuickReplies', 'X2'], (new \ReflectionMethod(AgentPausedTurnRecord::class, 'widgetTypesOf'))->invoke(null, $message));
    }

    public function testAnAnswerCarriesTheWidgetOnlyInAClosedBlockOfItsOwnType(): void
    {
        $asks = static fn (string $answer): bool => in_array('QuickReplies', AIConversationMessages::findClosedWidgetTypesOfText($answer), true);
        self::assertTrue($asks("Pick:\n```widget\n{\"type\":\"QuickReplies\",\"options\":[\"a\"]}\n```"));
        self::assertTrue($asks("```widget\n{\"type\":\"QuickRepliesDirective\"}\n```"), 'the legacy suffix is the same widget');
        self::assertFalse($asks("```widget\n{\"type\":\"QuickReplies\",\"options\":[\"a\""), 'a payload cut off before its JSON balances does not count');
        self::assertTrue($asks("```widget\n{\"type\":\"QuickReplies\"}\n``` Which one?"), 'a balanced block without a closer line renders closed (the frontend repair)');
        self::assertFalse($asks("```widget\n{\"type\":\"PostPreview\"}\n```\n```json\n{\"type\":\"QuickReplies\"}\n```"), 'a type in a later non-widget fence never counts');
        self::assertFalse($asks("```widget\n{\"type\":\"QuickReplies\",}\n```"), 'a closed block with invalid JSON no longer counts');
    }

    public function testTheVoiceTelemetryFlagSeesABlockWithJson(): void
    {
        self::assertNotSame([], WidgetBlocks::fromMarkdown("```widget\n{\"type\":\"QuickReplies\",}\n```")->getWidgetJsons(), 'invalid JSON still counts');
        self::assertSame([], WidgetBlocks::fromMarkdown("```widget\n```")->getWidgetJsons(), 'an empty fence no longer counts');
    }

    public function testTheAssistantWidgetBlockQueriesReadTheBlocksOwnType(): void
    {
        // plain or `…Directive`, an unclosed block counts (the frontend renders it), a later non-widget fence never
        $messages = $this->assistantMessages("```widget\n{\"type\":\"SetupHandoverDirective\"}\n```", 7);
        self::assertTrue($messages->hasAssistantWidgetBlockOfType('SetupHandover'));
        self::assertSame(7, $messages->buildShownWidgets()->findNewestOfType('SetupHandover')?->hostIndexInConversation);
        self::assertFalse($this->assistantMessages("```widget\n{\"type\":\"PostPreview\"}\n```\n```json\n{\"type\":\"SetupHandover\"}\n```")->hasAssistantWidgetBlockOfType('SetupHandover'));
        $unclosed = $this->assistantMessages("```widget\n{\"type\":\"SetupHandover\"}");
        self::assertTrue($unclosed->hasAssistantWidgetBlockOfType('SetupHandover'), 'a row without an index still counts for has…');
        self::assertNull($unclosed->buildShownWidgets()->findNewestOfType('SetupHandover')?->hostIndexInConversation, 'a row without an index has no newest index');
    }

    protected function assistantMessages(string $assistantText, ?int $indexInConversation = null): AIConversationMessages
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->message = $assistantText;
        $message->indexInConversation = $indexInConversation;
        /** @var AIConversationMessages $messages */
        $messages = self::instantiateWithoutConstructor(AIConversationMessages::class);
        $messages->elements = [$message];
        return $messages;
    }
}
