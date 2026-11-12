<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What ONE run's read of its created ids could only do partly: the tables whose read hit the limit and the tables
 * read without a scope column. The harness reads it from the run's stored created-id read
 * ({@see fromStoredReport()}); {@see AgentRecordedRunComparison::addCreatedIdReadReport()} reports it.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunCreatedIdReadReport extends ValueObject
{
    /** @var string[] The tables whose created-id read hit the limit */
    public array $cappedTables = [];

    /** @var AgentRecordedRunUnscopedTableRead[] The tables read without a scope column, in stored order */
    public array $unscopedTableReads = [];

    /**
     * @param string[] $cappedTables
     * @param AgentRecordedRunUnscopedTableRead[] $unscopedTableReads
     * @return static
     */
    public static function forCappedAndUnscopedTables(array $cappedTables, array $unscopedTableReads): static
    {
        $agentRecordedRunCreatedIdReadReport = new static();
        $agentRecordedRunCreatedIdReadReport->cappedTables = array_values($cappedTables);
        $agentRecordedRunCreatedIdReadReport->unscopedTableReads = array_values($unscopedTableReads);
        return $agentRecordedRunCreatedIdReadReport;
    }

    /**
     * @param array $storedReport The stored created-id read: `cappedTables` (table names) and `unscopedTables`
     *      (table → `{read, allocated}`); other keys are ignored, both keys are optional
     * @return static
     */
    public static function fromStoredReport(array $storedReport): static
    {
        $unscopedTableReads = [];
        foreach ((array)($storedReport['unscopedTables'] ?? []) as $unscopedTable => $storedReadCounts) {
            $unscopedTableReads[] = AgentRecordedRunUnscopedTableRead::forTable(
                (string)$unscopedTable,
                (int)($storedReadCounts['read'] ?? 0),
                (int)($storedReadCounts['allocated'] ?? 0)
            );
        }
        return static::forCappedAndUnscopedTables(array_map('strval', (array)($storedReport['cappedTables'] ?? [])), $unscopedTableReads);
    }
}
