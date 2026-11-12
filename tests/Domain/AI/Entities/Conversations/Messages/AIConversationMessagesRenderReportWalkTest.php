<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetTestRows;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The frontend's widget render report (FRONTEND_WIDGET_RENDER_REPORT) is a SIGNAL row that
 * owes no work: after the agent's final text it leaves nothing owed in every owed-work walk, so no recovery pass
 * resumes a turn to answer it; an owner's message before it stays owed.
 */
class AIConversationMessagesRenderReportWalkTest extends UnitTestCase
{
    use ConversationWidgetTestRows;

    protected const string REPORT_TEXT = "Widget render report for message 2:\n- widget 1 (ComposedView #1): failed RENDER_FAILED";

    public function testAReportAfterTheFinalTextOwesNothing(): void
    {
        $messages = self::buildMessages(
            self::buildOwnerTextRow(1, 'How are my visits?'),
            self::buildAssistantTextRow(2, 'Here they are.'),
            self::buildSignalRow(3, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_RENDER_REPORT, self::REPORT_TEXT),
        );
        self::assertTrue($messages->getElements()[2]->isSignalThatOwesNoWork());
        self::assertFalse($messages->hasUnansweredInboundMessage());
        self::assertFalse($messages->hasUnansweredInboundRowAtTheTail());
        self::assertTrue($messages->isNewestWireMessageFinalAssistantText());
        self::assertNull($messages->getOldestUnansweredInboundIndex());
    }

    public function testAnOwnerMessageBeforeTheReportStaysOwed(): void
    {
        $messages = self::buildMessages(
            self::buildAssistantTextRow(1, 'Here they are.'),
            self::buildOwnerTextRow(2, 'And the calls?'),
            self::buildSignalRow(3, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_RENDER_REPORT, self::REPORT_TEXT),
        );
        self::assertTrue($messages->hasUnansweredInboundMessage());
        self::assertFalse($messages->isNewestWireMessageFinalAssistantText());
        self::assertSame(2, $messages->getOldestUnansweredInboundIndex());
    }

    public function testAWidgetClickStillOwesATurn(): void
    {
        $messages = self::buildMessages(
            self::buildAssistantTextRow(1, 'Pick one.'),
            self::buildSignalRow(2, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'User selected B'),
        );
        self::assertFalse($messages->getElements()[1]->isSignalThatOwesNoWork());
        self::assertTrue($messages->hasUnansweredInboundMessage());
    }
}
