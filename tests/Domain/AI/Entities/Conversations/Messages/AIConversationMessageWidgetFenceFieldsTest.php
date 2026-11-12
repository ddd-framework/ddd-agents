<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * {@see AIConversationMessage::getWidgetFieldsOfType()} — the generic, domain-neutral READ of the ```widget fences
 * a message text carries. It is how a tool obtains a widget the CUSTOMER composed (the composer writes the fence
 * into their own message) without asking the model to copy it. Guards the contract every consumer relies on:
 * only fences of the asked type come back, the payload arrives as a decoded array, malformed JSON never throws.
 */
class AIConversationMessageWidgetFenceFieldsTest extends UnitTestCase
{
    public function testReturnsOnlyTheFencesOfTheAskedType(): void
    {
        $aiConversationMessage = $this->buildMessage(
            "Mach diesen Text kürzer\n\n"
            . "```widget\n{\"type\": \"WebsiteEditorSelection\", \"v\": 1, \"websiteId\": 166, \"elements\": [{\"kind\": \"slot\"}]}\n```\n\n"
            . "```widget\n{\"type\": \"SingleReview\", \"externalId\": \"gr1_abc\"}\n```"
        );

        $widgetFenceFields = $aiConversationMessage->getWidgetFieldsOfType('WebsiteEditorSelection');

        self::assertCount(1, $widgetFenceFields);
        self::assertSame(166, $widgetFenceFields[0]['websiteId']);
        self::assertSame([['kind' => 'slot']], $widgetFenceFields[0]['elements']);
        self::assertSame('WebsiteEditorSelection', $widgetFenceFields[0]['type'], 'the type key is returned as-is; consumers ignore it');
    }

    public function testReturnsEveryFenceOfThatTypeInMessageOrder(): void
    {
        $aiConversationMessage = $this->buildMessage(
            "```widget\n{\"type\": \"SingleReview\", \"externalId\": \"first\"}\n```\n"
            . "```widget\n{\"type\": \"SingleReview\", \"externalId\": \"second\"}\n```"
        );

        $widgetFenceFields = $aiConversationMessage->getWidgetFieldsOfType('SingleReview');

        self::assertSame(['first', 'second'], array_column($widgetFenceFields, 'externalId'));
    }

    public function testMalformedJsonIsSkippedInsteadOfThrowing(): void
    {
        $aiConversationMessage = $this->buildMessage(
            "```widget\n{\"type\": \"WebsiteEditorSelection\", \"elements\": [oops\n```\n"
            . "```widget\n{\"type\": \"WebsiteEditorSelection\", \"websiteId\": 7}\n```"
        );

        $widgetFenceFields = $aiConversationMessage->getWidgetFieldsOfType('WebsiteEditorSelection');

        self::assertCount(1, $widgetFenceFields);
        self::assertSame(7, $widgetFenceFields[0]['websiteId']);
    }

    public function testAMessageWithoutAMatchingFenceReturnsNothing(): void
    {
        self::assertSame([], $this->buildMessage('Kannst du die Überschrift kürzen?')->getWidgetFieldsOfType('WebsiteEditorSelection'));
        self::assertSame([], $this->buildMessage("```json\n{\"type\": \"WebsiteEditorSelection\"}\n```")->getWidgetFieldsOfType('WebsiteEditorSelection'));
        self::assertSame([], (new AIConversationMessage())->getWidgetFieldsOfType('WebsiteEditorSelection'), 'an unset message text is no fence');
    }

    public function testTheDistinctFenceTypesAreListedInFirstAppearanceOrder(): void
    {
        $message = $this->buildMessage(
            "Bitte hier\n\n```widget\n{\"type\": \"WebsiteEditorSelection\", \"websiteId\": 7}\n```\n\n"
            . "```widget\n{\"type\": \"ReviewSummary\", \"id\": 3}\n```\n\n"
            . "```widget\n{\"type\": \"WebsiteEditorSelection\", \"websiteId\": 8}\n```\n\n"
            . "```widget\n{not json}\n```"
        );
        self::assertSame(['WebsiteEditorSelection', 'ReviewSummary'], $message->getWidgetTypes());
        self::assertSame([], $this->buildMessage('Kannst du die Überschrift kürzen?')->getWidgetTypes());
        self::assertSame([], (new AIConversationMessage())->getWidgetTypes());
    }

    public function testAFenceOnlyMessageIsRecognisedAsCarryingNoWordsOfTheCustomer(): void
    {
        $fence = "```widget\n{\"type\": \"WebsiteEditorSelection\", \"websiteId\": 7, \"elements\": []}\n```";
        self::assertTrue($this->buildMessage($fence)->carriesOnlyWidgetBlocks());
        self::assertTrue($this->buildMessage("  \n" . $fence . "\n\n" . $fence . "\n")->carriesOnlyWidgetBlocks(), 'several fences and whitespace are still no words');
        self::assertFalse($this->buildMessage("Mach das kürzer\n\n" . $fence)->carriesOnlyWidgetBlocks(), 'prose next to the fence is a request');
        self::assertFalse($this->buildMessage('Kannst du die Überschrift kürzen?')->carriesOnlyWidgetBlocks(), 'no fence at all');
        self::assertFalse($this->buildMessage("```json\n{\"type\": \"WebsiteEditorSelection\"}\n```")->carriesOnlyWidgetBlocks(), 'a non-widget code block is text');
        self::assertFalse((new AIConversationMessage())->carriesOnlyWidgetBlocks());
    }

    protected function buildMessage(string $messageText): AIConversationMessage
    {
        $aiConversationMessage = new AIConversationMessage();
        $aiConversationMessage->message = $messageText;
        return $aiConversationMessage;
    }

    public function testTheReadersFollowWidgetBlocks(): void
    {
        // the blocks the frontend renders, not the old case-insensitive inline regex
        $json = '{"type":"WebsiteEditorSelection","selector":"h1"}';
        foreach (["~~~widget\n" . $json . "\n~~~", "```widget json\n" . $json . "\n```", "```widget\n" . $json] as $text) {
            self::assertSame(['WebsiteEditorSelection'], $this->buildMessage($text)->getWidgetTypes(), $text);
            self::assertTrue($this->buildMessage($text)->carriesOnlyWidgetBlocks(), $text);
        }
        // a glued opener reads as the frontend repairs it — a block after the prose, not a fence-only message
        self::assertSame(['WebsiteEditorSelection'], $this->buildMessage("text ```widget\n" . $json . "\n```")->getWidgetTypes());
        self::assertFalse($this->buildMessage("text ```widget\n" . $json . "\n```")->carriesOnlyWidgetBlocks());
        self::assertCount(1, $this->buildMessage("```widget\n{\"type\":\"WebsiteEditorSelectionDirective\"}\n```")->getWidgetFieldsOfType('WebsiteEditorSelection'), 'the legacy suffix is the same widget');
        foreach (["```WIDGET\n" . $json . "\n```", "    ```widget\n" . $json . "\n    ```"] as $text) {
            self::assertSame([], $this->buildMessage($text)->getWidgetFieldsOfType('WebsiteEditorSelection'), $text);
            self::assertFalse($this->buildMessage($text)->carriesOnlyWidgetBlocks(), $text);
        }
    }
}
