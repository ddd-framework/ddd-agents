<?php

declare(strict_types=1);

namespace AgentsReference\Presentation\Mcp\Demo\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The note the owner asked to keep. */
class DemoNoteAddRequestDto extends RequestDto
{
    /** @var string The note, in the owner's words */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $text;
}
