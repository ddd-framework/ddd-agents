<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSheetRange;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Infrastructure\Services\DDDService;

/**
 * Executes the drive and native-document writes of capability FILES from their stored step arguments (`stepKind` plus
 * its fields): the one place such a write reaches an adapter, for a direct step and an approved request alike
 * ({@see AgentConnectionOperationsService::executeStep()}). Before it writes, the item is checked again against the roots
 * of the acting account's reach (a root may have been taken back meanwhile), and a Sheets range is read again and
 * compared with what the agent read (a difference refuses). Docs and Slides writes are locked on the read revision.
 */
class AgentConnectionDriveStepsService
{
    public const string STEP_EDIT_DOC = 'EDIT_DOC';
    public const string STEP_EDIT_SLIDES = 'EDIT_SLIDES';
    public const string STEP_UPDATE_SHEET = 'UPDATE_SHEET';
    public const string STEP_APPEND_SHEET = 'APPEND_SHEET';
    public const string STEP_COMMENT = 'COMMENT';
    public const string STEP_REPLY = 'REPLY';
    public const string STEP_RESOLVE = 'RESOLVE';
    public const string STEP_TRASH = 'TRASH';

    public const array STEPS = [
        self::STEP_EDIT_DOC, self::STEP_EDIT_SLIDES, self::STEP_UPDATE_SHEET, self::STEP_APPEND_SHEET,
        self::STEP_COMMENT, self::STEP_REPLY, self::STEP_RESOLVE, self::STEP_TRASH,
    ];

    public static function isDriveStep(AgentConnectionOperation $agentConnectionOperation): bool
    {
        return $agentConnectionOperation->capability === AgentConnectionPermission::CAPABILITY_FILES
            && in_array($agentConnectionOperation->stepArguments->stepKind, static::STEPS, true);
    }

    /** @throws AgentConnectionRefusedException */
    public function executeStep(AgentConnectionOperation $agentConnectionOperation): AgentConnectionSendResult
    {
        $stepArguments = $agentConnectionOperation->stepArguments;
        $stepKind = $stepArguments->stepKind;
        $itemId = (string)$stepArguments->itemId;
        $agentConnection = $agentConnectionOperation->agentConnection;
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $adapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$adapter instanceof DriveAgentConnectionAdapter || !$adapter instanceof GoogleDocumentsAgentConnectionAdapter) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" cannot do $stepKind.");
        }
        $this->requireStillInsideRoots($agentConnectionOperation, $adapter, $itemId);
        return match ($stepKind) {
            static::STEP_EDIT_DOC => $adapter->editGoogleDoc($this->buildEditRequests($stepArguments)),
            static::STEP_EDIT_SLIDES => $adapter->editSlides($this->buildEditRequests($stepArguments)),
            static::STEP_UPDATE_SHEET => $this->updateSheetRangeUnchangedSinceRead($adapter, $itemId, $stepArguments),
            static::STEP_APPEND_SHEET => $adapter->appendSheetRows($itemId, $this->buildSheetRange($stepArguments)),
            static::STEP_COMMENT => $adapter->createComment($itemId, (string)$stepArguments->commentContent, $stepArguments->quotedText),
            static::STEP_REPLY => $adapter->replyToComment($itemId, (string)$stepArguments->commentId, (string)$stepArguments->commentContent),
            static::STEP_RESOLVE => $adapter->resolveComment($itemId, (string)$stepArguments->commentId),
            static::STEP_TRASH => $adapter->trashDriveItem($itemId),
            default => throw new AgentConnectionRefusedException("Unknown drive step $stepKind."),
        };
    }

    protected function requireStillInsideRoots(AgentConnectionOperation $agentConnectionOperation, DriveAgentConnectionAdapter $driveAdapter, string $itemId): void
    {
        $agentConnection = $agentConnectionOperation->agentConnection;
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        $agentConnectionAccess = $agentConnectionsService->getAccessOfAccount($agentConnection, (int)$agentConnectionOperation->actingAccountId, $agentConnectionOperation->getReach(), AgentConnectionPermission::CAPABILITY_FILES);
        if ($agentConnectionAccess === null) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" is no longer released for this account.");
        }
        /** @var AgentConnectionDriveScopeService $agentConnectionDriveScopeService */
        $agentConnectionDriveScopeService = DDDService::instance()->getService(AgentConnectionDriveScopeService::class);
        $agentConnectionDriveScopeService->requireItemInsideRoots($driveAdapter, $agentConnection, $agentConnectionAccess, $itemId);
    }

    /** A Sheets write: the range is read again; when its typed values differ from what the agent read, the write is refused */
    protected function updateSheetRangeUnchangedSinceRead(GoogleDocumentsAgentConnectionAdapter $documentsAdapter, string $spreadsheetId, AgentConnectionStepArguments $stepArguments): AgentConnectionSendResult
    {
        $sheetRange = $this->buildSheetRange($stepArguments);
        $currentRange = $documentsAdapter->readSheetRange($spreadsheetId, $sheetRange->range);
        $readFingerprint = (string)$stepArguments->readFingerprint;
        if ($readFingerprint === '' || !hash_equals($readFingerprint, $currentRange->getFingerprint())) {
            throw new AgentConnectionRefusedException(sprintf('The range %s changed since it was read; read it again (readSheetRange) and show the change.', $sheetRange->range));
        }
        return $documentsAdapter->updateSheetRange($spreadsheetId, $sheetRange);
    }

    protected function buildEditRequests(AgentConnectionStepArguments $stepArguments): AgentConnectionDocumentEditRequests
    {
        $documentEditRequests = AgentConnectionDocumentEditRequests::newInstance();
        $documentEditRequests->documentId = (string)$stepArguments->itemId;
        $documentEditRequests->requiredRevisionId = (string)$stepArguments->requiredRevisionId;
        $documentEditRequests->providerRequestsJson = (string)$stepArguments->providerRequestsJson;
        if ($documentEditRequests->requiredRevisionId === '') {
            throw new AgentConnectionRefusedException('An edit needs the revision it was read at.');
        }
        return $documentEditRequests;
    }

    protected function buildSheetRange(AgentConnectionStepArguments $stepArguments): AgentConnectionSheetRange
    {
        $sheetRange = AgentConnectionSheetRange::newInstance();
        $sheetRange->range = (string)$stepArguments->range;
        $sheetRange->rows = array_values((array)json_decode((string)$stepArguments->rowsJson, true));
        return $sheetRange;
    }
}
