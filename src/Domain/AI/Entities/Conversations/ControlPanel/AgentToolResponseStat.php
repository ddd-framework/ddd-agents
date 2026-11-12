<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE tool's response-size TOTALS over the control panel's selected date range: how often it was called
 * and how many characters its results carried — the "which tool must be optimised" view (`toLlmMarkdown` /
 * payload-reduction lever). Aggregated from the `virtual*` tool-size columns on the agent message table; element of
 * {@see AgentToolResponseStats}, heaviest total first.
 */
class AgentToolResponseStat extends ValueObject
{
    /** @var string The tool name (the `toolCall.name` the agent called). */
    public string $toolName;

    /** @var int How many times the tool was called in the range. */
    public int $callCount;

    /** @var int Sum of the tool results' character lengths in the range — the tool's total context weight. */
    public int $sumResultChars;

    /** @var int Average tool-result character length in the range. */
    public int $avgResultChars;

    /** @var int Largest single tool result in the range, in characters. */
    public int $maxResultChars;

    /**
     * The value of one ranking measure ({@see AgentToolResponseStats::MEASURES}): Ø result chars, the largest result,
     * the summed result chars or the call count; null for an unknown measure.
     */
    public function getMeasure(string $measure): ?int
    {
        return match ($measure) {
            AgentToolResponseStats::MEASURE_AVG_RESULT_CHARS => $this->avgResultChars,
            AgentToolResponseStats::MEASURE_MAX_RESULT_CHARS => $this->maxResultChars,
            AgentToolResponseStats::MEASURE_SUM_RESULT_CHARS => $this->sumResultChars,
            AgentToolResponseStats::MEASURE_CALL_COUNT => $this->callCount,
            default => null,
        };
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->toolName ?? '');
    }
}
