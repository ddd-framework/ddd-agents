<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Connections;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Slack inbound: the v0 signature with its time window and the payload fixtures of the events the agent reacts to. */
class SlackAgentConnectionAdapterTest extends UnitTestCase
{
    protected const string SECRET = 'test-signing-secret';

    public function testAValidSignatureInsideTheWindowPasses(): void
    {
        $body = '{"type":"event_callback"}';
        $signature = 'v0=' . hash_hmac('sha256', 'v0:1000:' . $body, self::SECRET);
        self::assertTrue(SlackAgentConnectionAdapter::isSignatureValid(self::SECRET, '1000', $body, $signature, 1100));
    }

    public function testATamperedBodyOrAnOldDeliveryFails(): void
    {
        $body = '{"type":"event_callback"}';
        $signature = 'v0=' . hash_hmac('sha256', 'v0:1000:' . $body, self::SECRET);
        self::assertFalse(SlackAgentConnectionAdapter::isSignatureValid(self::SECRET, '1000', $body . ' ', $signature, 1100));
        self::assertFalse(SlackAgentConnectionAdapter::isSignatureValid(self::SECRET, '1000', $body, $signature, 1000 + 301));
        self::assertFalse(SlackAgentConnectionAdapter::isSignatureValid(self::SECRET, 'abc', $body, $signature, 1000));
    }

    public function testTheHandshakeIsAnswered(): void
    {
        $inbounds = SlackAgentConnectionAdapter::parsePayload(['type' => 'url_verification', 'challenge' => 'xyz']);
        self::assertSame(AgentConnectionInbound::KIND_HANDSHAKE, $inbounds[0]->kind);
        self::assertSame('xyz', $inbounds[0]->handshakeChallenge);
    }

    public function testAMentionLosesTheBotMention(): void
    {
        $inbounds = SlackAgentConnectionAdapter::parsePayload(['type' => 'event_callback', 'event_id' => 'Ev1', 'team_id' => 'T1', 'event' => [
            'type' => 'app_mention', 'user' => 'U1', 'channel' => 'C1', 'ts' => '1.1', 'text' => '<@UBOT> how many orders?',
        ]]);
        self::assertSame(AgentConnectionInbound::KIND_MENTION, $inbounds[0]->kind);
        self::assertSame('how many orders?', $inbounds[0]->text);
        self::assertSame('1.1', $inbounds[0]->threadId);
    }

    public function testADirectMessageAndAThreadReplyAreRecognised(): void
    {
        $directMessage = SlackAgentConnectionAdapter::parsePayload(['type' => 'event_callback', 'event_id' => 'Ev2', 'event' => [
            'type' => 'message', 'channel_type' => 'im', 'user' => 'U1', 'channel' => 'D1', 'ts' => '2.2', 'text' => 'hi',
        ]]);
        self::assertSame(AgentConnectionInbound::KIND_DIRECT_MESSAGE, $directMessage[0]->kind);
        $threadReply = SlackAgentConnectionAdapter::parsePayload(['type' => 'event_callback', 'event_id' => 'Ev3', 'event' => [
            'type' => 'message', 'channel_type' => 'channel', 'user' => 'U1', 'channel' => 'C1', 'ts' => '3.3', 'thread_ts' => '1.1', 'text' => 'und?',
        ]]);
        self::assertSame(AgentConnectionInbound::KIND_MESSAGE, $threadReply[0]->kind);
        self::assertSame('1.1', $threadReply[0]->threadId);
    }

    public function testBotMessagesAndEditsAreIgnored(): void
    {
        foreach ([['bot_id' => 'B1', 'user' => 'U1'], ['subtype' => 'message_changed', 'user' => 'U1'], []] as $extra) {
            $inbounds = SlackAgentConnectionAdapter::parsePayload(['type' => 'event_callback', 'event_id' => 'Ev4', 'event' => [
                'type' => 'message', 'channel_type' => 'im', 'channel' => 'D1', 'ts' => '4.4', 'text' => 'x',
            ] + $extra]);
            self::assertSame(AgentConnectionInbound::KIND_IGNORED, $inbounds[0]->kind);
        }
    }

    public function testOutgoingTextCannotNotifyOrSpoofLinksAndQuotedContentCannotCloseItsFence(): void
    {
        self::assertSame('&lt;!channel&gt; &amp; &lt;https://evil.example|bank&gt;', SlackAgentConnectionAdapter::escapeText('<!channel> & <https://evil.example|bank>'));
        $fenced = SlackAgentConnectionAdapter::fenceAsCode("text\n```\n*now bold outside*");
        self::assertStringStartsWith("```\n", $fenced);
        self::assertStringEndsWith("\n```", $fenced);
        self::assertSame(2, substr_count($fenced, '```'));
    }

    public function testAButtonWithoutADeliveryKeyIsDropped(): void
    {
        $token = str_repeat('cd', 16);
        $inbounds = SlackAgentConnectionAdapter::parsePayload(['type' => 'block_actions', 'user' => ['id' => 'U1'], 'actions' => [['value' => "approval:$token:APPROVE"]]]);
        self::assertCount(0, $inbounds);
    }

    public function testAnApprovalButtonCarriesTokenAndOption(): void
    {
        $token = str_repeat('ab', 16);
        $inbounds = SlackAgentConnectionAdapter::parsePayload(['type' => 'block_actions', 'trigger_id' => 'tr1', 'user' => ['id' => 'U1'],
            'channel' => ['id' => 'C9'], 'container' => ['message_ts' => '5.5'],
            'actions' => [['value' => "approval:$token:APPROVE"], ['value' => 'something-else']]]);
        self::assertCount(1, $inbounds);
        self::assertSame(AgentConnectionInbound::KIND_BUTTON, $inbounds[0]->kind);
        self::assertSame($token, $inbounds[0]->approvalToken);
        self::assertSame('APPROVE', $inbounds[0]->optionKey);
    }
}
