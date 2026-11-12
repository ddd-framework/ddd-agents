<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Load the conversational context AROUND one message (the recall companion). After a search hit gives a
 * `messageId`, this expands the surrounding USER/ASSISTANT dialogue. Three independent token budgets (tokens ≈ chars/4):
 * the anchor ({@see $capContentTokens}) + how much dialogue before ({@see $capLeftTokens}) / after ({@see $capRightTokens}).
 * Access is rights-gated to the owner's own messages.
 */
class AIConversationMessageContextGetRequestDto extends RequestDto
{
    /** @var int The message to centre the context window on (from a search hit). */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $messageId;

    /** @var int|null Token cap for the anchor message itself (default applied server-side). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $capContentTokens = null;

    /** @var int|null Token budget for the dialogue BEFORE the anchor (default = capContentTokens; 0 = none). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $capLeftTokens = null;

    /** @var int|null Token budget for the dialogue AFTER the anchor (default = capContentTokens; 0 = none). */
    #[Parameter(in: Parameter::QUERY)]
    public ?int $capRightTokens = null;
}
