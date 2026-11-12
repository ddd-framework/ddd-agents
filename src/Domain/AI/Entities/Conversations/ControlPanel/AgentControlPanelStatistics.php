<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\Date;

/**
 * The whole agent admin control panel payload — fleet-wide, read-only aggregations over the data the
 * agent already writes: AI usage (tokens + cost per time bucket, from the granular usage logs), conversation
 * volume, active accounts (an account counts as active when it SENT at least one user message — the agreed
 * definition), the per-tool response-size TOTALS (the `toLlmMarkdown` optimisation ranking), and the TOP
 * conversations of the range (costliest/longest — each linkable into the admin inspector). Satisfaction has NO
 * data substrate yet ({@see self::$satisfactionCaptureAvailable} stays false until the feedback field
 * exists) — the panel renders that section as an explicit empty state.
 *
 * Assembled by the admin controller from the owning services' finders; each sequence is chart-ready
 * ({@see DateValueSequence}).
 */
class AgentControlPanelStatistics extends ValueObject
{
    /** @var string Metric name: input tokens summed per bucket (fleet-wide, individual calls only). */
    public const string METRIC_INPUT_TOKENS = 'INPUT_TOKENS';

    /** @var string Metric name: output tokens summed per bucket (fleet-wide, individual calls only). */
    public const string METRIC_OUTPUT_TOKENS = 'OUTPUT_TOKENS';

    /** @var string Metric name: AI cost in USD summed per bucket (fleet-wide, individual calls only). */
    public const string METRIC_COST_IN_USD = 'COST_IN_USD';

    /** @var string Metric name: customer conversations STARTED per bucket (delegation children excluded). */
    public const string METRIC_CONVERSATIONS = 'CONVERSATIONS';

    /** @var string Metric name: distinct accounts that sent at least one user message in the bucket. */
    public const string METRIC_ACTIVE_ACCOUNTS = 'ACTIVE_ACCOUNTS';

    /** @var string Time-series bucket: one point per calendar day. */
    public const string GRANULARITY_DAY = 'DAY';

    /** @var string Time-series bucket: one point per ISO week, keyed by its Monday. Active accounts per week are a real COUNT(DISTINCT) over the week — never a sum of the daily counts. */
    public const string GRANULARITY_WEEK = 'WEEK';

    /** @var Date The first day of the aggregated range (inclusive). */
    public Date $rangeFrom;

    /** @var Date The last day of the aggregated range (inclusive). */
    public Date $rangeTo;

    /** @var string The time-series bucket granularity ({@see self::GRANULARITY_DAY} | {@see self::GRANULARITY_WEEK}). */
    public string $granularity = self::GRANULARITY_DAY;

    /** @var AgentAIUsagePerBucket Fleet-wide AI tokens + cost per bucket. */
    public AgentAIUsagePerBucket $aiUsagePerBucket;

    /** @var DateValueSequence Customer conversations started per bucket (delegation children excluded). */
    public DateValueSequence $conversationsPerBucket;

    /** @var DateValueSequence Distinct active accounts per bucket (sent at least one user message in it). */
    public DateValueSequence $activeAccountsPerBucket;

    /** @var int Distinct active accounts over the 7 days up to and including `rangeTo` (WAU). */
    public int $weeklyActiveAccounts = 0;

    /** @var int Distinct active accounts over the 30 days up to and including `rangeTo` (MAU). */
    public int $monthlyActiveAccounts = 0;

    /** @var AgentToolResponseStats Per-tool response-size TOTALS over the range, heaviest first — the "which tool to optimise" ranking. */
    public AgentToolResponseStats $toolResponseStats;

    /** @var AgentConversationStats The TOP conversations of the range (default: costliest first, limit 50) — each row links into the admin inspector. */
    public AgentConversationStats $topConversations;

    /**
     * @var bool False when the `virtual*` tool-size columns are not applied on this database yet — the tool section
     * then shows an explicit unavailable state instead of silently-empty data.
     */
    public bool $toolResponseDataAvailable = true;

    /** @var bool False until the satisfaction feedback capture (rating field + chat control) exists — the panel shows "no feedback captured yet". */
    public bool $satisfactionCaptureAvailable = false;

    public function __construct()
    {
        $this->aiUsagePerBucket = new AgentAIUsagePerBucket();
        $this->conversationsPerBucket = new DateValueSequence(self::METRIC_CONVERSATIONS);
        $this->activeAccountsPerBucket = new DateValueSequence(self::METRIC_ACTIVE_ACCOUNTS);
        $this->toolResponseStats = new AgentToolResponseStats();
        $this->topConversations = new AgentConversationStats();
        parent::__construct();
    }
}
