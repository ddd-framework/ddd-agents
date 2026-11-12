<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The activity lines of one turn: sentences, ids and states; never a tool name, an argument or a result.
 * An element whose line is still null shows its state alone and gets its line with a later request.
 */
class AIConversationActivityGetResponseDto extends RestResponseDto
{
    /** @var int|null The message that started the narrated turn; the frontend pins it for its next requests. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public ?int $turnMessageId = null;

    /** @var bool Whether the narrated turn is still running; false = these are the last lines of the turn. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public bool $turnRunning = false;

    /** @var AgentActivityLines The elements of the turn in row order, one per tool call the customer is told about. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public AgentActivityLines $elements;
}
