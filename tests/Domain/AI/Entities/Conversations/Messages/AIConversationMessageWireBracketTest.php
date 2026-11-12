<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Owner channel awareness: ONE wire bracket for the owner message, its security-filtered twin and
 * the reply-language cue's pointer. A row without a device renders today's bytes (time only); a stamped device rides
 * inside the bracket; SIGNAL rows never carry a bracket.
 */
class AIConversationMessageWireBracketTest extends UnitTestCase
{
    protected function ownerMessage(string $text, ?OwnerDevice $ownerDevice): AIConversationMessage
    {
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = $text;
        $message->getChangeHistory()->createdTime = DateTime::fromTimestamp(
            (new \DateTimeImmutable('2026-10-14 07:12:00', new \DateTimeZone('UTC')))->getTimestamp()
        );
        if ($ownerDevice !== null) {
            $message->ownerDevice = $ownerDevice;
        }
        return $message;
    }

    public function testARowWithoutADeviceRendersTheTimeOnly(): void
    {
        $message = $this->ownerMessage('Hallo', null);
        $berlin = new \DateTimeZone('Europe/Berlin');
        self::assertSame('[2026-10-14 09:12:00+02:00]', $message->buildWireBracket($berlin));
        self::assertSame('[2026-10-14 09:12:00+02:00] Hallo', $message->getOpenAiMessageContent(false, $berlin));
        self::assertSame('[2026-10-14 07:12] Hallo', $message->getOpenAiMessageContent(), 'the legacy app-clock form without a zone');
    }

    public function testAStampedDeviceRidesInsideTheBracket(): void
    {
        $message = $this->ownerMessage('Hallo', OwnerDevice::fromClientHeader('mobileApp; os=iOS; device=iPhone16,2'));
        $berlin = new \DateTimeZone('Europe/Berlin');
        self::assertSame('[2026-10-14 09:12:00+02:00 | via iPhone 15 Pro Max app] Hallo', $message->getOpenAiMessageContent(false, $berlin));
        // the security-filtered twin carries the same bracket
        self::assertStringStartsWith('[2026-10-14 09:12:00+02:00 | via iPhone 15 Pro Max app] ', $message->toSecurityFilteredOpenAiChatMessageArray($berlin)['content']);
        // an unknown device renders no tag — today's bytes
        $unknown = $this->ownerMessage('Hallo', OwnerDevice::fromRequestHints(null, 'curl/8.4.0'));
        self::assertSame('[2026-10-14 09:12:00+02:00] Hallo', $unknown->getOpenAiMessageContent(false, $berlin));
    }

    public function testASignalRowNeverCarriesABracket(): void
    {
        $signal = $this->ownerMessage('clicked B', OwnerDevice::phoneCall());
        $signal->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $signal->signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK;
        self::assertSame('[SIGNAL:' . AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK . '] clicked B', $signal->toOpenAiChatMessageArray()['content']);
        self::assertStringStartsWith('[SIGNAL:', $signal->toSecurityFilteredOpenAiChatMessageArray()['content']);
    }

    public function testTheReplyLanguageCuePointsAtTheBracketAbove(): void
    {
        $agentContext = new AgentModelInput();
        $agentContext->timezone = new \DateTimeZone('Europe/Berlin');
        $agentContext->messages = [$this->ownerMessage('Bonjour', OwnerDevice::fromUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15'))];
        self::assertStringContainsString('the one marked [2026-10-14 09:12:00+02:00 | via web, Safari on Mac] above', $agentContext->buildReplyLanguageTailCue());
        // without a device the pointer is today's bytes
        $agentContext->messages = [$this->ownerMessage('Bonjour', null)];
        self::assertStringContainsString('the one marked [2026-10-14 09:12:00+02:00] above', $agentContext->buildReplyLanguageTailCue());
    }
}
