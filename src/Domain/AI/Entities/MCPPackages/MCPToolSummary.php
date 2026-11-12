<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * The coarse "what + when" line for an #[MCPTool] method — the cheap LEVEL-1 disclosure the model sees in the `load_tool`
 * catalog while the tool is in the `available` state.
 * The full input schema (LEVEL-2) loads only when the tool is loaded. Mirror of a skill's title+summary entry.
 *
 * Write it so the model can ROUTE on it alone: precisely what the tool does AND when / for what to reach for it — one or
 * two tight sentences, NOT a novel and NOT a re-statement of the parameter list. Distinct from the method's docblock
 * `description` (which documents the full call); this is the routing summary.
 *
 *   #[MCPTool, MCPToolSummary('Returns the bookings of a workshop for a period — the booked slots, their state and
 *    the free capacity. Use to answer "what is booked / when is a slot free" before changing a single booking.')]
 *   public function getBookings(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolSummary
{
    public function __construct(public string $summary)
    {
    }
}
