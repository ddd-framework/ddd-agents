<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The effort a turn of a conversation is sent with when nothing turn-specific raises it, with the declaration that set
 * it — the admin panel's baseline the per-turn effort chips compare against
 * ({@see AgentReasoningEffortLimits::buildBaseline()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentReasoningEffortBaseline extends ValueObject
{
    /** @var string|null The baseline effort; null = no effort is sent and the model has none configured (the provider's default). */
    public ?string $effort = null;

    /** @var string What set {@see $effort}: an {@see AgentReasoningEffortDecision}::FLOOR_SOURCE_*. */
    public string $source = AgentReasoningEffortDecision::FLOOR_SOURCE_CONFIGURED;

    public static function create(?string $effort, string $source): static
    {
        $agentReasoningEffortBaseline = new static();
        $agentReasoningEffortBaseline->effort = $effort;
        $agentReasoningEffortBaseline->source = $source;
        return $agentReasoningEffortBaseline;
    }
}
