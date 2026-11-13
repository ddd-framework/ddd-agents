<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes

declare(strict_types=1);

namespace {{NAMESPACE}}\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The input of the write tool. A model that fills every parameter sends "" for one it does not need: on an optional
 * property (nullable or with a default) the executor reads "" and null as "not passed". A property whose empty string
 * is a value of its own (it clears a text) carries MCPEmptyStringIsValue.
 */
class {{FEATURE}}AddRequestDto extends RequestDto
{
    /** @var int|null The space to write to; omit it to write to the space of the conversation */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var string The item, in the owner's words (required) */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $text;
}
