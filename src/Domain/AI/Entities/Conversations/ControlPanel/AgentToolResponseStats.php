<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\ControlPanel;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The agent control panel's tool-response monitoring set: one {@see AgentToolResponseStat} per tool with its
 * TOTALS over the selected date range, heaviest (by summed result chars) first — the ranking of which tools to
 * optimise. Built by `DBAIConversationMessage::findToolResponseStats()` from the `virtual*` tool-size columns.
 *
 * @property AgentToolResponseStat[] $elements
 * @method AgentToolResponseStat[] getElements()
 * @method AgentToolResponseStat|null first()
 * @method AgentToolResponseStat getByUniqueKey(string $uniqueKey)
 */
class AgentToolResponseStats extends ObjectSet
{
    /** @var string Rank by the average result length — systematically fat responses. */
    public const string MEASURE_AVG_RESULT_CHARS = 'avg';

    /** @var string Rank by the largest single result — outlier payloads. */
    public const string MEASURE_MAX_RESULT_CHARS = 'max';

    /** @var string Rank by the summed result length — the tool's total context weight. */
    public const string MEASURE_SUM_RESULT_CHARS = 'sum';

    /** @var string Rank by how often the tool was called. */
    public const string MEASURE_CALL_COUNT = 'calls';

    /** @var string[] Every ranking measure. */
    public const array MEASURES = [
        self::MEASURE_AVG_RESULT_CHARS,
        self::MEASURE_MAX_RESULT_CHARS,
        self::MEASURE_SUM_RESULT_CHARS,
        self::MEASURE_CALL_COUNT,
    ];

    /** Whether `$measure` is one of {@see self::MEASURES}. */
    public static function isKnownMeasure(string $measure): bool
    {
        return in_array($measure, self::MEASURES, true);
    }

    /**
     * The tools ranked by `$measure`, largest first — the ranking an application's debug console command prints and
     * its admin tools return. The set itself keeps its order. An unknown measure ranks
     * every tool at 0 (the given order); callers check {@see isKnownMeasure()} first.
     *
     * @return AgentToolResponseStat[]
     */
    public function getStatsSortedByMeasure(string $measure): array
    {
        $toolResponseStats = $this->getElements();
        usort(
            $toolResponseStats,
            static fn (AgentToolResponseStat $statA, AgentToolResponseStat $statB): int => ($statB->getMeasure($measure) ?? 0) <=> ($statA->getMeasure($measure) ?? 0)
        );
        return $toolResponseStats;
    }
}
