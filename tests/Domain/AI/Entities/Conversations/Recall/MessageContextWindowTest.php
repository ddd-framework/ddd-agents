<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Recall;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Recall\MessageContextEntry;
use DDD\Domain\AI\Entities\Conversations\Recall\MessageContextWindow;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The pure assembly/cap logic of {@see MessageContextWindow::build()} — the part that can go wrong (ordering,
 * boundary truncation, per-side budgets, message-id propagation). No DB.
 */
class MessageContextWindowTest extends UnitTestCase
{
    protected function message(int $id, int $index, string $role, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $this->setPropertyValue($message, 'id', $id);
        $message->indexInConversation = $index;
        $message->role = $role;
        $message->message = $text;
        $message->aiConversationId = 7;
        return $message;
    }

    /** Fits within budget: chronological order [before… anchor …after], anchor marked, ids carried, nothing truncated. */
    public function testAssemblesChronologicalWindowWithAnchorMarkedAndIds(): void
    {
        $anchor = $this->message(20, 5, 'ASSISTANT', 'budget set to 150 EUR/day');
        // repo returns NEAREST-first
        $before = [$this->message(19, 4, 'USER', 'increase the ads budget'), $this->message(18, 3, 'ASSISTANT', 'sure')];
        $after = [$this->message(21, 6, 'USER', 'I never approved that'), $this->message(22, 7, 'ASSISTANT', 'apologies')];

        $window = MessageContextWindow::build($anchor, $before, $after, 2000, 2000, 2000);

        $indexes = array_map(static fn (MessageContextEntry $e): ?int => $e->indexInConversation, $window->entries);
        self::assertSame([3, 4, 5, 6, 7], $indexes, 'entries must be chronological (before reversed, anchor, after)');
        $ids = array_map(static fn (MessageContextEntry $e): ?int => $e->messageId, $window->entries);
        self::assertSame([18, 19, 20, 21, 22], $ids, 'every line carries its messageId (re-centre handles)');
        $anchorEntry = $window->entries[2];
        self::assertTrue($anchorEntry->isAnchor);
        self::assertSame(20, $window->anchorMessageId);
        self::assertSame(7, $window->conversationId);
        foreach ($window->entries as $entry) {
            self::assertFalse($entry->truncatedAtStart);
            self::assertFalse($entry->truncatedAtEnd);
        }
    }

    /** Left cap forces the OUTERMOST before-message to be shown end-only (truncatedAtStart); after side independent. */
    public function testLeftCapTruncatesOutermostBeforeMessageAtStart(): void
    {
        $anchor = $this->message(20, 5, 'ASSISTANT', 'x');
        // leftCap = 10 tokens = 40 chars. Nearest is 20 chars (fits, 20 budget left); farther is 40 chars → only its
        // last 20 chars fit → shown end-only (truncatedAtStart).
        $before = [
            $this->message(19, 4, 'USER', str_repeat('a', 20)),   // nearest (fits fully)
            $this->message(18, 3, 'USER', str_repeat('b', 40)),   // farther → boundary, partial
        ];
        $window = MessageContextWindow::build($anchor, $before, [], 2000, 10, 0);

        // chronological: [farther(truncated), nearest, anchor]
        self::assertCount(3, $window->entries);
        $farther = $window->entries[0];
        self::assertSame(18, $farther->messageId);
        self::assertTrue($farther->truncatedAtStart, 'the outermost before-message is shown end-only');
        self::assertLessThan(40, mb_strlen($farther->text), 'it was sliced to the remaining budget');
        self::assertFalse($window->entries[1]->truncatedAtStart, 'the nearest before-message is shown in full');
        self::assertTrue($window->entries[2]->isAnchor);
    }

    /** An oversized anchor is truncated to its own cap (end cut); a 0 side-cap yields no context that side. */
    public function testOversizedAnchorIsCappedAndZeroSideCapAddsNothing(): void
    {
        $anchor = $this->message(20, 5, 'ASSISTANT', str_repeat('z', 1000));
        $window = MessageContextWindow::build($anchor, [$this->message(19, 4, 'USER', 'hi')], [], 10, 0, 0);

        self::assertCount(1, $window->entries, 'left cap 0 → no before; anchor only');
        $anchorEntry = $window->entries[0];
        self::assertTrue($anchorEntry->isAnchor);
        self::assertTrue($anchorEntry->truncatedAtEnd, 'oversized anchor is end-truncated to its cap');
        self::assertSame(40, mb_strlen($anchorEntry->text), '10 tokens = 40 chars');
    }
}
