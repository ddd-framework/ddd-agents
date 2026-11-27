<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveChanges;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveDownload;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/**
 * Capability FILES as a drive behind the CloudDrive EXTERNAL space: the folder tree, the
 * change feed, bytes and renditions, trash and sharing. The adapter knows no roots: the roots are enforced by
 * {@see \DDD\Domain\AI\Services\Connections\AgentConnectionDriveScopeService} on every call, for every release way.
 * Every call uses the connection's own token.
 */
interface DriveAgentConnectionAdapter
{
    public function getDriveItem(string $itemId): AgentConnectionDriveItem;

    public function listFolderChildren(string $folderId, ?string $pageToken, int $pageSize): AgentConnectionDriveItems;

    /** Full-text and name search over the token's corpus; the caller filters by roots and pages on */
    public function searchDriveItems(string $query, ?string $pageToken, int $pageSize): AgentConnectionDriveItems;

    /** Items others shared with the token's identity directly (candidates of unassigned roots) */
    public function listSharedWithMe(?string $pageToken, int $pageSize): AgentConnectionDriveItems;

    public function getChangesStartToken(?string $sharedDriveId): string;

    public function listChanges(string $pageToken, ?string $sharedDriveId): AgentConnectionDriveChanges;

    /**
     * The bytes of a binary (download) or a rendition of a native document (`$exportMimeType`, e.g. application/pdf,
     * text/csv); at most `$maximumBytes`, refused above.
     */
    public function downloadDriveItem(string $itemId, ?string $exportMimeType, int $maximumBytes): AgentConnectionDriveDownload;

    /** Moves to the trash (never a hard delete) */
    public function trashDriveItem(string $itemId): AgentConnectionSendResult;

    /** Release to the agent's identity: shares an item with an address at a role (reader, commenter, writer, fileOrganizer) */
    public function shareDriveItem(string $itemId, string $emailAddress, string $role): AgentConnectionSendResult;

    /** Takes a share back (the release is revoked) */
    public function unshareDriveItem(string $itemId, string $permissionId): AgentConnectionSendResult;

    /** Pins a binary revision (`keepForever`, max 200 per file) for our snapshots */
    public function keepRevisionForever(string $itemId, string $revisionId): void;

    /** Uploads a new revision of a binary file, refused when the head revision is not `$expectedHeadRevisionId` */
    public function uploadNewRevision(string $itemId, string $content, string $contentType, string $expectedHeadRevisionId): AgentConnectionSendResult;
}
