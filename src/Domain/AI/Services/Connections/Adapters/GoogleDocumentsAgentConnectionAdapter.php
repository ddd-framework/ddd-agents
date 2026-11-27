<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSheetRange;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveComments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;

/**
 * Native documents of the provider: structured reads with anchors and edits locked on the read revision. Docs and
 * Slides writes carry `writeControl.requiredRevisionId`; a Sheets write compares the range's typed values first. Every
 * write runs only through {@see \DDD\Domain\AI\Services\Connections\AgentConnectionOperationsService} (level, yes).
 */
interface GoogleDocumentsAgentConnectionAdapter
{
    public function readGoogleDoc(string $documentId, int $maximumCharacters): AgentConnectionDocumentRead;

    /** Docs batchUpdate, locked on `$documentEditRequests->requiredRevisionId` */
    public function editGoogleDoc(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult;

    /** Sheets of a spreadsheet with their sizes and named ranges, as markdown */
    public function describeSpreadsheet(string $spreadsheetId): AgentConnectionDocumentRead;

    /** The typed userEnteredValue grid (formulas included) */
    public function readSheetRange(string $spreadsheetId, string $range): AgentConnectionSheetRange;

    public function updateSheetRange(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult;

    public function appendSheetRows(string $spreadsheetId, AgentConnectionSheetRange $sheetRange): AgentConnectionSendResult;

    public function readSlides(string $presentationId, int $maximumCharacters): AgentConnectionDocumentRead;

    /** Slides batchUpdate (text and tables only), locked on the read revision */
    public function editSlides(AgentConnectionDocumentEditRequests $documentEditRequests): AgentConnectionSendResult;

    public function listComments(string $itemId, bool $includeResolved): AgentConnectionDriveComments;

    public function createComment(string $itemId, string $content, ?string $quotedText): AgentConnectionSendResult;

    public function replyToComment(string $itemId, string $commentId, string $content): AgentConnectionSendResult;

    public function resolveComment(string $itemId, string $commentId): AgentConnectionSendResult;
}
