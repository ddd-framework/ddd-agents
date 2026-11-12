<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the read of a KEYED task-plan tree found irregular: a duplicate key, a key that does not conform
 * to {@see TaskPlanItem::KEY_PATTERN}, a key under the wrong parent, an item without a key that got a fresh one. The code
 * never writes such a tree; the list guards hand-edited or partially written rows. Transient — built on every read,
 * never persisted. One line per finding, e.g. `"2.2" appears twice`, `"3.1" sits under "2"`.
 */
class TaskPlanKeyIntegrityViolations extends ValueObject
{
    /** @var string[] One line per finding, in the order the read met them. */
    public array $lines = [];

    public function addLine(string $line): void
    {
        $this->lines[] = $line;
    }

    public function hasViolations(): bool
    {
        return $this->lines !== [];
    }
}
