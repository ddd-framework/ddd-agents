<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationSignalSource;
use DDD\Domain\AI\Entities\Conversations\Widgets\ShownConversationWidgets;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `AIConversationMessages::buildShownWidgets()` is memoised on the rows' content: rows that read the
 * same give the same view object back, and every change the view depends on (a row added or removed, a text edited in
 * place on an OLDER row, the role, author, type, signal source or index of a row, a row object replaced) gives a fresh
 * view equal to `ShownConversationWidgets::buildFor()` over the changed rows.
 */
class ShownConversationWidgetsMemoTest extends UnitTestCase
{
    use ConversationWidgetTestRows;

    public function testUnchangedRowsGiveTheSameViewObjectBack(): void
    {
        $messages = self::buildConversationRows();

        $firstView = $messages->buildShownWidgets();

        self::assertSame($firstView, $messages->buildShownWidgets());
        self::assertSame(self::summarize(ShownConversationWidgets::buildFor($messages)), self::summarize($firstView));
    }

    public function testAnAddedRowGivesAFreshViewWithTheNewReaction(): void
    {
        $messages = self::buildConversationRows();
        $viewBefore = $messages->buildShownWidgets();

        $ownerAnswer = self::buildOwnerTextRow(4, 'Yes');
        $messages->add($ownerAnswer);
        $viewAfter = $messages->buildShownWidgets();

        self::assertNotSame($viewBefore, $viewAfter);
        self::assertSame(self::summarize(ShownConversationWidgets::buildFor($messages)), self::summarize($viewAfter));
        self::assertTrue($viewAfter->findNewestOfType('QuickReplies')?->ownerWroteAfterIt());
        self::assertFalse($viewBefore->findNewestOfType('QuickReplies')?->ownerWroteAfterIt(), 'the earlier view object is not changed');
    }

    public function testATextEditedInPlaceOnAnOlderRowGivesAFreshView(): void
    {
        $messages = self::buildConversationRows();
        $viewBefore = $messages->buildShownWidgets();

        $olderAssistantRow = $messages->getElements()[0];
        $olderAssistantRow->message = 'No widget any more.';
        $viewAfter = $messages->buildShownWidgets();

        self::assertNotSame($viewBefore, $viewAfter);
        self::assertFalse($viewAfter->hasShownOfType('OpeningHoursEditor'));
        self::assertTrue($viewBefore->hasShownOfType('OpeningHoursEditor'));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function provideRowFieldChanges(): array
    {
        return [
            'role' => ['role', AIConversationMessage::ROLE_ASSISTANT],
            'author' => ['authorType', AIConversationMessage::AUTHOR_AGENT],
            'message type' => ['messageType', AIConversationMessage::MESSAGE_TOOL_MESSAGE],
            'index' => ['indexInConversation', 1],
        ];
    }

    #[DataProvider('provideRowFieldChanges')]
    public function testAChangedFieldTheViewReadsGivesAFreshView(string $propertyName, mixed $newValue): void
    {
        $messages = self::buildConversationRows();
        $viewBefore = $messages->buildShownWidgets();

        $ownerRow = $messages->getElements()[1];
        $ownerRow->$propertyName = $newValue;
        $viewAfter = $messages->buildShownWidgets();

        self::assertNotSame($viewBefore, $viewAfter, $propertyName);
        self::assertSame(self::summarize(ShownConversationWidgets::buildFor($messages)), self::summarize($viewAfter), $propertyName);
    }

    public function testAChangedSignalSourceGivesAFreshView(): void
    {
        $messages = self::buildMessages(
            self::buildAssistantTextRow(0, self::buildWidgetFence('OpeningHoursEditor')),
            self::buildSignalRow(1, AIConversationSignalSource::FRONTEND_WIDGET_CLICK->value, 'User saved their opening hours on the OpeningHoursEditor widget.'),
        );
        $viewBefore = $messages->buildShownWidgets();
        self::assertCount(1, $viewBefore->findNewestOfType('OpeningHoursEditor')?->getReactions() ?? []);

        $messages->getElements()[1]->signalSource = AIConversationSignalSource::MID_TURN_SIGNAL_REPLAY->value;
        $viewAfter = $messages->buildShownWidgets();

        self::assertNotSame($viewBefore, $viewAfter);
        self::assertCount(0, $viewAfter->findNewestOfType('OpeningHoursEditor')?->getReactions() ?? [], 'the replay copy is no reaction');
    }

    public function testARowReplacedByAnEqualObjectGivesAFreshViewOverTheNewObject(): void
    {
        $messages = self::buildConversationRows();
        $viewBefore = $messages->buildShownWidgets();

        $replacedRows = self::buildConversationRows();
        $messages->clear();
        foreach ($replacedRows->getElements() as $replacedRow) {
            $messages->add($replacedRow);
        }
        $viewAfter = $messages->buildShownWidgets();

        self::assertNotSame($viewBefore, $viewAfter);
        self::assertSame(self::summarize($viewBefore), self::summarize($viewAfter));
        self::assertSame($replacedRows->getElements()[1], $viewAfter->findNewestOfType('OpeningHoursEditor')?->getReactions()[0]->getReactionMessage());
    }

    protected static function buildConversationRows(): AIConversationMessages
    {
        return self::buildMessages(
            self::buildAssistantTextRow(0, "Hours:\n" . self::buildWidgetFence('OpeningHoursEditor')),
            self::buildOwnerTextRow(2, 'Mon to Fri 9 to 5'),
            self::buildToolRow(3, self::buildWidgetFence('QuickReplies', ['options' => ['Yes', 'No']])),
        );
    }

    /**
     * @return array<int, array{0: ?int, 1: string, 2: int, 3: int[]}>
     */
    protected static function summarize(ShownConversationWidgets $shownConversationWidgets): array
    {
        $summaries = [];
        foreach ($shownConversationWidgets->getElements() as $shownConversationWidget) {
            $summaries[] = [
                $shownConversationWidget->hostIndexInConversation,
                $shownConversationWidget->widgetType,
                $shownConversationWidget->ordinalAmongBlocksOfType,
                array_map(static fn ($conversationWidgetReaction): int => $conversationWidgetReaction->indexInConversation, $shownConversationWidget->getReactions()),
            ];
        }
        return $summaries;
    }
}
