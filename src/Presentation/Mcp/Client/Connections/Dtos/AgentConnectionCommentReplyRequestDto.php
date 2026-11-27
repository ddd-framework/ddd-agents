<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Connections\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class AgentConnectionCommentReplyRequestDto extends RequestDto
{
    /** @var string|null The drive connection by its name or id (listConnections); omitted = the only or default one */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $connection = null;
    /** @var string The item's id (a document, spreadsheet, presentation, file or folder) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $itemId = '';
    /** @var string The comment (listGoogleComments) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $commentId = '';
    /** @var string The reply (empty for resolveGoogleComment) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public string $content = '';
}
