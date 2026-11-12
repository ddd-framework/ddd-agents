<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One message of a tail a resume diagnosis shows ({@see AIConversationResumeDiagnosis::$fullTailRows},
 * {@see AIConversationResumeDiagnosis::$deriveTailRows}), as it is printed.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationResumeDiagnosisTailRow extends ValueObject
{
    /** The longest message preview shown, in characters. */
    public const int MESSAGE_PREVIEW_MAXIMUM_LENGTH = 34;

    /** @var string The message's index in the conversation; `—` when it has none */
    public string $indexInConversation = '';

    /** @var string The message's role and type, as `role/type` */
    public string $roleAndMessageType = '';

    /** @var string The start of the message text on one line, cut with `…` */
    public string $messagePreview = '';

    /**
     * @param AIConversationMessage $message
     * @return static
     */
    public static function forMessage(AIConversationMessage $message): static
    {
        $messagePreview = trim(preg_replace('/\s+/', ' ', $message->message ?? '') ?? '');
        if (mb_strlen($messagePreview) > self::MESSAGE_PREVIEW_MAXIMUM_LENGTH) {
            $messagePreview = mb_substr($messagePreview, 0, self::MESSAGE_PREVIEW_MAXIMUM_LENGTH - 3) . '…';
        }
        $tailRow = new static();
        $tailRow->indexInConversation = (string)($message->indexInConversation ?? '—');
        $tailRow->roleAndMessageType = ($message->role ?? '—') . '/' . ($message->messageType ?? '—');
        $tailRow->messagePreview = $messagePreview;
        return $tailRow;
    }

    /** @return string[] The three values in the order index, role/type, preview */
    public function getPrintedValues(): array
    {
        return [$this->indexInConversation, $this->roleAndMessageType, $this->messagePreview];
    }
}
