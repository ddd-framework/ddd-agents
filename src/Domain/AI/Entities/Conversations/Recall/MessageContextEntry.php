<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One line of a {@see MessageContextWindow} — a single conversational message (user input or assistant prose) shown
 * around a recalled anchor message. The anchor itself is marked ({@see $isAnchor}); the OUTERMOST before/after lines
 * may be partially shown to hit the token cap — {@see $truncatedAtStart} (its beginning was cut, "…" prefix) /
 * {@see $truncatedAtEnd} (its end was cut, "…" suffix). Carries its own {@see $messageId} so the model can RE-CENTRE
 * on any line — call the context tool again with a boundary line's id to walk further back/forward through the chat.
 */
class MessageContextEntry extends ValueObject
{
    /** @var int|null The message's own id — the re-centre handle for walking the conversation. */
    public ?int $messageId;

    /** @var int|null Position of this message in its conversation (chronological order key). */
    public ?int $indexInConversation;

    /** @var string|null The message role (USER / ASSISTANT). */
    public ?string $role;

    /** @var string The (possibly boundary-truncated) message text shown for this line. */
    public string $text;

    /** @var bool Whether this is the recalled anchor message the window is centred on. */
    public bool $isAnchor;

    /** @var bool The message's BEGINNING was cut to fit the cap (render with a leading "…"). */
    public bool $truncatedAtStart;

    /** @var bool The message's END was cut to fit the cap (render with a trailing "…"). */
    public bool $truncatedAtEnd;

    public function __construct(
        ?int $messageId,
        ?int $indexInConversation,
        ?string $role,
        string $text,
        bool $isAnchor = false,
        bool $truncatedAtStart = false,
        bool $truncatedAtEnd = false,
    ) {
        parent::__construct();
        $this->messageId = $messageId;
        $this->indexInConversation = $indexInConversation;
        $this->role = $role;
        $this->text = $text;
        $this->isAnchor = $isAnchor;
        $this->truncatedAtStart = $truncatedAtStart;
        $this->truncatedAtEnd = $truncatedAtEnd;
    }

    /** One Markdown line: `▶ [#id] role: text` — the anchor marked with `▶`, the `#id` is the re-centre handle,
     *  cut boundaries shown with `…`. */
    public function toLlmMarkdown(): string
    {
        $role = $this->role !== null ? strtolower($this->role) : '?';
        $text = ($this->truncatedAtStart ? '…' : '') . trim($this->text) . ($this->truncatedAtEnd ? '…' : '');
        $marker = $this->isAnchor ? '▶ ' : '  ';
        $id = $this->messageId !== null ? '[#' . $this->messageId . '] ' : '';
        return $marker . $id . $role . ': ' . $text;
    }
}
