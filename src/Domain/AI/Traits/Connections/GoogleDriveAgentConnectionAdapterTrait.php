<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Traits\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveChanges;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveComments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveDownload;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSheetRange;
use DDD\Domain\AI\Libs\Connections\Google\GoogleDriveWorkspaceClient;

/**
 * The drive and native-document side of the Google adapter ({@see \DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter},
 * {@see \DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter}): every call goes through
 * {@see GoogleDriveWorkspaceClient} on the HTTP client the connection's token authorizes. The using class provides
 * `getAuthorizedClient()` (a refreshed `Google\Client`).
 */
trait GoogleDriveAgentConnectionAdapterTrait
{
    protected ?GoogleDriveWorkspaceClient $driveWorkspaceClient = null;

    protected function getDriveWorkspaceClient(): GoogleDriveWorkspaceClient
    {
        return $this->driveWorkspaceClient ??= new GoogleDriveWorkspaceClient($this->getAuthorizedClient()->authorize());
    }

    public function getDriveItem(string $itemId): AgentConnectionDriveItem
    {
        return $this->getDriveWorkspaceClient()->getDriveItem($itemId);
    }

    public function listFolderChildren(string $folderId, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        return $this->getDriveWorkspaceClient()->listFolderChildren($folderId, $pageToken, $pageSize);
    }

    public function searchDriveItems(string $query, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        return $this->getDriveWorkspaceClient()->searchDriveItems($query, $pageToken, $pageSize);
    }

    public function listSharedWithMe(?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        return $this->getDriveWorkspaceClient()->listSharedWithMe($pageToken, $pageSize);
    }

    public function getChangesStartToken(?string $sharedDriveId): string
    {
        return $this->getDriveWorkspaceClient()->getChangesStartToken($sharedDriveId);
    }

    public function listChanges(string $pageToken, ?string $sharedDriveId): AgentConnectionDriveChanges
    {
        return $this->getDriveWorkspaceClient()->listChanges($pageToken, $sharedDriveId);
    }

    public function downloadDriveItem(string $itemId, ?string $exportMimeType, int $maximumBytes): AgentConnectionDriveDownload
    {
        return $this->getDriveWorkspaceClient()->downloadDriveItem($itemId, $exportMimeType, $maximumBytes);
    }

    public function trashDriveItem(string $itemId): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->trashDriveItem($itemId);
    }

    public function shareDriveItem(string $itemId, string $emailAddress, string $role): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->shareDriveItem($itemId, $emailAddress, $role);
    }

    public function unshareDriveItem(string $itemId, string $permissionId): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->unshareDriveItem($itemId, $permissionId);
    }

    public function keepRevisionForever(string $itemId, string $revisionId): void
    {
        $this->getDriveWorkspaceClient()->keepRevisionForever($itemId, $revisionId);
    }

    public function uploadNewRevision(string $itemId, string $content, string $contentType, string $expectedHeadRevisionId): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->uploadNewRevision($itemId, $content, $contentType, $expectedHeadRevisionId);
    }

    public function readGoogleDoc(string $documentId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        return $this->getDriveWorkspaceClient()->readGoogleDoc($documentId, $maximumCharacters);
    }

    public function editGoogleDoc(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->editGoogleDoc($documentEditRequests);
    }

    public function describeSpreadsheet(string $spreadsheetId): AgentConnectionDocumentRead
    {
        return $this->getDriveWorkspaceClient()->describeSpreadsheet($spreadsheetId);
    }

    public function readSheetRange(string $spreadsheetId, string $range): AgentConnectionSheetRange
    {
        return $this->getDriveWorkspaceClient()->readSheetRange($spreadsheetId, $range);
    }

    public function updateSheetRange(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->updateSheetRange($spreadsheetId, $sheetRange);
    }

    public function appendSheetRows(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->appendSheetRows($spreadsheetId, $sheetRange);
    }

    public function readSlides(string $presentationId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        return $this->getDriveWorkspaceClient()->readSlides($presentationId, $maximumCharacters);
    }

    public function editSlides(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->editSlides($documentEditRequests);
    }

    public function listComments(string $itemId, bool $includeResolved): AgentConnectionDriveComments
    {
        return $this->getDriveWorkspaceClient()->listComments($itemId, $includeResolved);
    }

    public function createComment(string $itemId, string $content, ?string $quotedText): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->createComment($itemId, $content, $quotedText);
    }

    public function replyToComment(string $itemId, string $commentId, string $content): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->replyToComment($itemId, $commentId, $content);
    }

    public function resolveComment(string $itemId, string $commentId): AgentConnectionSendResult
    {
        return $this->getDriveWorkspaceClient()->resolveComment($itemId, $commentId);
    }
}
