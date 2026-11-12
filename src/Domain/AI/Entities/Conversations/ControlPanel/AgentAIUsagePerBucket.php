<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The fleet-wide AI usage trend of the agent control panel: tokens + cost per time bucket (calendar day,
 * or ISO week keyed by its Monday — the statistics' granularity) across ALL accounts, as three chart-ready
 * {@see DateValueSequence}s over the same bucket axis. Built by `DBAIUsageLog::findFleetUsagePerBucket()` from the
 * granular usage logs (individual calls only — envelopes excluded so cost is never double-counted).
 */
class AgentAIUsagePerBucket extends ValueObject
{
    /** @var DateValueSequence Input tokens summed per bucket. */
    public DateValueSequence $inputTokensPerBucket;

    /** @var DateValueSequence Output tokens summed per bucket. */
    public DateValueSequence $outputTokensPerBucket;

    /** @var DateValueSequence Cost in USD summed per bucket. */
    public DateValueSequence $costInUSDPerBucket;

    public function __construct()
    {
        $this->inputTokensPerBucket = new DateValueSequence(AgentControlPanelStatistics::METRIC_INPUT_TOKENS);
        $this->outputTokensPerBucket = new DateValueSequence(AgentControlPanelStatistics::METRIC_OUTPUT_TOKENS);
        $this->costInUSDPerBucket = new DateValueSequence(AgentControlPanelStatistics::METRIC_COST_IN_USD);
        parent::__construct();
    }
}
