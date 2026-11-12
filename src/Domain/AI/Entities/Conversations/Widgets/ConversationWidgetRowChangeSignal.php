<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessageWritePublication;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * The realtime tick after the one writer stored a widget row: handed to
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationMessagesService::publishAfterCommit()}, which runs it once the
 * row is visible to other processes, and tells the open chats to read the row. A value object instead of a closure.
 * Transient: never persisted, never serialized.
 *
 * @phpstan-consistent-constructor
 */
class ConversationWidgetRowChangeSignal extends ValueObject implements AIConversationMessageWritePublication
{
    /** The conversation the row was written into. */
    #[HideProperty]
    public ?AIConversation $conversation = null;

    /** The realtime service the writer publishes through (its test double included). */
    #[HideProperty]
    public ?AIConversationRealtimeSignalService $realtimeSignalService = null;

    public static function forConversation(AIConversation $conversation, AIConversationRealtimeSignalService $realtimeSignalService): static
    {
        $conversationWidgetRowChangeSignal = new static();
        $conversationWidgetRowChangeSignal->conversation = $conversation;
        $conversationWidgetRowChangeSignal->realtimeSignalService = $realtimeSignalService;
        return $conversationWidgetRowChangeSignal;
    }

    /** The conversation is RUNNING: its turn wrote the row. */
    public function publish(): void
    {
        if ($this->conversation !== null && $this->realtimeSignalService !== null) {
            $this->realtimeSignalService->publishChangeSignal($this->conversation, AIConversationRealtimeSignalService::STATUS_RUNNING);
        }
    }
}
