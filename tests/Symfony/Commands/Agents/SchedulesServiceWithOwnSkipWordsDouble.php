<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationScheduleOccurrenceSpawn;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The schedules service of an application that has its own word for the agent space: it redeclares the two skip
 * reasons of the space with values of its own. Answers the fire command from scripted rows; reads and writes nothing.
 */
class SchedulesServiceWithOwnSkipWordsDouble extends AIConversationSchedulesService
{
    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_NOT_ELIGIBLE = 'SHOP_NOT_ELIGIBLE';

    public const string OCCURRENCE_SKIP_REASON_AGENT_SPACE_MISSING = 'SHOP_MISSING';

    /** @var AIConversationSchedule[] The due schedules, by id */
    public array $dueSchedulesById = [];

    /** @var AIConversationScheduleOccurrenceSpawn[] What the spawn answers, by schedule id */
    public array $occurrenceSpawnsByScheduleId = [];

    /** @var string[] The status transitions the command asked for, as `<schedule id>:<status>` */
    public array $recordedStatusTransitions = [];

    /** @var int[] The schedules whose occurrence the command claimed, in order */
    public array $claimedScheduleIds = [];

    /** @var int[] The schedules the command asked to spawn a run for, in order */
    public array $spawnedScheduleIds = [];

    public function findDueScheduleIds(int $limit = self::DUE_SCHEDULES_BATCH_LIMIT): array
    {
        return array_slice(array_keys($this->dueSchedulesById), 0, $limit);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return $this->dueSchedulesById[(int)$entityId] ?? null;
    }

    public function claimFireOccurrence(AIConversationSchedule $schedule): bool
    {
        $this->claimedScheduleIds[] = (int)$schedule->id;
        return true;
    }

    public function spawnRunConversationForClaimedSchedule(AIConversationSchedule $schedule): AIConversationScheduleOccurrenceSpawn
    {
        $this->spawnedScheduleIds[] = (int)$schedule->id;
        return $this->occurrenceSpawnsByScheduleId[(int)$schedule->id];
    }

    public function transitionStatus(AIConversationSchedule $schedule, string $status): void
    {
        $this->recordedStatusTransitions[] = $schedule->id . ':' . $status;
    }
}
