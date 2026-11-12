<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetTestRows;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `ComposedView` answers through its `Form` nodes: the signal names the form, the fields come
 * back as the submission, a decline is no answer, and a form the tree does not carry is no reaction to it.
 */
class ComposedViewFormSubmissionTest extends UnitTestCase
{
    use ConversationWidgetTestRows;

    public function testASubmitOfOneOfTheTreesFormsIsReadWithItsFields(): void
    {
        $composedViewConversationWidget = self::buildWidgetWithForms();
        self::assertSame(['contact', 'feedback'], $composedViewConversationWidget->getFormNames());

        $reaction = self::buildReaction("ComposedView form \"feedback\" submitted:\n- message: Great: fast service\n- rating: 5");
        self::assertTrue($composedViewConversationWidget->isReactionToThisWidget($reaction));
        $formSubmission = $composedViewConversationWidget->readAnswer($reaction);
        self::assertNotNull($formSubmission);
        self::assertSame('feedback', $formSubmission->formName);
        self::assertSame(['message' => 'Great: fast service', 'rating' => '5'], $formSubmission->fieldValues);
        self::assertFalse($composedViewConversationWidget->isDeclined($reaction));
    }

    public function testADeclineIsNoAnswerAndAForeignFormIsNoReaction(): void
    {
        $composedViewConversationWidget = self::buildWidgetWithForms();
        $decline = self::buildReaction('ComposedView form "contact" declined.');
        self::assertTrue($composedViewConversationWidget->isDeclined($decline));
        self::assertNull($composedViewConversationWidget->readAnswer($decline));

        $foreignForm = self::buildReaction("ComposedView form \"newsletter\" submitted:\n- email: a@b.c");
        self::assertFalse($composedViewConversationWidget->isReactionToThisWidget($foreignForm));
        self::assertNull($composedViewConversationWidget->readAnswer($foreignForm));
    }

    protected static function buildWidgetWithForms(): ComposedViewConversationWidget
    {
        $block = "```widget\n" . json_encode(['type' => 'ComposedView', 'view' => ['type' => 'Stack', 'children' => [
            ['type' => 'Form', 'name' => 'contact', 'children' => []],
            ['type' => 'Surface', 'children' => [['type' => 'Form', 'name' => 'feedback']]],
        ]]]) . "\n```";
        $widgetBlock = WidgetBlocks::fromMarkdown($block)->findFirstBlockOfTypes('ComposedView');
        self::assertNotNull($widgetBlock);
        return ComposedViewConversationWidget::fromWidgetBlock($widgetBlock);
    }

    protected static function buildReaction(string $signalText): ConversationWidgetReaction
    {
        $conversationWidgetReaction = ConversationWidgetReaction::fromMessage(self::buildSignalRow(4, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, $signalText));
        self::assertNotNull($conversationWidgetReaction);
        return $conversationWidgetReaction;
    }
}
