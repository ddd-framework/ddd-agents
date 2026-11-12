<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One row of an {@see AgentDecisionStatisticsSection} table: its cells in the section's column order.
 *
 * @phpstan-consistent-constructor
 */
class AgentDecisionStatisticsRow extends ValueObject
{
    /** @var string[] The cells, rendered ('' for no value). */
    public array $cells = [];

    /**
     * The row of a query result read by the section's columns.
     *
     * @param array<string, mixed> $values column => value
     * @param string[] $columns
     */
    public static function fromValues(array $values, array $columns): static
    {
        $row = new static();
        foreach ($columns as $column) {
            $value = $values[$column] ?? null;
            $row->cells[] = $value === null ? '' : (string)$value;
        }
        return $row;
    }
}
