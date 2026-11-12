<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Push a frontend-widget SIGNAL into an existing AIConversation — the general way from a widget to a signal. A widget
 * sends a human-readable description of what the customer did (e.g. clicking an image option), and the agent acts on it
 * next turn. Model-visible, user-invisible.
 */
class AIConversationInjectSignalPostRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation to inject the signal into. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /**
     * @var string The human-readable signal the agent should act on (e.g. "User selected image B (fileId abc123)").
     * Sanitized + length-capped server-side.
     */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $signalText;

    /**
     * @var string|null The signal provenance. Defaults server-side to
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK}
     * when omitted; the OwnerActionConfirm widget passes OWNER_ACTION_CONFIRM, the widget failover
     * FRONTEND_WIDGET_INVALID, the open/return transitions of a flow outside the chat FRONTEND_NAVIGATION
     * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::SIGNAL_SOURCE_FRONTEND_NAVIGATION}),
     * the chat's widget render report FRONTEND_WIDGET_RENDER_REPORT (stored for the next turn, never starts one —
     * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource::FRONTEND_WIDGET_RENDER_REPORT}).
     */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $signalSource = null;
}
