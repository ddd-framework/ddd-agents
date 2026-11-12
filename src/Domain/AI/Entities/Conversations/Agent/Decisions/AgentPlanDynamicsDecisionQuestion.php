<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the plan-dynamics site asks at one turn start: does this owner turn need a
 * working plan — the owner message, the thread before it and the plans already finished. Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentPlanDynamicsDecisionsService::buildRequestInput()}.
 */
class AgentPlanDynamicsDecisionQuestion extends ValueObject
{
    /** The site mode for this conversation (`on` applies, `shadow` records only). */
    public string $mode = AgentDecisionModelSettings::MODE_OFF;

    /** The index of the owner message that started this turn. */
    public int $initiatorMessageIndex = 0;

    public string $ownerMessage = '';

    /** The last assistant text before the owner message. */
    public string $assistantTextBefore = '';

    /** @var string[] The recent turns before the owner message, one line each. */
    public array $recentTurns = [];

    /** @var string[] The finished plans, newest first, one line each. */
    public array $finishedPlans = [];
}
