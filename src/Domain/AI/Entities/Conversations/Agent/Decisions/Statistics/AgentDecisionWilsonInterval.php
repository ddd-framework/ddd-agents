<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The two-sided 95 % Wilson interval of a share: the range the true share lies in, given how many of the counted
 * cases were successes. Used by the decision statistics for the precision of the shadow picks.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionWilsonInterval extends ValueObject
{
    /** The z value of a two-sided 95 % interval. */
    public const float Z_VALUE_OF_95_PERCENT = 1.96;

    /** @var float The lower bound of the share, 0.0 … 1.0 */
    public float $lowerBound = 0.0;

    /** @var float The upper bound of the share, 0.0 … 1.0 */
    public float $upperBound = 0.0;

    /**
     * @param int $successCount How many of the counted cases were successes
     * @param int $caseCount How many cases were counted
     * @return static The interval; 0.0 … 0.0 when no case was counted
     */
    public static function forSuccessesOfCases(int $successCount, int $caseCount): static
    {
        $wilsonInterval = new static();
        if ($caseCount <= 0) {
            return $wilsonInterval;
        }
        $zValue = static::Z_VALUE_OF_95_PERCENT;
        $observedShare = $successCount / $caseCount;
        $denominator = 1 + $zValue * $zValue / $caseCount;
        $centre = ($observedShare + $zValue * $zValue / (2 * $caseCount)) / $denominator;
        $halfWidth = $zValue * sqrt($observedShare * (1 - $observedShare) / $caseCount + $zValue * $zValue / (4 * $caseCount * $caseCount)) / $denominator;
        $wilsonInterval->lowerBound = max(0.0, $centre - $halfWidth);
        $wilsonInterval->upperBound = min(1.0, $centre + $halfWidth);
        return $wilsonInterval;
    }
}
