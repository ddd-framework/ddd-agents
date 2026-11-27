<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The bytes of one drive item at one revision (a download of a binary, an export or rendition of a native document).
 * In memory only: the CloudDrive intake stores them in our object store.
 */
class AgentConnectionDriveDownload extends ValueObject
{
    public string $itemId = '';
    public string $revisionKey = '';
    public string $fileName = '';
    public string $contentType = '';
    public string $content = '';

    public function getSha256(): string
    {
        return hash('sha256', $this->content);
    }
}
