<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * A tool response whose note to the model DICTATES a widget block without a condition: on a screen the answer is one
 * short line and then exactly that block (the video widgets of a video search, a currency converter, a bookings list).
 * The type rides as a typed platform fact beside the result ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::$dictatedWidgetType}),
 * so the loop can tell that an answer without the block is incomplete without reading the note's wording
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentStallClassification}). A note that leaves the block to
 * the question ("only when the owner asked about …", "a single fact stays prose") dictates nothing here.
 */
interface MCPToolResponseDictatedWidgetTypeProvider
{
    /** The widget type the note dictates for this result, or null when it dictates none (no data, a phone call, a failure). */
    public function getDictatedWidgetType(): ?string;
}
