<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFileContent;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionFiles;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/**
 * Capability FILES (a connected drive). The CloudDrive external space builds on this provider client; the scope boundaries of the connection (FOLDER:…) narrow
 * every call.
 */
interface FilesAgentConnectionAdapter
{
    /** @param string[] $allowedFolderIds empty = the whole drive */
    public function searchFiles(string $query, int $maximumResults, array $allowedFolderIds): AgentConnectionFiles;

    /** @param string[] $allowedFolderIds */
    public function readFile(string $fileId, int $maximumCharacters, array $allowedFolderIds): AgentConnectionFileContent;

    public function createTextFile(string $name, string $text, ?string $parentFolderId): AgentConnectionSendResult;
}
