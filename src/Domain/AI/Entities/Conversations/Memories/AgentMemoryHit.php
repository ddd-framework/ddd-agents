<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One stored memory a similarity search found: its id, its type, its text and its change times (the search hit the
 * agent's recall reads carries all of them). Built by the memory component.
 */
class AgentMemoryHit extends ValueObject
{
    /** @var int|null The memory row's id; null for a memory without a row. */
    public ?int $memoryId = null;

    /** @var string|null The memory type (opaque, the memory component's vocabulary). */
    public ?string $memoryType = null;

    /** @var string The memory text. */
    public string $content = '';

    /** @var DateTime|null When the memory was created. */
    public ?DateTime $createdTime = null;

    /** @var DateTime|null When the memory was last changed. */
    public ?DateTime $modifiedTime = null;
}
