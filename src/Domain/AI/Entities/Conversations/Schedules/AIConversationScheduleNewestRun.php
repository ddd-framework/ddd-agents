<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The NEWEST run of one schedule, as a list of schedules shows it in one line: the run's conversation, its run state
 * and the first characters of its outcome. Built from one row of the read over a set of schedules
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findNewestScheduleRunSummaryPerSchedule()}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleNewestRun extends ValueObject
{
    /** @var int How many characters of the outcome the read returns */
    public const int OUTCOME_EXCERPT_CHARACTERS = 120;

    /** @var int The schedule this is the newest run of */
    public int $scheduleId = 0;

    /** @var int The conversation of the run */
    public int $conversationId = 0;

    /** @var string|null The run state of the run's conversation */
    public ?string $runState = null;

    /**
     * @var string|null The first {@see OUTCOME_EXCERPT_CHARACTERS} characters of the outcome of the run's stored
     *      summary; null while the run has none
     */
    public ?string $outcomeExcerpt = null;

    /**
     * @param array $newestRunRow One row of the read (`scheduleId`, `conversationId`, `runState`, `outcome`)
     * @return static
     */
    public static function fromNewestRunRow(array $newestRunRow): static
    {
        $scheduleNewestRun = new static();
        $scheduleNewestRun->scheduleId = (int)$newestRunRow['scheduleId'];
        $scheduleNewestRun->conversationId = (int)$newestRunRow['conversationId'];
        $scheduleNewestRun->runState = $newestRunRow['runState'] !== null ? (string)$newestRunRow['runState'] : null;
        $scheduleNewestRun->outcomeExcerpt = $newestRunRow['outcome'] !== null ? (string)$newestRunRow['outcome'] : null;
        return $scheduleNewestRun;
    }
}
