<?php
// placeholder {{NAMESPACE}} sample: AgentsReference\Presentation\Mcp\Demo
// placeholder {{FEATURE}} sample: DemoNotes

declare(strict_types=1);

namespace {{NAMESPACE}}\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The input of the read tool. Every public property becomes a schema property; its docblock is the description the
 * model reads. `agentSpaceId` is the package's argument that names the space: in a conversation with a space the
 * executor fills it when the model leaves it out, and the tool schema marks it optional.
 */
class {{FEATURE}}GetRequestDto extends RequestDto
{
    /** @var int|null The space to read; omit it to read the space of the conversation */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var int|null At most this many items, newest last (default: all) */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $limit = null;
}
