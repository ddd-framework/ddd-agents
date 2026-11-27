<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEditRequests;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnectionStepArguments;
use DDD\Domain\AI\Services\Connections\Adapters\GoogleDocumentsAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveStepsService;

/** Opens the database-free parts of the drive steps (the sheet fingerprint check, the edit requests) to the tests */
class DriveTestStepsService extends AgentConnectionDriveStepsService
{
    public function updateSheetForTest(GoogleDocumentsAgentConnectionAdapter $documentsAdapter, string $spreadsheetId, AgentConnectionStepArguments $stepArguments): AgentConnectionSendResult
    {
        return $this->updateSheetRangeUnchangedSinceRead($documentsAdapter, $spreadsheetId, $stepArguments);
    }

    public function buildEditRequestsForTest(AgentConnectionStepArguments $stepArguments): AgentConnectionDocumentEditRequests
    {
        return $this->buildEditRequests($stepArguments);
    }
}
