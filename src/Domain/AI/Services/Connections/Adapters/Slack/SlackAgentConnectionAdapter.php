<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters\Slack;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessage;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatMessages;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionChatPost;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbound;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionInbounds;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSlackBotIdentity;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionSendResult;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionExpiredException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\AgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\ChatAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\InboundAgentConnectionAdapter;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use GuzzleHttp\Client as HttpClient;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Throwable;

/**
 * Slack in mode 2: the agent's own bot. Posts and threads through the Web API with the bot token (an environment
 * variable named by the connection's credentials), buttons as Block Kit actions whose value names an approval request
 * by its token, and inbound through the Events API (production: a signed HTTPS delivery, answered within 3 s) or
 * Socket Mode (local and dev: the same payloads over a WebSocket, see the Socket Mode runner).
 *
 * Text that goes out is escaped ({@see escapeText()}): `&`, `<` and `>` become entities, so model or third-party text
 * can neither notify a channel (`<!channel>`) nor spoof a link; code fences inside quoted content are neutralised
 * ({@see fenceAsCode()}). The Web API is called only on the fixed host {@see API_BASE_URL}.
 *
 * Parsing ({@see parsePayload()}) is shared by both inbound ways: an event callback (a message in a direct message, an
 * app mention, a reply in a thread the agent is in) or a block action (a button). The bot's own messages, edits and
 * other subtypes are ignored.
 */
#[Exclude]
class SlackAgentConnectionAdapter extends AgentConnectionAdapter implements ChatAgentConnectionAdapter, InboundAgentConnectionAdapter
{
    public const string PROVIDER = AgentConnection::PROVIDER_SLACK;

    public const string API_BASE_URL = 'https://slack.com/api/';

    /** @var int a delivery older than this is refused (replay protection, Slack's recommendation) */
    public const int MAXIMUM_DELIVERY_AGE_SECONDS = 300;

    protected ?HttpClient $httpClient = null;

    public function getCapabilities(): array
    {
        return [AgentConnectionPermission::CAPABILITY_CHAT];
    }

    /** A test sets a client with a mocked handler */
    public function setHttpClient(HttpClient $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    public function readChatThread(string $channelId, string $threadId, int $maximumMessages): AgentConnectionChatMessages
    {
        $answer = $this->callApi('conversations.replies', ['channel' => $channelId, 'ts' => $threadId, 'limit' => max(1, min(200, $maximumMessages))], 'GET');
        $chatMessages = new AgentConnectionChatMessages();
        foreach ($answer['messages'] ?? [] as $slackMessage) {
            $chatMessage = new AgentConnectionChatMessage();
            $chatMessage->channelId = $channelId;
            $chatMessage->threadId = $threadId;
            $chatMessage->messageId = (string)($slackMessage['ts'] ?? '');
            $chatMessage->authorExternalUserId = $slackMessage['user'] ?? null;
            $chatMessage->text = static::shorten((string)($slackMessage['text'] ?? ''), 4000);
            $chatMessage->postedAt = isset($slackMessage['ts']) && is_numeric($slackMessage['ts']) ? DateTime::fromTimestamp((int)$slackMessage['ts']) : null;
            $chatMessage->isFromAgent = isset($slackMessage['bot_id']);
            $chatMessages->add($chatMessage);
        }
        return $chatMessages;
    }

    public function postChatMessage(AgentConnectionChatPost $chatPost): AgentConnectionSendResult
    {
        $parameters = ['channel' => $chatPost->channelId, 'text' => static::escapeText($chatPost->text), 'unfurl_links' => false, 'unfurl_media' => false];
        if ($chatPost->buttons !== null && $chatPost->buttons->count() > 0) {
            $parameters['blocks'] = static::buildBlocks($chatPost);
        }
        if ($chatPost->replacesMessageId !== null) {
            $parameters['ts'] = $chatPost->replacesMessageId;
            $answer = $this->callApi('chat.update', $parameters);
        } else {
            if ($chatPost->threadId !== null) {
                $parameters['thread_ts'] = $chatPost->threadId;
            }
            $answer = $this->callApi('chat.postMessage', $parameters);
        }
        $sendResult = new AgentConnectionSendResult();
        $sendResult->externalId = (string)($answer['ts'] ?? '');
        $sendResult->channelId = (string)($answer['channel'] ?? $chatPost->channelId);
        $sendResult->threadId = $chatPost->threadId ?? $sendResult->externalId;
        return $sendResult;
    }

    public function findVerifiedEmailOfUser(string $externalUserId): ?string
    {
        $answer = $this->callApi('users.info', ['user' => $externalUserId], 'GET');
        $user = $answer['user'] ?? [];
        if (($user['deleted'] ?? false) || ($user['is_bot'] ?? false) || ($user['is_restricted'] ?? false) || ($user['is_stranger'] ?? false)) {
            return null;
        }
        $email = $user['profile']['email'] ?? null;
        return is_string($email) && $email !== '' ? mb_strtolower($email) : null;
    }

    /** The bot's identity in its workspace (auth.test), to recognise mentions and own messages */
    public function findBotIdentity(): AgentConnectionSlackBotIdentity
    {
        $answer = $this->callApi('auth.test', [], 'POST');
        $slackBotIdentity = new AgentConnectionSlackBotIdentity();
        $slackBotIdentity->botUserId = (string)($answer['user_id'] ?? '');
        $slackBotIdentity->teamId = (string)($answer['team_id'] ?? '');
        $slackBotIdentity->teamName = (string)($answer['team'] ?? '');
        $slackBotIdentity->botName = (string)($answer['user'] ?? '');
        return $slackBotIdentity;
    }

    /** Slack's escaping of message text: no mention of a channel, no link markup from the text itself */
    public static function escapeText(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }

    /** Quoted content as a code block that cannot be closed from inside (its own fences are broken up) */
    public static function fenceAsCode(string $content): string
    {
        return "```\n" . str_replace('```', "`\u{200B}`\u{200B}`", $content) . "\n```";
    }

    /** Socket Mode: a fresh WebSocket URL from the app-level token */
    public function openSocketModeUrl(): string
    {
        $appToken = AgentConnectionsConfiguration::readEnvironmentValue($this->getSettings()->appTokenEnvironmentVariable);
        if ($appToken === null || $appToken === '') {
            throw new AgentConnectionRefusedException('Socket Mode needs the Slack app-level token in this stage.');
        }
        $answer = $this->callApiWithToken('apps.connections.open', [], 'POST', $appToken);
        return (string)($answer['url'] ?? '');
    }

    public function verifyAndParseDelivery(array $headers, string $rawBody, DateTime $receivedAt): AgentConnectionInbounds
    {
        $signingSecret = AgentConnectionsConfiguration::readEnvironmentValue($this->getSettings()->signingSecretEnvironmentVariable);
        if ($signingSecret === null || $signingSecret === '') {
            throw new UnauthorizedException('Slack signing secret is not set in this stage.');
        }
        $timestamp = (string)($headers['x-slack-request-timestamp'] ?? '');
        $signature = (string)($headers['x-slack-signature'] ?? '');
        if (!static::isSignatureValid($signingSecret, $timestamp, $rawBody, $signature, $receivedAt->getTimestamp())) {
            throw new UnauthorizedException('Invalid Slack signature.');
        }
        $contentType = (string)($headers['content-type'] ?? '');
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($rawBody, $formFields);
            $payload = json_decode((string)($formFields['payload'] ?? ''), true);
        } else {
            $payload = json_decode($rawBody, true);
        }
        if (!is_array($payload)) {
            return new AgentConnectionInbounds();
        }
        return static::parsePayload($payload, (string)($headers['x-slack-retry-num'] ?? ''));
    }

    public static function isSignatureValid(string $signingSecret, string $timestamp, string $rawBody, string $signature, int $nowUnixTime): bool
    {
        if ($timestamp === '' || !ctype_digit($timestamp) || abs($nowUnixTime - (int)$timestamp) > static::MAXIMUM_DELIVERY_AGE_SECONDS) {
            return false;
        }
        $expectedSignature = 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $rawBody, $signingSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Turns one Slack payload (Events API body, Socket Mode envelope payload, or an interactivity payload) into typed
     * events. Pure, unit tested with recorded fixtures.
     *
     * @param array<string, mixed> $payload
     */
    public static function parsePayload(array $payload, string $retryNumber = ''): AgentConnectionInbounds
    {
        $inbounds = new AgentConnectionInbounds();
        $payloadType = (string)($payload['type'] ?? '');
        if ($payloadType === 'url_verification') {
            $inbound = new AgentConnectionInbound();
            $inbound->kind = AgentConnectionInbound::KIND_HANDSHAKE;
            $inbound->handshakeChallenge = (string)($payload['challenge'] ?? '');
            $inbounds->add($inbound);
            return $inbounds;
        }
        if ($payloadType === 'block_actions') {
            foreach ($payload['actions'] ?? [] as $action) {
                $actionValue = (string)($action['value'] ?? '');
                if (!preg_match('/^approval:([0-9a-f]{32}):([A-Z_]{1,20})$/', $actionValue, $matches)) {
                    continue;
                }
                // the dedup key: Slack's trigger id, else the action's own timestamp; a click without either is dropped
                $deliveryKey = (string)($payload['trigger_id'] ?? ($action['action_ts'] ?? ''));
                if ($deliveryKey === '') {
                    continue;
                }
                $inbound = new AgentConnectionInbound();
                $inbound->kind = AgentConnectionInbound::KIND_BUTTON;
                $inbound->providerEventId = 'action:' . $deliveryKey . ':' . $matches[1];
                $inbound->externalAccountId = $payload['team']['id'] ?? null;
                $inbound->channelId = $payload['channel']['id'] ?? ($payload['container']['channel_id'] ?? null);
                $inbound->messageId = $payload['container']['message_ts'] ?? ($payload['message']['ts'] ?? null);
                $inbound->threadId = $payload['message']['thread_ts'] ?? $inbound->messageId;
                $inbound->externalUserId = $payload['user']['id'] ?? null;
                $inbound->approvalToken = $matches[1];
                $inbound->optionKey = $matches[2];
                $inbounds->add($inbound);
            }
            return $inbounds;
        }
        if ($payloadType !== 'event_callback') {
            return $inbounds;
        }
        $event = $payload['event'] ?? [];
        $eventType = (string)($event['type'] ?? '');
        $inbound = new AgentConnectionInbound();
        $inbound->providerEventId = (string)($payload['event_id'] ?? '');
        $inbound->externalAccountId = $payload['team_id'] ?? null;
        $inbound->channelId = $event['channel'] ?? null;
        $inbound->messageId = $event['ts'] ?? null;
        $inbound->threadId = $event['thread_ts'] ?? ($event['ts'] ?? null);
        $inbound->externalUserId = $event['user'] ?? null;
        $inbound->text = (string)($event['text'] ?? '');
        if (isset($event['bot_id']) || (isset($event['subtype']) && $event['subtype'] !== 'thread_broadcast') || $inbound->externalUserId === null) {
            $inbound->kind = AgentConnectionInbound::KIND_IGNORED;
            $inbounds->add($inbound);
            return $inbounds;
        }
        if ($eventType === 'app_mention') {
            $inbound->kind = AgentConnectionInbound::KIND_MENTION;
        } elseif ($eventType === 'message' && ($event['channel_type'] ?? '') === 'im') {
            $inbound->kind = AgentConnectionInbound::KIND_DIRECT_MESSAGE;
        } elseif ($eventType === 'message' && isset($event['thread_ts'])) {
            // a reply in a thread; the core decides whether the thread belongs to a conversation
            $inbound->kind = AgentConnectionInbound::KIND_MESSAGE;
        } else {
            $inbound->kind = AgentConnectionInbound::KIND_IGNORED;
        }
        // the mention of the bot itself is no content
        $inbound->text = trim((string)preg_replace('/<@[A-Z0-9]+>/', '', $inbound->text));
        $inbounds->add($inbound);
        return $inbounds;
    }

    /** @return array<int, array<string, mixed>> Block Kit: the text and one actions block with the buttons */
    public static function buildBlocks(AgentConnectionChatPost $chatPost): array
    {
        $elements = [];
        foreach ($chatPost->buttons?->getElements() ?? [] as $chatButton) {
            $element = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => mb_substr($chatButton->label, 0, 75)],
                'value' => $chatButton->actionValue,
                'action_id' => substr(hash('sha256', $chatButton->actionValue), 0, 32),
            ];
            if (in_array($chatButton->style, ['primary', 'danger'], true)) {
                $element['style'] = $chatButton->style;
            }
            $elements[] = $element;
        }
        return [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => mb_substr(static::escapeText($chatPost->text), 0, 3000)]],
            ['type' => 'actions', 'elements' => $elements],
        ];
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    protected function callApi(string $method, array $parameters, string $httpMethod = 'POST'): array
    {
        $botToken = (string)$this->readSecrets()->get('token');
        return $this->callApiWithToken($method, $parameters, $httpMethod, $botToken);
    }

    /** @param array<string, mixed> $parameters @return array<string, mixed> */
    protected function callApiWithToken(string $method, array $parameters, string $httpMethod, string $token): array
    {
        $httpClient = $this->httpClient ?? new HttpClient(['timeout' => 10]);
        try {
            $options = ['headers' => ['Authorization' => 'Bearer ' . $token]];
            if ($httpMethod === 'GET') {
                $options['query'] = $parameters;
            } else {
                $options['json'] = $parameters;
            }
            $response = $httpClient->request($httpMethod, static::API_BASE_URL . $method, $options);
            $answer = json_decode((string)$response->getBody(), true);
        } catch (Throwable $requestFailure) {
            throw new AgentConnectionProviderException("Slack $method failed: " . mb_substr($requestFailure->getMessage(), 0, 200), 0, $requestFailure);
        }
        if (!is_array($answer) || !($answer['ok'] ?? false)) {
            $error = (string)($answer['error'] ?? 'no answer');
            if (in_array($error, ['invalid_auth', 'account_inactive', 'token_revoked', 'not_authed'], true)) {
                throw new AgentConnectionExpiredException("Slack refused the token ($error).");
            }
            throw new AgentConnectionProviderException("Slack $method answered $error.");
        }
        return $answer;
    }
}
