<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The conversational context AROUND a recalled message — the `loadMessageContext` result. A semantic recall hit gives
 * the agent ONE matched message; this gives it the surrounding dialogue so it can read the matched line in context
 * ("when did I set the ads budget to 150€" → recall finds the line → this loads the turns around it).
 *
 * Three independent token budgets (tokens ≈ chars/4): the ANCHOR is always shown (capped to `anchorCap`); the BEFORE
 * dialogue fills up to `leftCap`, the AFTER up to `rightCap`. Only USER input + ASSISTANT prose are shown — tool-call
 * / signal / empty turns are skipped (the loading repo filters them), so the window is clean conversational content.
 * The OUTERMOST before/after line is partially shown to hit its cap (before → its END kept, after → its START kept).
 * Every line carries its `messageId` ({@see MessageContextEntry}) so the model can RE-CENTRE on a boundary line and
 * walk further through the chat.
 *
 * Pure value object: {@see build()} does the cap/truncate/assemble over already-loaded messages — no repo contact.
 *
 * @phpstan-consistent-constructor
 */
class MessageContextWindow extends ValueObject
{
    /** @var int|null The conversation the window belongs to. */
    public ?int $conversationId;

    /** @var int|null The recalled anchor message the window is centred on. */
    public ?int $anchorMessageId;

    /** @var int|null The anchor's position in the conversation. */
    public ?int $anchorIndexInConversation;

    /** @var int Approximate token size of the assembled window (chars/4) — what it costs to put in context. */
    public int $approximateTokens;

    /** @var MessageContextEntry[] The window lines in chronological order (before … anchor … after). */
    public array $entries;

    /**
     * @param MessageContextEntry[] $entries
     */
    public function __construct(
        ?int $conversationId,
        ?int $anchorMessageId,
        ?int $anchorIndexInConversation,
        int $approximateTokens,
        array $entries,
    ) {
        parent::__construct();
        $this->conversationId = $conversationId;
        $this->anchorMessageId = $anchorMessageId;
        $this->anchorIndexInConversation = $anchorIndexInConversation;
        $this->approximateTokens = $approximateTokens;
        $this->entries = $entries;
    }

    /** tokens → chars (the agreed estimate: 1 token ≈ 4 chars). */
    protected static function tokensToChars(int $tokens): int
    {
        return max(0, $tokens) * 4;
    }

    /**
     * Assemble the window from the anchor + its surrounding content messages (both lists NEAREST-anchor FIRST), within
     * the three caps. Pure — no DB. The before list is reversed to chronological for display; the after list is already
     * chronological. The boundary message that overflows a side's cap is sliced (before → keep its END; after → keep
     * its START) and flagged truncated.
     *
     * @param AIConversationMessage[] $beforeNearestFirst content messages BEFORE the anchor, nearest first
     * @param AIConversationMessage[] $afterNearestFirst  content messages AFTER the anchor, nearest first
     */
    public static function build(
        AIConversationMessage $anchor,
        array $beforeNearestFirst,
        array $afterNearestFirst,
        int $anchorCapTokens,
        int $leftCapTokens,
        int $rightCapTokens,
    ): static {
        $anchorText = self::buildAnchorText($anchor);
        $anchorCapChars = self::tokensToChars($anchorCapTokens);
        $anchorTruncated = mb_strlen($anchorText) > $anchorCapChars;
        $anchorEntry = new MessageContextEntry(
            messageId: isset($anchor->id) ? (int)$anchor->id : null,
            indexInConversation: $anchor->indexInConversation ?? null,
            role: $anchor->role ?? null,
            text: $anchorTruncated ? mb_substr($anchorText, 0, $anchorCapChars) : $anchorText,
            isAnchor: true,
            truncatedAtEnd: $anchorTruncated,
        );

        // BEFORE — fill leftCap from the nearest message outward; reverse to chronological for display.
        $beforeCollected = [];
        $remaining = self::tokensToChars($leftCapTokens);
        foreach ($beforeNearestFirst as $message) {
            $text = trim((string)($message->message ?? ''));
            $length = mb_strlen($text);
            if ($length <= $remaining) {
                $beforeCollected[] = self::entryFromMessage($message, $text);
                $remaining -= $length;
                continue;
            }
            if ($remaining > 0) {
                // Boundary: keep the END of this message (nearest the anchor's flow); mark its start cut.
                $beforeCollected[] = self::entryFromMessage($message, mb_substr($text, -$remaining), truncatedAtStart: true);
            }
            break;
        }
        $beforeEntries = array_reverse($beforeCollected);

        // AFTER — fill rightCap from the nearest message outward; already chronological.
        $afterEntries = [];
        $remaining = self::tokensToChars($rightCapTokens);
        foreach ($afterNearestFirst as $message) {
            $text = trim((string)($message->message ?? ''));
            $length = mb_strlen($text);
            if ($length <= $remaining) {
                $afterEntries[] = self::entryFromMessage($message, $text);
                $remaining -= $length;
                continue;
            }
            if ($remaining > 0) {
                // Boundary: keep the START of this message; mark its end cut.
                $afterEntries[] = self::entryFromMessage($message, mb_substr($text, 0, $remaining), truncatedAtEnd: true);
            }
            break;
        }

        $entries = [...$beforeEntries, $anchorEntry, ...$afterEntries];
        $usedChars = 0;
        foreach ($entries as $entry) {
            $usedChars += mb_strlen($entry->text);
        }

        return new static(
            conversationId: $anchor->aiConversationId ?? null,
            anchorMessageId: isset($anchor->id) ? (int)$anchor->id : null,
            anchorIndexInConversation: $anchor->indexInConversation ?? null,
            approximateTokens: (int)ceil($usedChars / 4),
            entries: $entries,
        );
    }

    /**
     * The anchor's text as the window shows it. The anchor may be any row type (a keyword hit on a tool row), so its
     * widget blocks lose their `emittedBy` — the exact bytes from before the field; the dialogue around
     * it is TEXT rows only and is shown as stored.
     */
    public static function buildAnchorText(AIConversationMessage $anchor): string
    {
        return trim(WidgetBlocks::buildModelFacingText(WidgetBlocks::fromMarkdown((string)($anchor->message ?? ''))->buildMarkdownWithoutEmittedBy()));
    }

    protected static function entryFromMessage(
        AIConversationMessage $message,
        string $text,
        bool $truncatedAtStart = false,
        bool $truncatedAtEnd = false,
    ): MessageContextEntry {
        return new MessageContextEntry(
            messageId: isset($message->id) ? (int)$message->id : null,
            indexInConversation: $message->indexInConversation ?? null,
            role: $message->role ?? null,
            text: $text,
            truncatedAtStart: $truncatedAtStart,
            truncatedAtEnd: $truncatedAtEnd,
        );
    }

    /** The window as an agent-readable block — header + each line's own {@see MessageContextEntry::toLlmMarkdown()}. */
    public function toLlmMarkdown(): string
    {
        $header = sprintf(
            'Context around message #%s in conversation #%s (≈%d tokens; re-centre on any [#id] to walk further):',
            $this->anchorMessageId ?? '?',
            $this->conversationId ?? '?',
            $this->approximateTokens,
        );
        $lines = array_map(static fn (MessageContextEntry $entry): string => $entry->toLlmMarkdown(), $this->entries);
        return $header . "\n" . implode("\n", $lines);
    }
}
