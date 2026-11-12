<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * While the owner is on the phone line, a tool result's widget dictation never reaches the model — the
 * fence and the sentence that orders it become one neutral note, on the raw and on the JSON-encoded result alike.
 */
class PhoneLineWidgetFenceTest extends UnitTestCase
{
    protected const string CONNECT_DICTATION = "Google is not connected. To offer the connection, write ONE short line, then on its own line exactly:\n```widget\n{\"type\":\"ReviewsConnect\"}\n```";

    public function testTheDictationAndItsFenceBecomeTheNote(): void
    {
        $phoneText = AIConversationMessage::replaceWidgetFencesForThePhoneLine(self::CONNECT_DICTATION);
        self::assertStringStartsWith('Google is not connected.', $phoneText, 'the fact before the dictation stays');
        self::assertStringContainsString(AIConversationMessage::PHONE_LINE_WIDGET_NOTE, $phoneText);
        self::assertStringNotContainsString('```', $phoneText);
        self::assertStringNotContainsString('own line', $phoneText);
    }

    public function testTheJsonEncodedResultStaysValidJson(): void
    {
        $encodedResult = json_encode(['content' => [['type' => 'text', 'text' => self::CONNECT_DICTATION]], 'structuredContent' => ['connected' => false]]);
        $phoneResult = AIConversationMessage::replaceWidgetFencesForThePhoneLine((string)$encodedResult);
        $decodedResult = json_decode($phoneResult, true);
        self::assertIsArray($decodedResult);
        self::assertStringContainsString(AIConversationMessage::PHONE_LINE_WIDGET_NOTE, $decodedResult['content'][0]['text']);
        self::assertFalse($decodedResult['structuredContent']['connected']);
    }

    public function testABareFenceAndAResultWithoutOneAreHandled(): void
    {
        $phoneText = AIConversationMessage::replaceWidgetFencesForThePhoneLine("Saved.\n```widget\n{\"type\":\"PostPreview\"}\n```\nDone.");
        self::assertStringStartsWith('Saved.', $phoneText);
        self::assertStringEndsWith('Done.', $phoneText);
        self::assertStringNotContainsString('PostPreview', $phoneText);
        self::assertSame('Plain result.', AIConversationMessage::replaceWidgetFencesForThePhoneLine('Plain result.'));
        self::assertSame("Code:\n```php\necho 1;\n```", AIConversationMessage::replaceWidgetFencesForThePhoneLine("Code:\n```php\necho 1;\n```"), 'other fences stay');
    }

    public function testProseNamingAWidgetBlockDoesNotSwallowTheText(): void
    {
        $gateText = "done not applied — this item requires a ```widget block of type \"SetupHandover\" in your ANSWER TEXT first. The block, on its own lines in your answer text, is:\n```widget\n{\"type\":\"SetupHandover\"}\n```\nThe platform closes the item.";
        $phoneText = AIConversationMessage::replaceWidgetFencesForThePhoneLine($gateText);
        self::assertStringContainsString('requires a ```widget block of type', $phoneText, 'prose stays');
        self::assertStringContainsString(AIConversationMessage::PHONE_LINE_WIDGET_NOTE, $phoneText);
        self::assertStringContainsString('The platform closes the item.', $phoneText);
        self::assertStringNotContainsString('{"type"', $phoneText);
    }
}
