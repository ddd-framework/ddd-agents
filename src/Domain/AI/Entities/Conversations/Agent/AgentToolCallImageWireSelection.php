<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which tool results' pictures ride ONE model call's wire under the picture cap, and which results were dropped. Built
 * by {@see AgentModelInput::selectToolCallImagesForWire()}; read by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService::resolveToolCallImages()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallImageWireSelection extends ValueObject
{
    /**
     * @var array<string, array<int, mixed>> tool_call_id ⇒ the kept entries of its pictures (bytes or placeholders, as
     *      the candidates carried them), in chronological order of the results
     */
    public array $imagesOnWireByToolCallId = [];

    /** @var string[] The tool_call_ids whose pictures do not fit under the cap, newest first */
    public array $droppedToolCallIds = [];

    /**
     * @param array<string, array<int, mixed>> $imagesOnWireByToolCallId
     * @param string[] $droppedToolCallIds
     * @return static
     */
    public static function forImagesOnWireAndDroppedToolCallIds(array $imagesOnWireByToolCallId, array $droppedToolCallIds): static
    {
        $agentToolCallImageWireSelection = new static();
        $agentToolCallImageWireSelection->imagesOnWireByToolCallId = $imagesOnWireByToolCallId;
        $agentToolCallImageWireSelection->droppedToolCallIds = $droppedToolCallIds;
        return $agentToolCallImageWireSelection;
    }
}
