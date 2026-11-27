<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A range of a spreadsheet with its typed values as the user entered them (formulas included): rows of cells, each a
 * string, number, bool or null. Read before a write and compared with what the agent read (a difference refuses).
 */
class AgentConnectionSheetRange extends ValueObject
{
    public string $range = '';
    /** @var array<int, array<int, string|int|float|bool|null>> */
    public array $rows = [];

    public function getFingerprint(): string
    {
        return hash('sha256', (string)json_encode($this->rows));
    }
}
