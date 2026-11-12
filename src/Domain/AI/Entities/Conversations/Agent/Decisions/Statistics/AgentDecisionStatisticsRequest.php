<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What {@see \DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService::buildStatistics()} reads: the site, the window, the filters.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionStatisticsRequest extends ValueObject
{
    /** @var string The decision site ({@see AgentDecisionModelSettings}::SITE_*). */
    public string $site = AgentDecisionModelSettings::SITE_SKILL_PRELOAD;

    /** @var string Window start, `Y-m-d H:i:s`. */
    public string $from;

    /** @var string Window end (exclusive), `Y-m-d H:i:s`. */
    public string $to;

    /** @var string Observation cutoff: a turn younger than it without a terminal row is censored. */
    public string $cutoff;

    /** @var string|null Only decisions of this mode (`shadow` / `on`). */
    public ?string $mode = null;

    /** @var bool True = eval traffic counts too. */
    public bool $includeEval = false;

    /** @var bool True = the accrual arithmetic from the observed rates. */
    public bool $withAccrualArithmetic = false;

    /** The request with the command's defaults: the last 7 days up to now, cutoff now − 30 minutes. */
    public static function forLastDays(int $days = 7): static
    {
        $request = new static();
        $request->from = (new DateTime())->modify("-{$days} days")->format('Y-m-d 00:00:00');
        $request->to = (new DateTime())->format('Y-m-d H:i:s');
        $request->cutoff = (new DateTime())->modify('-30 minutes')->format('Y-m-d H:i:s');
        return $request;
    }
}
