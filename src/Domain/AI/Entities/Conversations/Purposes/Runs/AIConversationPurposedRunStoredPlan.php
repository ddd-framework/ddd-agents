<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Purposes\Runs;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the purposed-runs read takes from ONE run's stored working state: its task plan as JSON text and its
 * environment — two keys of the working state, never the whole column. Built from one row of
 * {@see \DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposedRunsReadService}.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationPurposedRunStoredPlan extends ValueObject
{
    /** @var int The conversation of the run */
    public int $conversationId = 0;

    /**
     * @var string|null The stored `taskPlan` as JSON text; null for a run without one (a stored JSON null reads as
     *      the text `null` on MariaDB and as NULL on PostgreSQL — the reader of the plan treats both as none)
     */
    public ?string $taskPlanJson = null;

    /** @var string|null The stored `environment`; null for a run without one */
    public ?string $environment = null;

    /**
     * @param array $storedPlanRow One row of the read (`id`, `taskPlanJson`, `environment`)
     * @return static
     */
    public static function fromStoredPlanRow(array $storedPlanRow): static
    {
        $purposedRunStoredPlan = new static();
        $purposedRunStoredPlan->conversationId = (int)$storedPlanRow['id'];
        $purposedRunStoredPlan->taskPlanJson = $storedPlanRow['taskPlanJson'] ?? null;
        $storedEnvironment = $storedPlanRow['environment'] ?? null;
        $purposedRunStoredPlan->environment = $storedEnvironment === 'null' ? null : $storedEnvironment;
        return $purposedRunStoredPlan;
    }
}
