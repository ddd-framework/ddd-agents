<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Rename a conversation's display TITLE. Latches {@see \DDD\Domain\AI\Entities\Conversations\AIConversationSummary::$manuallyEdited}
 * so the auto-summary (first-message / compaction refresh) never overwrites the hand-picked title again. Title-only —
 * the summary TEXT stays system-generated. The service's `find()` enforces read-rights, so a user can only rename a
 * conversation they own.
 */
class AIConversationTitleUpdateRequestDto extends RequestDto
{
    /** @var int The id of the AIConversation whose title to set. */
    #[Parameter(in: Parameter::PATH, required: true)]
    public int $aiConversationId;

    /** @var string The new display title (latches manuallyEdited so auto-refreshes skip it). */
    #[Parameter(in: Parameter::BODY, required: true)]
    public string $title;
}
