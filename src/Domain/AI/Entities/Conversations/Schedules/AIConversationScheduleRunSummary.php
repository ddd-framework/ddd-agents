<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE run of a schedule as its run history lists it: the run's conversation, when it started, its run state, the
 * title and outcome of its stored summary, and what the run cost. Built from one row of the run history read
 * ({@see \DDD\Domain\AI\Services\Conversations\AIConversationsService::findScheduleRunSummaries()}); no conversation
 * is loaded for it.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationScheduleRunSummary extends ValueObject
{
    /** @var int The conversation of the run */
    public int $conversationId = 0;

    /** @var string|null When the run's conversation was created, as the database returns it */
    public ?string $created = null;

    /** @var string The run state of the run's conversation */
    public string $runState = '';

    /** @var string|null The title of the run's stored summary; null while the run has none */
    public ?string $title = null;

    /** @var string|null The outcome of the run's stored summary; null while the run has none */
    public ?string $outcome = null;

    /**
     * @var float What the run cost in USD, rounded to 4 decimals: its messages, their tool calls and what the run
     *      booked outside both
     */
    public float $costInUSD = 0.0;

    /**
     * @param array $runSummaryRow One row of the run history read (`conversationId`, `created`, `runState`, `title`,
     *        `outcome`, `costInUSD`)
     * @return static
     */
    public static function fromRunSummaryRow(array $runSummaryRow): static
    {
        $scheduleRunSummary = new static();
        $scheduleRunSummary->conversationId = (int)$runSummaryRow['conversationId'];
        $scheduleRunSummary->created = isset($runSummaryRow['created']) ? (string)$runSummaryRow['created'] : null;
        $scheduleRunSummary->runState = (string)$runSummaryRow['runState'];
        $scheduleRunSummary->title = isset($runSummaryRow['title']) ? (string)$runSummaryRow['title'] : null;
        $scheduleRunSummary->outcome = isset($runSummaryRow['outcome']) ? (string)$runSummaryRow['outcome'] : null;
        $scheduleRunSummary->costInUSD = round((float)$runSummaryRow['costInUSD'], 4);
        return $scheduleRunSummary;
    }
}
