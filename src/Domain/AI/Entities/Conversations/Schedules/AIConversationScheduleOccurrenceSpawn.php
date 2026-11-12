<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Eligibility\AgentAutomationEligibility;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What happened to ONE claimed occurrence of a schedule: the run conversation it
 * spawned, or why it was skipped — the result of
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::spawnRunConversationForClaimedSchedule()}.
 * Returned per call, so the fire command names the exact skip cause without reading state off the shared service.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleOccurrenceSpawn extends ValueObject
{
    /** @var AIConversation|null The spawned run conversation; null when the occurrence was skipped. */
    public ?AIConversation $runConversation = null;

    /**
     * @var string|null WHY the occurrence was skipped — one of the
     * {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService} OCCURRENCE_SKIP_REASON_* constants;
     * null after a spawn.
     */
    public ?string $skipReason = null;

    /** @var AgentAutomationEligibility|null The eligibility picture behind a skip for a space or an account that is not eligible. */
    public ?AgentAutomationEligibility $skipEligibility = null;

    public static function spawned(AIConversation $runConversation): static
    {
        $occurrenceSpawn = new static();
        $occurrenceSpawn->runConversation = $runConversation;
        return $occurrenceSpawn;
    }

    public static function skipped(string $skipReason, ?AgentAutomationEligibility $skipEligibility = null): static
    {
        $occurrenceSpawn = new static();
        $occurrenceSpawn->skipReason = $skipReason;
        $occurrenceSpawn->skipEligibility = $skipEligibility;
        return $occurrenceSpawn;
    }
}
