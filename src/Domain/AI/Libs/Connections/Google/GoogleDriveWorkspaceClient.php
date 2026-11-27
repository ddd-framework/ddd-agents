<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Libs\Connections\Google;

use DDD\Domain\AI\Libs\Connections\CappedStreamReader;
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
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Libs\Connections\Documents\GoogleDocumentStructuredReader;
use DDD\Infrastructure\Base\DateTime\DateTime;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

/**
 * The Drive, Docs, Sheets and Slides REST calls of a drive connection, over the HTTP client the connection's token
 * authorizes (`Google\Client::authorize()`). Shared drives are included everywhere. A refusal of the provider (stale
 * revision, missing right) becomes an {@see AgentConnectionRefusedException} with its reason; other failures an
 * {@see AgentConnectionProviderException}.
 */
class GoogleDriveWorkspaceClient
{
    public const string DRIVE_URL = 'https://www.googleapis.com/drive/v3/';
    public const string UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/';
    public const string DOCS_URL = 'https://docs.googleapis.com/v1/documents/';
    public const string SHEETS_URL = 'https://sheets.googleapis.com/v4/spreadsheets/';
    public const string SLIDES_URL = 'https://slides.googleapis.com/v1/presentations/';

    public const string ITEM_FIELDS = 'id,name,mimeType,parents,size,md5Checksum,headRevisionId,version,modifiedTime,webViewLink,driveId,trashed,capabilities(canEdit,canComment,canShare,canTrash,canManageMembers)';

    /** @var array<string, string> the rendition of a native document when bytes are asked without a format */
    public const array DEFAULT_EXPORT_MIME_TYPES = [
        'application/vnd.google-apps.document' => 'application/pdf',
        'application/vnd.google-apps.spreadsheet' => 'application/pdf',
        'application/vnd.google-apps.presentation' => 'application/pdf',
    ];

    public function __construct(protected ClientInterface $httpClient)
    {
    }

    public function getDriveItem(string $itemId): AgentConnectionDriveItem
    {
        return static::toDriveItem($this->requestJson('GET', static::DRIVE_URL . 'files/' . rawurlencode($itemId), ['fields' => static::ITEM_FIELDS, 'supportsAllDrives' => 'true']));
    }

    public function listFolderChildren(string $folderId, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        return $this->listFiles("'" . static::escapeQueryValue($folderId) . "' in parents and trashed = false", $pageToken, $pageSize, null);
    }

    public function searchDriveItems(string $query, ?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        $escapedQuery = static::escapeQueryValue($query);
        return $this->listFiles("trashed = false and (name contains '$escapedQuery' or fullText contains '$escapedQuery')", $pageToken, $pageSize, null);
    }

    public function listSharedWithMe(?string $pageToken, int $pageSize): AgentConnectionDriveItems
    {
        return $this->listFiles('sharedWithMe = true and trashed = false', $pageToken, $pageSize, null);
    }

    public function getChangesStartToken(?string $sharedDriveId): string
    {
        $queryParameters = ['supportsAllDrives' => 'true'];
        if ($sharedDriveId !== null) {
            $queryParameters['driveId'] = $sharedDriveId;
        }
        return (string)($this->requestJson('GET', static::DRIVE_URL . 'changes/startPageToken', $queryParameters)['startPageToken'] ?? '');
    }

    public function listChanges(string $pageToken, ?string $sharedDriveId): AgentConnectionDriveChanges
    {
        $queryParameters = [
            'pageToken' => $pageToken, 'pageSize' => '500', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            'fields' => 'nextPageToken,newStartPageToken,changes(removed,fileId,file(' . static::ITEM_FIELDS . '))',
        ];
        if ($sharedDriveId !== null) {
            $queryParameters['driveId'] = $sharedDriveId;
        }
        $changesJson = $this->requestJson('GET', static::DRIVE_URL . 'changes', $queryParameters);
        $driveChanges = AgentConnectionDriveChanges::newInstance();
        foreach ((array)($changesJson['changes'] ?? []) as $changeJson) {
            if (!empty($changeJson['removed']) || !isset($changeJson['file']) || !empty($changeJson['file']['trashed'])) {
                $driveChanges->removedItemIds[] = (string)($changeJson['fileId'] ?? '');
                continue;
            }
            $addedElement = static::toDriveItem((array)$changeJson['file']);
            $driveChanges->changedItems->add($addedElement);
        }
        $driveChanges->nextPageToken = isset($changesJson['nextPageToken']) ? (string)$changesJson['nextPageToken'] : null;
        $driveChanges->newStartPageToken = isset($changesJson['newStartPageToken']) ? (string)$changesJson['newStartPageToken'] : null;
        return $driveChanges;
    }

    public function downloadDriveItem(string $itemId, ?string $exportMimeType, int $maximumBytes): AgentConnectionDriveDownload
    {
        $driveItem = $this->getDriveItem($itemId);
        if ($driveItem->isFolder()) {
            throw new AgentConnectionRefusedException('A folder has no content.');
        }
        $exportMimeType ??= static::DEFAULT_EXPORT_MIME_TYPES[$driveItem->mimeType] ?? null;
        if ($driveItem->kind === AgentConnectionDriveItem::KIND_GOOGLE_OTHER) {
            throw new AgentConnectionRefusedException("The native type {$driveItem->mimeType} has no rendition here.");
        }
        if (!$driveItem->isNativeDocument() && $driveItem->sizeBytes !== null && $driveItem->sizeBytes > $maximumBytes) {
            throw new AgentConnectionRefusedException(sprintf('"%s" has %d MB; at most %d MB are read.', $driveItem->name, (int)ceil($driveItem->sizeBytes / 1048576), intdiv($maximumBytes, 1048576)));
        }
        // streamed and read up to the limit: an export has no size beforehand, a file may be larger than it says
        $response = $driveItem->isNativeDocument()
            ? $this->request('GET', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/export', ['mimeType' => (string)$exportMimeType], streamBody: true)
            : $this->request('GET', static::DRIVE_URL . 'files/' . rawurlencode($itemId), ['alt' => 'media', 'supportsAllDrives' => 'true'], streamBody: true);
        $content = CappedStreamReader::read($response->getBody(), $maximumBytes);
        if ($content === null) {
            throw new AgentConnectionRefusedException(sprintf('"%s" is larger than %d MB.', $driveItem->name, intdiv($maximumBytes, 1048576)));
        }
        $driveDownload = AgentConnectionDriveDownload::newInstance();
        $driveDownload->itemId = $itemId;
        $driveDownload->revisionKey = $driveItem->getRevisionKey();
        $driveDownload->fileName = $driveItem->name;
        $driveDownload->contentType = $driveItem->isNativeDocument() ? (string)$exportMimeType : $driveItem->mimeType;
        $driveDownload->content = $content;
        return $driveDownload;
    }

    public function trashDriveItem(string $itemId): AgentConnectionSendResult
    {
        $this->requestJson('PATCH', static::DRIVE_URL . 'files/' . rawurlencode($itemId), ['supportsAllDrives' => 'true', 'fields' => 'id'], ['trashed' => true]);
        return static::sendResult($itemId);
    }

    public function shareDriveItem(string $itemId, string $emailAddress, string $role): AgentConnectionSendResult
    {
        if (!in_array($role, ['reader', 'commenter', 'writer', 'fileOrganizer'], true)) {
            throw new AgentConnectionRefusedException("The role $role is not granted here (never organizer).");
        }
        $permissionJson = $this->requestJson(
            'POST',
            static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/permissions',
            ['supportsAllDrives' => 'true', 'sendNotificationEmail' => 'false', 'fields' => 'id'],
            ['type' => 'user', 'role' => $role, 'emailAddress' => $emailAddress]
        );
        return static::sendResult((string)($permissionJson['id'] ?? ''));
    }

    public function unshareDriveItem(string $itemId, string $permissionId): AgentConnectionSendResult
    {
        $this->request('DELETE', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/permissions/' . rawurlencode($permissionId), ['supportsAllDrives' => 'true']);
        return static::sendResult($permissionId);
    }

    public function keepRevisionForever(string $itemId, string $revisionId): void
    {
        $this->requestJson('PATCH', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/revisions/' . rawurlencode($revisionId), ['fields' => 'id'], ['keepForever' => true]);
    }

    public function uploadNewRevision(string $itemId, string $content, string $contentType, string $expectedHeadRevisionId): AgentConnectionSendResult
    {
        $driveItem = $this->getDriveItem($itemId);
        if ($driveItem->getRevisionKey() !== $expectedHeadRevisionId) {
            throw new AgentConnectionRefusedException(sprintf('"%s" changed since it was read (revision %s, now %s); read it again.', $driveItem->name, $expectedHeadRevisionId, $driveItem->getRevisionKey()));
        }
        $uploadJson = $this->decode($this->request('PATCH', static::UPLOAD_URL . 'files/' . rawurlencode($itemId), ['uploadType' => 'media', 'supportsAllDrives' => 'true', 'fields' => 'id,headRevisionId'], null, $content, $contentType));
        return static::sendResult((string)($uploadJson['headRevisionId'] ?? $itemId));
    }

    public function readGoogleDoc(string $documentId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        return GoogleDocumentStructuredReader::readDocument($this->requestJson('GET', static::DOCS_URL . rawurlencode($documentId)), $maximumCharacters);
    }

    public function editGoogleDoc(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        $this->requestJson('POST', static::DOCS_URL . rawurlencode($documentEditRequests->documentId) . ':batchUpdate', [], [
            'requests' => (array)json_decode($documentEditRequests->providerRequestsJson, true),
            'writeControl' => ['requiredRevisionId' => $documentEditRequests->requiredRevisionId],
        ]);
        return static::sendResult($documentEditRequests->documentId);
    }

    public function describeSpreadsheet(string $spreadsheetId): AgentConnectionDocumentRead
    {
        $spreadsheetJson = $this->requestJson('GET', static::SHEETS_URL . rawurlencode($spreadsheetId), ['fields' => 'properties.title,sheets.properties,namedRanges']);
        $documentRead = AgentConnectionDocumentRead::newInstance();
        $documentRead->itemId = $spreadsheetId;
        $documentRead->kind = AgentConnectionDriveItem::KIND_GOOGLE_SHEET;
        $documentRead->title = (string)($spreadsheetJson['properties']['title'] ?? '');
        $documentRead->revisionId = $this->getDriveItem($spreadsheetId)->getRevisionKey();
        $lines = ['| Sheet | Rows | Columns |', '| --- | --- | --- |'];
        foreach ((array)($spreadsheetJson['sheets'] ?? []) as $sheetJson) {
            $sheetProperties = (array)($sheetJson['properties'] ?? []);
            $lines[] = sprintf('| %s | %d | %d |', str_replace('|', '\|', (string)($sheetProperties['title'] ?? '')), (int)($sheetProperties['gridProperties']['rowCount'] ?? 0), (int)($sheetProperties['gridProperties']['columnCount'] ?? 0));
        }
        foreach ((array)($spreadsheetJson['namedRanges'] ?? []) as $namedRangeJson) {
            $lines[] = 'Named range: ' . (string)($namedRangeJson['name'] ?? '');
        }
        $documentRead->markdown = implode("\n", $lines);
        return $documentRead;
    }

    public function readSheetRange(string $spreadsheetId, string $range): AgentConnectionSheetRange
    {
        $gridJson = $this->requestJson('GET', static::SHEETS_URL . rawurlencode($spreadsheetId), [
            'ranges' => $range, 'includeGridData' => 'true', 'fields' => 'sheets.data.rowData.values.userEnteredValue',
        ]);
        $sheetRange = AgentConnectionSheetRange::newInstance();
        $sheetRange->range = $range;
        foreach ((array)($gridJson['sheets'][0]['data'][0]['rowData'] ?? []) as $rowJson) {
            $row = [];
            foreach ((array)($rowJson['values'] ?? []) as $cellJson) {
                $row[] = static::cellValue((array)($cellJson['userEnteredValue'] ?? []));
            }
            $sheetRange->rows[] = $row;
        }
        return $sheetRange;
    }

    public function updateSheetRange(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        $this->requestJson('PUT', static::SHEETS_URL . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($sheetRange->range), ['valueInputOption' => 'USER_ENTERED'], [
            'range' => $sheetRange->range, 'majorDimension' => 'ROWS', 'values' => $sheetRange->rows,
        ]);
        return static::sendResult($spreadsheetId);
    }

    public function appendSheetRows(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult
    {
        $this->requestJson('POST', static::SHEETS_URL . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($sheetRange->range) . ':append', ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'], [
            'majorDimension' => 'ROWS', 'values' => $sheetRange->rows,
        ]);
        return static::sendResult($spreadsheetId);
    }

    public function readSlides(string $presentationId, int $maximumCharacters): AgentConnectionDocumentRead
    {
        return GoogleDocumentStructuredReader::readPresentation($this->requestJson('GET', static::SLIDES_URL . rawurlencode($presentationId)), $maximumCharacters);
    }

    public function editSlides(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult
    {
        $this->requestJson('POST', static::SLIDES_URL . rawurlencode($documentEditRequests->documentId) . ':batchUpdate', [], [
            'requests' => (array)json_decode($documentEditRequests->providerRequestsJson, true),
            'writeControl' => ['requiredRevisionId' => $documentEditRequests->requiredRevisionId],
        ]);
        return static::sendResult($documentEditRequests->documentId);
    }

    public function listComments(string $itemId, bool $includeResolved): AgentConnectionDriveComments
    {
        $commentsJson = $this->requestJson('GET', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/comments', [
            'pageSize' => '100',
            'fields' => 'comments(id,content,quotedFileContent,resolved,createdTime,author(displayName),replies(id,content,author(displayName)))',
        ]);
        $driveComments = AgentConnectionDriveComments::newInstance();
        foreach ((array)($commentsJson['comments'] ?? []) as $commentJson) {
            if (!$includeResolved && !empty($commentJson['resolved'])) {
                continue;
            }
            $driveComment = AgentConnectionDriveComment::newInstance();
            $driveComment->commentId = (string)($commentJson['id'] ?? '');
            $driveComment->authorName = (string)($commentJson['author']['displayName'] ?? '');
            $driveComment->content = (string)($commentJson['content'] ?? '');
            $driveComment->quotedText = isset($commentJson['quotedFileContent']['value']) ? (string)$commentJson['quotedFileContent']['value'] : null;
            $driveComment->isResolved = !empty($commentJson['resolved']);
            $driveComment->createdAt = isset($commentJson['createdTime']) ? DateTime::fromString((string)$commentJson['createdTime']) : null;
            foreach ((array)($commentJson['replies'] ?? []) as $replyJson) {
                $reply = AgentConnectionDriveComment::newInstance();
                $reply->commentId = (string)($replyJson['id'] ?? '');
                $reply->authorName = (string)($replyJson['author']['displayName'] ?? '');
                $reply->content = (string)($replyJson['content'] ?? '');
                $driveComment->replies->add($reply);
            }
            $driveComments->add($driveComment);
        }
        return $driveComments;
    }

    public function createComment(string $itemId, string $content, ?string $quotedText): AgentConnectionSendResult
    {
        $body = ['content' => $content];
        if ($quotedText !== null && $quotedText !== '') {
            $body['quotedFileContent'] = ['value' => $quotedText, 'mimeType' => 'text/plain'];
        }
        $commentJson = $this->requestJson('POST', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/comments', ['fields' => 'id'], $body);
        return static::sendResult((string)($commentJson['id'] ?? ''));
    }

    public function replyToComment(string $itemId, string $commentId, string $content): AgentConnectionSendResult
    {
        $replyJson = $this->requestJson('POST', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/comments/' . rawurlencode($commentId) . '/replies', ['fields' => 'id'], ['content' => $content]);
        return static::sendResult((string)($replyJson['id'] ?? ''));
    }

    public function resolveComment(string $itemId, string $commentId): AgentConnectionSendResult
    {
        $replyJson = $this->requestJson('POST', static::DRIVE_URL . 'files/' . rawurlencode($itemId) . '/comments/' . rawurlencode($commentId) . '/replies', ['fields' => 'id'], ['action' => 'resolve', 'content' => '']);
        return static::sendResult((string)($replyJson['id'] ?? $commentId));
    }

    protected function listFiles(string $query, ?string $pageToken, int $pageSize, ?string $sharedDriveId): AgentConnectionDriveItems
    {
        $queryParameters = [
            'q' => $query, 'pageSize' => (string)max(1, min(1000, $pageSize)), 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            'fields' => 'nextPageToken,files(' . static::ITEM_FIELDS . ')', 'orderBy' => 'folder,name',
        ];
        if ($pageToken !== null) {
            $queryParameters['pageToken'] = $pageToken;
        }
        if ($sharedDriveId !== null) {
            $queryParameters['driveId'] = $sharedDriveId;
            $queryParameters['corpora'] = 'drive';
        }
        $listJson = $this->requestJson('GET', static::DRIVE_URL . 'files', $queryParameters);
        $driveItems = AgentConnectionDriveItems::newInstance();
        foreach ((array)($listJson['files'] ?? []) as $fileJson) {
            $addedElement = static::toDriveItem((array)$fileJson);
            $driveItems->add($addedElement);
        }
        $driveItems->nextPageToken = isset($listJson['nextPageToken']) ? (string)$listJson['nextPageToken'] : null;
        return $driveItems;
    }

    /** @param array<string, mixed> $fileJson */
    public static function toDriveItem(array $fileJson): AgentConnectionDriveItem
    {
        $driveItem = AgentConnectionDriveItem::newInstance();
        $driveItem->itemId = (string)($fileJson['id'] ?? '');
        $driveItem->name = (string)($fileJson['name'] ?? '');
        $driveItem->mimeType = (string)($fileJson['mimeType'] ?? '');
        $driveItem->kind = AgentConnectionDriveItem::kindOfMimeType($driveItem->mimeType);
        $driveItem->parentIds = array_values(array_map('strval', (array)($fileJson['parents'] ?? [])));
        $driveItem->sizeBytes = isset($fileJson['size']) ? (int)$fileJson['size'] : null;
        $driveItem->md5Checksum = isset($fileJson['md5Checksum']) ? (string)$fileJson['md5Checksum'] : null;
        $driveItem->headRevisionId = isset($fileJson['headRevisionId']) ? (string)$fileJson['headRevisionId'] : null;
        $driveItem->version = isset($fileJson['version']) ? (string)$fileJson['version'] : null;
        $driveItem->modifiedAt = isset($fileJson['modifiedTime']) ? DateTime::fromString((string)$fileJson['modifiedTime']) : null;
        $driveItem->webViewLink = isset($fileJson['webViewLink']) ? (string)$fileJson['webViewLink'] : null;
        $driveItem->driveId = isset($fileJson['driveId']) ? (string)$fileJson['driveId'] : null;
        $driveItem->isTrashed = !empty($fileJson['trashed']);
        $capabilities = (array)($fileJson['capabilities'] ?? []);
        $driveItem->canEdit = !empty($capabilities['canEdit']);
        $driveItem->canComment = !empty($capabilities['canComment']);
        $driveItem->canShare = !empty($capabilities['canShare']);
        $driveItem->canTrash = !empty($capabilities['canTrash']);
        $driveItem->canManageMembers = !empty($capabilities['canManageMembers']);
        return $driveItem;
    }

    /** @param array<string, mixed> $userEnteredValue */
    protected static function cellValue(array $userEnteredValue): string|int|float|bool|null
    {
        if (array_key_exists('formulaValue', $userEnteredValue)) {
            return (string)$userEnteredValue['formulaValue'];
        }
        if (array_key_exists('numberValue', $userEnteredValue)) {
            return is_int($userEnteredValue['numberValue']) ? $userEnteredValue['numberValue'] : (float)$userEnteredValue['numberValue'];
        }
        if (array_key_exists('boolValue', $userEnteredValue)) {
            return (bool)$userEnteredValue['boolValue'];
        }
        return array_key_exists('stringValue', $userEnteredValue) ? (string)$userEnteredValue['stringValue'] : null;
    }

    protected static function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    protected static function sendResult(string $externalId): AgentConnectionSendResult
    {
        $sendResult = AgentConnectionSendResult::newInstance();
        $sendResult->externalId = $externalId;
        return $sendResult;
    }

    /**
     * @param array<string, string> $queryParameters
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $url, array $queryParameters = [], ?array $jsonBody = null): array
    {
        return $this->decode($this->request($method, $url, $queryParameters, $jsonBody));
    }

    /** @return array<string, mixed> */
    protected function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $queryParameters
     * @param array<string, mixed>|null $jsonBody
     * @throws AgentConnectionRefusedException
     * @throws AgentConnectionProviderException
     */
    protected function request(string $method, string $url, array $queryParameters = [], ?array $jsonBody = null, ?string $rawBody = null, ?string $rawContentType = null, bool $streamBody = false): ResponseInterface
    {
        $options = ['http_errors' => false, 'query' => $queryParameters, 'timeout' => 60, 'stream' => $streamBody];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }
        if ($rawBody !== null) {
            $options['body'] = $rawBody;
            $options['headers'] = ['Content-Type' => (string)$rawContentType];
        }
        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (GuzzleException $transportFailure) {
            throw new AgentConnectionProviderException('The drive provider did not answer: ' . $transportFailure->getMessage());
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode < 400) {
            return $response;
        }
        $errorJson = json_decode((string)$response->getBody(), true);
        $reason = is_array($errorJson) ? (string)($errorJson['error']['message'] ?? '') : '';
        if (in_array($statusCode, [400, 403, 404, 409, 412], true)) {
            throw new AgentConnectionRefusedException(sprintf('The drive provider refused (%d): %s', $statusCode, mb_substr($reason, 0, 300)));
        }
        throw new AgentConnectionProviderException(sprintf('The drive provider failed (%d): %s', $statusCode, mb_substr($reason, 0, 300)));
    }
}
