<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionSheetWriteRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The item's id (a document, spreadsheet, presentation, file or folder) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $itemId = '';
    /** @var string A1 range with the sheet */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $range = '';
    /** @var string The rows as a JSON array of arrays (strings, numbers, booleans, formulas starting with =) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $rowsJson = '';
    /** @var string|null updateSheetRange: the readFingerprint of your readSheetRange of this range (a changed range refuses) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $readFingerprint = null;
}
