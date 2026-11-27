<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Traits\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveChanges;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveComment;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveComments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveDownload;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSheetRange;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Libs\Connections\Documents\GoogleDocumentStructuredReader;
use DDD\Domain\AI\Libs\Connections\Google\GoogleDriveWorkspaceClient;

/**
 * The drive and native documents of the fake adapter. The fixture key `drive` holds:
 * - `items`: provider file JSON (`id`, `name`, `mimeType`, `parents`, `headRevisionId` or `version`, `capabilities`,
 *   plus `content` for bytes);
 * - `documents`: Docs/Slides JSON by id; `sheets`: by id, `ranges` => rows; `comments`: by item id;
 * - `changes`: the pages of the change feed by page token.
 * Every write is recorded (`FILES:<step>`) and bumps the item's revision, so a stale write is refused like the provider
 * would.
 */
trait FakeDriveAgentConnectionAdapterTrait
{
    /** @return array<string, mixed> */
    protected static function driveWorld(): array
    {
        return (array)(static::world()['drive'] ?? []);
    }

    /** @return array<string, mixed> */
    protected function requireDriveItemJson(string $itemId): array
    {
        foreach ((array)(static::driveWorld()['items'] ?? []) as $itemJson) {
            if ((string)($itemJson['id'] ?? '') === $itemId) {
                return (array)$itemJson;
            }
        }
        throw new AgentConnectionRefusedException("No drive item $itemId.");
    }

    protected function bumpDriveRevision(string $itemId): void
    {
        foreach ((array)(static::$world['drive']['items'] ?? []) as $itemIndex => $itemJson) {
            if ((string)($itemJson['id'] ?? '') !== $itemId) {
                continue;
            }
            $currentRevision = (string)($itemJson['headRevisionId'] ?? $itemJson['version'] ?? '0');
            $nextRevision = (string)((int)$currentRevision + 1);
            static::$world['drive']['items'][$itemIndex][isset($itemJson['headRevisionId']) ? 'headRevisionId' : 'version'] = $nextRevision;
            if (isset(static::$world['drive']['documents'][$itemId])) {
                static::$world['drive']['documents'][$itemId]['revisionId'] = $nextRevision;
            }
        }
    }

    protected function requireCurrentRevision(string $itemId, string $requiredRevisionId): void
    {
        $currentRevision = GoogleDriveWorkspaceClient::toDriveItem($this->requireDriveItemJson($itemId))->getRevisionKey();
        if ($currentRevision !== $requiredRevisionId) {
            throw new AgentConnectionRefusedException(sprintf('The drive provider refused (400): the document changed since revision %s (now %s).', $requiredRevisionId, $currentRevision));
        }
    }

    public function getDriveItem(string $itemId): AgentConnectionDriveItem
    {
        return GoogleDriveWorkspaceClient::toDriveItem($this->requireDriveItemJson($itemId));
    }

    public function listFolderChildren(string $folderId, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        $driveItems = AgentConnectionDriveItems::newInstance();
        foreach ((array)(static::driveWorld()['items'] ?? []) as $itemJson) {
            if (in_array($folderId, (array)($itemJson['parents'] ?? []), true) && empty($itemJson['trashed'])) {
                $driveItem = GoogleDriveWorkspaceClient::toDriveItem((array)$itemJson);
                $driveItems->add($driveItem);
            }
        }
        return $driveItems;
    }

    public function searchDriveItems(string $query, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        $driveItems = AgentConnectionDriveItems::newInstance();
        foreach ((array)(static::driveWorld()['items'] ?? []) as $itemJson) {
            $haystack = mb_strtolower((string)($itemJson['name'] ?? '') . ' ' . (string)($itemJson['content'] ?? ''));
            if (empty($itemJson['trashed']) && ($query === '' || str_contains($haystack, mb_strtolower($query)))) {
                $driveItem = GoogleDriveWorkspaceClient::toDriveItem((array)$itemJson);
                $driveItems->add($driveItem);
            }
        }
        return $driveItems;
    }

    public function listSharedWithMe(?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        $driveItems = AgentConnectionDriveItems::newInstance();
        foreach ((array)(static::driveWorld()['items'] ?? []) as $itemJson) {
            if (!empty($itemJson['sharedWithMe']) && empty($itemJson['trashed'])) {
                $driveItem = GoogleDriveWorkspaceClient::toDriveItem((array)$itemJson);
                $driveItems->add($driveItem);
            }
        }
        return $driveItems;
    }

    public function getChangesStartToken(?string $sharedDriveId): string
    {
        return (string)(static::driveWorld()['startPageToken'] ?? '1');
    }

    public function listChanges(string $pageToken, ?string $sharedDriveId): AgentConnectionDriveChanges
    {
        $driveChanges = AgentConnectionDriveChanges::newInstance();
        $pageJson = (array)(static::driveWorld()['changes'][$pageToken] ?? []);
        foreach ((array)($pageJson['changed'] ?? []) as $itemJson) {
            $driveItem = GoogleDriveWorkspaceClient::toDriveItem((array)$itemJson);
            $driveChanges->changedItems->add($driveItem);
        }
        $driveChanges->removedItemIds = array_values(array_map('strval', (array)($pageJson['removed'] ?? [])));
        $driveChanges->newStartPageToken = (string)($pageJson['newStartPageToken'] ?? $pageToken);
        return $driveChanges;
    }

    public function downloadDriveItem(string $itemId, ?string $exportMimeType, int $maximumBytes): AgentConnectionDriveDownload
    {
        $itemJson = $this->requireDriveItemJson($itemId);
        $driveItem = GoogleDriveWorkspaceClient::toDriveItem($itemJson);
        $content = (string)($itemJson['content'] ?? '');
        if (strlen($content) > $maximumBytes) {
            throw new AgentConnectionRefusedException(sprintf('"%s" is larger than %d MB.', $driveItem->name, intdiv($maximumBytes, 1048576)));
        }
        $driveDownload = AgentConnectionDriveDownload::newInstance();
        $driveDownload->itemId = $itemId;
        $driveDownload->revisionKey = $driveItem->getRevisionKey();
        $driveDownload->fileName = $driveItem->name;
        $driveDownload->contentType = $driveItem->isNativeDocument() ? (string)($exportMimeType ?? 'application/pdf') : $driveItem->mimeType;
        $driveDownload->content = $content;
        return $driveDownload;
    }

    public function trashDriveItem(string $itemId): AgentConnectionSendResult
    {
        $this->requireDriveItemJson($itemId);
        foreach ((array)(static::$world['drive']['items'] ?? []) as $itemIndex => $itemJson) {
            if ((string)($itemJson['id'] ?? '') === $itemId) {
                static::$world['drive']['items'][$itemIndex]['trashed'] = true;
            }
        }
        return $this->recordStep('FILES:TRASH', ['itemId' => $itemId], 'trash-');
    }

    public function shareDriveItem(string $itemId, string $emailAddress, string $role): AgentConnectionSendResult
    {
        $this->requireDriveItemJson($itemId);
        return $this->recordStep('FILES:SHARE', ['itemId' => $itemId, 'emailAddress' => $emailAddress, 'role' => $role], 'permission-');
    }

    public function unshareDriveItem(string $itemId, string $permissionId): AgentConnectionSendResult
    {
        return $this->recordStep('FILES:UNSHARE', ['itemId' => $itemId, 'permissionId' => $permissionId], 'unshare-');
    }

    public function keepRevisionForever(string $itemId, string $revisionId): void
    {
        $this->recordStep('FILES:KEEP_REVISION', ['itemId' => $itemId, 'revisionId' => $revisionId], 'keep-');
    }

    public function uploadNewRevision(string $itemId, string $content, string $contentType, string $expectedHeadRevisionId): AgentConnectionSendResult
    {
        $this->requireCurrentRevision($itemId, $expectedHeadRevisionId);
        foreach ((array)(static::$world['drive']['items'] ?? []) as $itemIndex => $itemJson) {
            if ((string)($itemJson['id'] ?? '') === $itemId) {
                static::$world['drive']['items'][$itemIndex]['content'] = $content;
            }
        }
        $this->bumpDriveRevision($itemId);
        return $this->recordStep('FILES:UPLOAD', ['itemId' => $itemId, 'sizeBytes' => strlen($content)], 'revision-');
    }

    public function readGoogleDoc(string $documentId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        $documentJson = (array)(static::driveWorld()['documents'][$documentId] ?? []);
        if ($documentJson === []) {
            throw new AgentConnectionRefusedException("No document $documentId.");
        }
        return GoogleDocumentStructuredReader::readDocument($documentJson, $maximumCharacters);
    }

    public function editGoogleDoc(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        $this->requireCurrentRevision($documentEditRequests->documentId, $documentEditRequests->requiredRevisionId);
        $this->bumpDriveRevision($documentEditRequests->documentId);
        return $this->recordStep('FILES:EDIT_DOC', ['documentId' => $documentEditRequests->documentId, 'requests' => (array)json_decode($documentEditRequests->providerRequestsJson, true)], 'edit-');
    }

    public function describeSpreadsheet(string $spreadsheetId): AgentConnectionDocumentRead
    {
        $documentRead = AgentConnectionDocumentRead::newInstance();
        $documentRead->itemId = $spreadsheetId;
        $documentRead->kind = AgentConnectionDriveItem::KIND_GOOGLE_SHEET;
        $documentRead->title = $this->getDriveItem($spreadsheetId)->name;
        $documentRead->revisionId = $this->getDriveItem($spreadsheetId)->getRevisionKey();
        $lines = ['| Range | Rows |', '| --- | --- |'];
        foreach ((array)(static::driveWorld()['sheets'][$spreadsheetId]['ranges'] ?? []) as $range => $rows) {
            $lines[] = sprintf('| %s | %d |', $range, count((array)$rows));
        }
        $documentRead->markdown = implode("\n", $lines);
        return $documentRead;
    }

    public function readSheetRange(string $spreadsheetId, string $range): AgentConnectionSheetRange
    {
        $sheetRange = AgentConnectionSheetRange::newInstance();
        $sheetRange->range = $range;
        $sheetRange->rows = array_values((array)(static::driveWorld()['sheets'][$spreadsheetId]['ranges'][$range] ?? []));
        return $sheetRange;
    }

    public function updateSheetRange(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        static::$world['drive']['sheets'][$spreadsheetId]['ranges'][$sheetRange->range] = $sheetRange->rows;
        $this->bumpDriveRevision($spreadsheetId);
        return $this->recordStep('FILES:UPDATE_SHEET', ['spreadsheetId' => $spreadsheetId, 'range' => $sheetRange->range, 'rows' => $sheetRange->rows], 'sheet-');
    }

    public function appendSheetRows(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        foreach ($sheetRange->rows as $row) {
            static::$world['drive']['sheets'][$spreadsheetId]['ranges'][$sheetRange->range][] = $row;
        }
        $this->bumpDriveRevision($spreadsheetId);
        return $this->recordStep('FILES:APPEND_SHEET', ['spreadsheetId' => $spreadsheetId, 'range' => $sheetRange->range, 'rows' => $sheetRange->rows], 'sheet-');
    }

    public function readSlides(string $presentationId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        $presentationJson = (array)(static::driveWorld()['documents'][$presentationId] ?? []);
        if ($presentationJson === []) {
            throw new AgentConnectionRefusedException("No presentation $presentationId.");
        }
        return GoogleDocumentStructuredReader::readPresentation($presentationJson, $maximumCharacters);
    }

    public function editSlides(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        $this->requireCurrentRevision($documentEditRequests->documentId, $documentEditRequests->requiredRevisionId);
        $this->bumpDriveRevision($documentEditRequests->documentId);
        return $this->recordStep('FILES:EDIT_SLIDES', ['presentationId' => $documentEditRequests->documentId, 'requests' => (array)json_decode($documentEditRequests->providerRequestsJson, true)], 'edit-');
    }

    public function listComments(string $itemId, bool $includeResolved): AgentConnectionDriveComments
    {
        $driveComments = AgentConnectionDriveComments::newInstance();
        foreach ((array)(static::driveWorld()['comments'][$itemId] ?? []) as $commentJson) {
            if (!$includeResolved && !empty($commentJson['resolved'])) {
                continue;
            }
            $driveComment = AgentConnectionDriveComment::newInstance();
            $driveComment->commentId = (string)($commentJson['id'] ?? '');
            $driveComment->authorName = (string)($commentJson['authorName'] ?? '');
            $driveComment->content = (string)($commentJson['content'] ?? '');
            $driveComment->quotedText = isset($commentJson['quotedText']) ? (string)$commentJson['quotedText'] : null;
            $driveComment->isResolved = !empty($commentJson['resolved']);
            $driveComments->add($driveComment);
        }
        return $driveComments;
    }

    public function createComment(string $itemId, string $content, ?string $quotedText): AgentConnectionSendResult
    {
        $sendResult = $this->recordStep('FILES:COMMENT', ['itemId' => $itemId, 'content' => $content, 'quotedText' => $quotedText], 'comment-');
        static::$world['drive']['comments'][$itemId][] = ['id' => $sendResult->externalId, 'content' => $content, 'quotedText' => $quotedText, 'authorName' => 'agent'];
        return $sendResult;
    }

    public function replyToComment(string $itemId, string $commentId, string $content): AgentConnectionSendResult
    {
        return $this->recordStep('FILES:REPLY', ['itemId' => $itemId, 'commentId' => $commentId, 'content' => $content], 'reply-');
    }

    public function resolveComment(string $itemId, string $commentId): AgentConnectionSendResult
    {
        foreach ((array)(static::$world['drive']['comments'][$itemId] ?? []) as $commentIndex => $commentJson) {
            if ((string)($commentJson['id'] ?? '') === $commentId) {
                static::$world['drive']['comments'][$itemId][$commentIndex]['resolved'] = true;
            }
        }
        return $this->recordStep('FILES:RESOLVE', ['itemId' => $itemId, 'commentId' => $commentId], 'resolve-');
    }
}
