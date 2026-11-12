<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** Input of the probe tool: a note, and the space it is recorded for as an argument of the call. */
class ProbeNoteRequestDto extends RequestDto
{
    /** @var int|null The space the note is recorded for. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?int $agentSpaceId = null;

    /** @var string|null The note. */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $note = null;
}
