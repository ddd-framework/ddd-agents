<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The images of one tool call that are sent to the model with the call's result: the `tool_call_id` and the image
 * bytes selected for it. Never stored.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolResultImagesOfToolCall extends ValueObject
{
    /** @var string The `tool_call_id` of the call whose result carries the images. */
    public string $toolCallId;

    /** @var ToolResultImage[] The images sent with the call's result, in the order the tool returned them. */
    public array $toolResultImages = [];

    /**
     * @param ToolResultImage[] $toolResultImages
     */
    public static function forToolCall(string $toolCallId, array $toolResultImages): static
    {
        $toolResultImagesOfToolCall = new static();
        $toolResultImagesOfToolCall->toolCallId = $toolCallId;
        $toolResultImagesOfToolCall->toolResultImages = array_values($toolResultImages);
        return $toolResultImagesOfToolCall;
    }

    /** The key of a tool call's images: its `tool_call_id`. */
    public static function uniqueKeyOfToolCall(string $toolCallId): string
    {
        return self::class . '_' . $toolCallId;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyOfToolCall($this->toolCallId);
    }
}
