<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationUrlGrounding;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The stored tool result is the RAW JSON blob: its escapes (`\/`, `\n`, `·`) are not interpreted by a string scan.
 * The verified-URL parser reads the decoded content texts (a run put every network of the page read's line under
 * "facebook" because the ` · ` separator stood as `·`); the URL grounding treats a sentence-ending dot after a
 * handle as punctuation (a run refused the owner's own Instagram handle).
 */
class ToolResultTextsAndVerifiedUrlsTest extends UnitTestCase
{
    protected const string RAW_PAGE_READ_RESULT = '{"content":[{"type":"text","text":"## Page 1 of 1\n# roastmarket — https:\/\/roastmarket.de\/\nPlatform-verified social profile URLs on this page: facebook https:\/\/facebook.com\/roastmarket · instagram https:\/\/instagram.com\/roastmarket · linkedin https:\/\/linkedin.com\/company\/roast-market · tiktok https:\/\/tiktok.com\/@roastmarket.kaffee · youtube https:\/\/youtube.com\/channel\/UCimDg1tStvGCVLHqLExS80Q\nContent of https:\/\/roastmarket.de\/ (EXTERNAL):\n\nKaffee online kaufen."}],"isError":false}';

    /** @param array<int, AIConversationMessage> $messages */
    protected function messageSet(array $messages): AIConversationMessages
    {
        $messageSet = self::instantiateWithoutConstructor(AIConversationMessages::class);
        self::setPropertyValue($messageSet, 'elements', $messages);
        self::setPropertyValue($messageSet, 'elementCount', count($messages));
        return $messageSet;
    }

    protected function completedRead(int $index, string $rawResult): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_AGENT;
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = 'getWebPageAsMarkdown';
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = $rawResult;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function ownerText(int $index, string $text): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = $text;
        $message->toolCall = null;
        return $message;
    }

    public function testTheRawBlobIsDecodedToItsTexts(): void
    {
        $decoded = AIConversationMessages::decodeToolResultTexts(self::RAW_PAGE_READ_RESULT);
        self::assertStringContainsString("roastmarket · instagram https://instagram.com/roastmarket · linkedin", $decoded);
        self::assertStringContainsString("\nContent of https://roastmarket.de/", $decoded);
        self::assertSame('plain text stays', AIConversationMessages::decodeToolResultTexts('plain text stays'));
        self::assertSame('', AIConversationMessages::decodeToolResultTexts(''));
    }

    public function testEveryNetworkOfTheVerifiedLineIsParsedUnderItsOwnName(): void
    {
        $messages = $this->messageSet([$this->completedRead(5, self::RAW_PAGE_READ_RESULT)]);
        $verified = $messages->buildToolCallHistory()->getPlatformVerifiedSocialProfileUrls();
        self::assertNotNull($verified);
        self::assertSame([
            'facebook' => ['https://facebook.com/roastmarket'],
            'instagram' => ['https://instagram.com/roastmarket'],
            'linkedin' => ['https://linkedin.com/company/roast-market'],
            'tiktok' => ['https://tiktok.com/@roastmarket.kaffee'],
            'youtube' => ['https://youtube.com/channel/UCimDg1tStvGCVLHqLExS80Q'],
        ], $verified->urlsByNetwork);
        self::assertSame(5, $verified->messageIndex);
    }

    public function testARetypedUrlOfAVerifiedNetworkIsCorrectedToTheVerifiedOne(): void
    {
        $messages = $this->messageSet([$this->completedRead(5, self::RAW_PAGE_READ_RESULT)]);
        $provenance = new \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance();
        $provenance->conversationMessages = $messages;
        self::assertFalse(AIConversationUrlGrounding::isUrlGrounded($provenance, 'https://www.linkedin.com/company/roastmarket'));
        self::assertSame('https://linkedin.com/company/roast-market', AIConversationUrlGrounding::findPlatformVerifiedSocialProfileUrl($provenance, 'linkedin', 'https://www.linkedin.com/company/roastmarket'));
        self::assertSame('https://tiktok.com/@roastmarket.kaffee', AIConversationUrlGrounding::findPlatformVerifiedSocialProfileUrl($provenance, 'tiktok', 'https://www.tiktok.com/@roastmarket'));
        self::assertNull(AIConversationUrlGrounding::findPlatformVerifiedSocialProfileUrl($provenance, 'pinterest', 'https://pinterest.com/roastmarket'));
    }

    public function testASentenceEndingDotAfterTheOwnersHandleIsPunctuation(): void
    {
        $messages = $this->messageSet([$this->ownerText(2, 'Website hab ich keine, läuft alles über Instagram @nailsbydana.koeln.')]);
        $provenance = new \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance();
        $provenance->conversationMessages = $messages;
        self::assertTrue(AIConversationUrlGrounding::isUrlGrounded($provenance, '@nailsbydana.koeln'));
        self::assertFalse(AIConversationUrlGrounding::isUrlGrounded($provenance, 'https://www.instagram.com/nailsbydana.koeln'), 'the URL built from the handle is grounded through the handle, which the write checks as the stated input');
        self::assertFalse(AIConversationUrlGrounding::isUrlGrounded($provenance, '@nailsbydana'), 'a shortened handle is not the handle');
        self::assertTrue(AIConversationUrlGrounding::containsUrlOnABoundary('youtube.com/@fiveelephant4192 and more', 'youtube.com/@fiveelephant4192'));
        self::assertFalse(AIConversationUrlGrounding::containsUrlOnABoundary('youtube.com/@fiveelephant4192', 'youtube.com/@fiveelephant'));
    }
}

