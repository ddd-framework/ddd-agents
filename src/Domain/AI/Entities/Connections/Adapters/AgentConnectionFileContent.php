<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The text of one file: a Google document exported, a text file read; capped.
 */
class AgentConnectionFileContent extends ValueObject
{
    public string $fileId = '';
    public string $name = '';
    public string $mimeType = '';
    public string $text = '';
    public bool $isTruncated = false;
}
