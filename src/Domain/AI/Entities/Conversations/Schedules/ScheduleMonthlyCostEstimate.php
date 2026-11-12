<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Schedules;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The ONE-schedule slice of {@see RecurringScheduleMonthlyCostEstimate}: how often THIS
 * recurring schedule fires per month and what those firings are projected to cost — captured per schedule inside
 * {@see \DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService::buildMonthlyCostEstimateForActiveRecurringSchedulesOfAgentSpace()}
 * (the same loop that sums the aggregate) so the customer sees WHICH schedule drives the monthly load. Carries
 * exact figures always — the fair-use nulling (hide USD/share from the customer) happens exclusively in the
 * host's customer response DTO.
 */
class ScheduleMonthlyCostEstimate extends ValueObject
{
    /** @var int The schedule this slice belongs to. */
    public int $scheduleId = 0;

    /** @var int The schedule's firings over the next 30 days (cron iterated, capped). */
    public int $firingsPerMonth = 0;

    /** @var float The projected monthly spend of those firings in USD (firings × per-run cost). */
    public float $estimatedMonthlyCostUsd = 0.0;

    /** @var float|null The schedule's own hard cost cap per firing in USD; null = the plan-adaptive default cap. */
    public ?float $perRunCostCapUsd = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->scheduleId);
    }
}
