<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionDocumentEdit;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionDocumentEditRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The item's id (a document, spreadsheet, presentation, file or folder) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $itemId = '';
    /** @var string The revisionId of your read (readGoogleDoc / readSlides); a changed document refuses the edit */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $revisionId = '';
    /** @var AgentConnectionDocumentEdit[] The edits against anchors of that read */
    #[Parameter(in: Parameter::BODY, required: true)]
    public array $edits = [];
}
