<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One file of a connected drive (metadata).
 */
class AgentConnectionFile extends ValueObject
{
    public string $fileId = '';
    public string $name = '';
    public string $mimeType = '';
    public ?DateTime $modifiedAt = null;
    public ?int $sizeBytes = null;
    /** @var string[] parent folder ids */
    public array $parentIds = [];
    public ?string $ownerAddress = null;
}
