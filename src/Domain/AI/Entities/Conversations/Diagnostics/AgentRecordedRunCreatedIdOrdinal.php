<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where ONE id a run created stands among that run's new rows: its table and its 1-based creation ordinal in that
 * table. {@see AgentRecordedRunPlayedAnswerIdTranslation} keeps one per recorded created id and looks up the replay's
 * id of the same table and ordinal.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunCreatedIdOrdinal extends ValueObject
{
    /** @var string The table that created the id */
    public string $table = '';

    /** @var int The id's 1-based position among the table's created ids, ascending */
    public int $ordinal = 0;

    /**
     * @param string $table
     * @param int $ordinal
     * @return static
     */
    public static function forTableAndOrdinal(string $table, int $ordinal): static
    {
        $agentRecordedRunCreatedIdOrdinal = new static();
        $agentRecordedRunCreatedIdOrdinal->table = $table;
        $agentRecordedRunCreatedIdOrdinal->ordinal = $ordinal;
        return $agentRecordedRunCreatedIdOrdinal;
    }

    /**
     * @param array<string, int[]> $createdIdsByTable Another run's created ids per table, ascending
     * @return int|null The id that run created in the same table at the same ordinal; null when it created none there
     */
    public function getCounterpartIdIn(array $createdIdsByTable): ?int
    {
        return $createdIdsByTable[$this->table][$this->ordinal - 1] ?? null;
    }
}
