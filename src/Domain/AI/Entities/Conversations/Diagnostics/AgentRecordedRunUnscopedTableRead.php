<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE table whose created ids a run read without a scope column: how many ids the read found and how many the table
 * allocated since the run's high-water mark. One element of {@see AgentRecordedRunCreatedIdReadReport::$unscopedTableReads}.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunUnscopedTableRead extends ValueObject
{
    /** @var string The table read unscoped */
    public string $table = '';

    /** @var int How many created ids the read found */
    public int $readIdCount = 0;

    /** @var int How many ids the table allocated since the high-water mark */
    public int $allocatedIdCount = 0;

    /**
     * @param string $table
     * @param int $readIdCount
     * @param int $allocatedIdCount
     * @return static
     */
    public static function forTable(string $table, int $readIdCount, int $allocatedIdCount): static
    {
        $agentRecordedRunUnscopedTableRead = new static();
        $agentRecordedRunUnscopedTableRead->table = $table;
        $agentRecordedRunUnscopedTableRead->readIdCount = $readIdCount;
        $agentRecordedRunUnscopedTableRead->allocatedIdCount = $allocatedIdCount;
        return $agentRecordedRunUnscopedTableRead;
    }
}
