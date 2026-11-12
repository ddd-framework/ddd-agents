<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * The owner-facing name of what a WRITE tool saves, as the run report names it ("Bookings", "Working days") —
 * an English source string that runs through the translation layer
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentRunReport::ownerFacingTitleForWriteTool()}). A write without
 * it is named by the words of its tool name without the verb. Tools that save the same thing share the subject
 * (`addBookings` and `updateBookings` are both "Bookings").
 *
 *   #[MCPTool]
 *   #[MCPToolEffect(MCPToolEffect::WRITE)]
 *   #[MCPToolOwnerFacingSubject('Working days')]
 *   public function updateWorkingDays(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolOwnerFacingSubject
{
    public function __construct(public string $subject)
    {
    }
}
