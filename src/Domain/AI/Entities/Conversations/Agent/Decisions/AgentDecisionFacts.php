<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Facts about the space an application contributes to a decision question (e.g. whether a booking exists), by
 * fact name. A fact the application cannot resolve is absent — every question tolerates a missing fact. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentDecisionFactsService}.
 */
class AgentDecisionFacts extends ValueObject
{
    /** @var array<string, bool|int|float|string> The resolved facts, keyed by fact name. */
    public array $valuesByFactName = [];
}
