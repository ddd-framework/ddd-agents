<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One line of the dialogue the owner-consent check reads: who spoke, at which conversation index, and
 * the words. An ASSISTANT line is context for the first question only ("was the step explained?"); a WIDGET line is the
 * askOwner widget an owner line picks on; only an OWNER line can carry the agreement.
 *
 * @phpstan-consistent-constructor
 */
class OwnerConsentDialogueLine extends ValueObject
{
    public const string SPEAKER_ASSISTANT = 'ASSISTANT';
    public const string SPEAKER_WIDGET = 'WIDGET';
    public const string SPEAKER_OWNER = 'OWNER';

    /** One of the SPEAKER_* constants. */
    public string $speaker = self::SPEAKER_OWNER;

    /** The row's `indexInConversation`. */
    public int $messageIndex = -1;

    /** The words, cut to the decision's limit. */
    public string $text = '';

    public static function create(string $speaker, int $messageIndex, string $text): static
    {
        $line = new static();
        $line->speaker = $speaker;
        $line->messageIndex = $messageIndex;
        $line->text = $text;
        return $line;
    }

    /**
     * A row speaks once in the dialogue — the speaker and the index are the identity (a widget and its pick share no
     * index).
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->speaker . ':' . $this->messageIndex);
    }

    public function isOwnerLine(): bool
    {
        return $this->speaker === self::SPEAKER_OWNER;
    }

    /** The line as the decision's state carries it — the speaker and the words, no index (order is the list's). */
    public function toDecisionStateRow(): array
    {
        return ['speaker' => $this->speaker, 'text' => $this->text];
    }
}
