<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The images sent to the model with the tool results of one model call, one element per tool call that carries
 * images, in the order the calls were selected (newest first). Never stored.
 *
 * @property AgentToolResultImagesOfToolCall[] $elements
 * @method AgentToolResultImagesOfToolCall[] getElements()
 * @method AgentToolResultImagesOfToolCall|null first()
 */
class AgentToolResultImagesOfToolCalls extends ObjectSet
{
    /**
     * Adds the images of a tool call; a call the set already holds keeps its images.
     *
     * @param ToolResultImage[] $toolResultImages
     */
    public function addImagesOfToolCall(string $toolCallId, array $toolResultImages): void
    {
        $toolResultImagesOfToolCall = AgentToolResultImagesOfToolCall::forToolCall($toolCallId, $toolResultImages);
        $this->add($toolResultImagesOfToolCall);
    }

    /** @return ToolResultImage[] The images of a tool call; empty when the call carries none. */
    public function getImagesOfToolCall(string $toolCallId): array
    {
        $toolResultImagesOfToolCall = $this->getByUniqueKey(AgentToolResultImagesOfToolCall::uniqueKeyOfToolCall($toolCallId));
        return $toolResultImagesOfToolCall instanceof AgentToolResultImagesOfToolCall ? $toolResultImagesOfToolCall->toolResultImages : [];
    }

    /** @return string[] The `tool_call_id`s that carry images, in the set's order. */
    public function getToolCallIds(): array
    {
        $toolCallIds = [];
        foreach ($this->getElements() as $toolResultImagesOfToolCall) {
            $toolCallIds[] = $toolResultImagesOfToolCall->toolCallId;
        }
        return $toolCallIds;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
