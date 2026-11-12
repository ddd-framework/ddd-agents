<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE validated distribution of a turn-start `choice` question picks under the rule shared by the skill preload
 * and the direct widget: the best non-`none` answer is the pick when its probability reaches the floor while `none`
 * stays at or below the ceiling. Built by {@see AgentSkillPreloadDecision::resolvePreloadFromDistribution()} and
 * {@see AgentDirectWidgetDecision::resolveFromDistribution()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionDistributionPick extends ValueObject
{
    /** @var bool True when the best non-`none` answer is the pick under the thresholds */
    public bool $isPicked = false;

    /** @var string|null Why nothing was picked (the deciding class's SKIP_* value); null when an answer was picked */
    public ?string $skipReason = null;

    /**
     * @var float|null The best non-`none` answer's margin over the second; null when the distribution was unusable or
     *      held no candidate, and always null for the direct widget (its rule does not read the margin)
     */
    public ?float $marginOverSecond = null;

    /**
     * @var string|null The best non-`none` answer — also when it was not picked; null when the distribution was
     *      unusable or held no candidate
     */
    public ?string $topToken = null;

    /**
     * @param bool $isPicked
     * @param string|null $skipReason
     * @param string|null $topToken
     * @param float|null $marginOverSecond
     * @return static
     */
    public static function forOutcome(bool $isPicked, ?string $skipReason, ?string $topToken, ?float $marginOverSecond = null): static
    {
        $distributionPick = new static();
        $distributionPick->isPicked = $isPicked;
        $distributionPick->skipReason = $skipReason;
        $distributionPick->topToken = $topToken;
        $distributionPick->marginOverSecond = $marginOverSecond;
        return $distributionPick;
    }
}
