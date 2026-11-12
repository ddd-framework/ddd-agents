<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use ReflectionClass;

/**
 * Message rows for the widget view tests: the host rows a widget stands in, the owner's reaction
 * rows and the rows that are neither — built without the container, as the in-memory gates see them.
 */
trait ConversationWidgetTestRows
{
    /** The model's own text row. */
    protected static function buildAssistantTextRow(int $indexInConversation, string $text): AIConversationMessage
    {
        return self::buildRow($indexInConversation, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, AIConversationMessage::MESSAGE_TEXT, null, $text);
    }

    /** A row a tool (or the platform) wrote through the one writer. */
    protected static function buildToolRow(int $indexInConversation, string $text, string $toolName = 'someTool'): AIConversationMessage
    {
        return self::buildRow($indexInConversation, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, AIConversationMessage::MESSAGE_TOOL_MESSAGE, $toolName, $text);
    }

    /** The owner's own text. */
    protected static function buildOwnerTextRow(int $indexInConversation, string $text): AIConversationMessage
    {
        return self::buildRow($indexInConversation, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_TEXT, null, $text);
    }

    /** A SIGNAL row of `$signalSource` (a widget click when the frontend wrote it). */
    protected static function buildSignalRow(int $indexInConversation, string $signalSource, string $text): AIConversationMessage
    {
        return self::buildRow($indexInConversation, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, AIConversationMessage::MESSAGE_SIGNAL, $signalSource, $text);
    }

    protected static function buildRow(int $indexInConversation, string $role, string $authorType, string $messageType, ?string $signalSource, string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = (new ReflectionClass(AIConversationMessage::class))->newInstanceWithoutConstructor();
        $message->id = 900000 + $indexInConversation;
        $message->indexInConversation = $indexInConversation;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = $messageType;
        $message->signalSource = $signalSource;
        $message->message = $text;
        return $message;
    }

    /** The rows as a loaded set, in the order given (the readers must not depend on it). */
    protected static function buildMessages(AIConversationMessage ...$messageRows): AIConversationMessages
    {
        /** @var AIConversationMessages $messages */
        $messages = (new ReflectionClass(AIConversationMessages::class))->newInstanceWithoutConstructor();
        $messages->elements = $messageRows;
        return $messages;
    }

    /** A ```widget fence of `$widgetType` with `$widgetFields` after `type`. */
    protected static function buildWidgetFence(string $widgetType, array $widgetFields = []): string
    {
        return "```widget\n" . json_encode(['type' => $widgetType] + $widgetFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n```";
    }
}
