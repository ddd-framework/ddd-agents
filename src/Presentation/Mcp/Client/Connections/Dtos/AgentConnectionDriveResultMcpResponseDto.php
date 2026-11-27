<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentRead;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveComments;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItem;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDriveItems;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSheetRange;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The answer of a drive or native-document tool */
class AgentConnectionDriveResultMcpResponseDto extends RestResponseDto
{
    /** @var string The connection used (its name) */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $connection = '';

    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $connectionId = 0;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionDriveItems $driveItems = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionDriveItem $driveItem = null;

    /** @var AgentConnectionDocumentRead|null The structured read: markdown with anchors and its revisionId */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionDocumentRead $documentRead = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionSheetRange $sheetRange = null;

    /** @var string|null readSheetRange: pass it to updateSheetRange (a range changed since refuses) */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $readFingerprint = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionDriveComments $comments = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentConnectionSendResult $sendResult = null;

    /** @var string|null What happened, for the model */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;
}
