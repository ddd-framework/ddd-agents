<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One inbound event an adapter verified and parsed: a Slack message or mention, a button, a new mail. Its text is written by others and stays data until the core decides who speaks.
 */
class AgentConnectionInbound extends ValueObject
{
    public const string KIND_MESSAGE = 'MESSAGE';
    public const string KIND_MENTION = 'MENTION';
    public const string KIND_DIRECT_MESSAGE = 'DIRECT_MESSAGE';
    public const string KIND_BUTTON = 'BUTTON';
    public const string KIND_NEW_MAIL = 'NEW_MAIL';
    /** @var string A provider handshake (Slack url_verification): answered by the controller, nothing else happens */
    public const string KIND_HANDSHAKE = 'HANDSHAKE';
    /** @var string Nothing the core handles (a bot's own message, an edit) */
    public const string KIND_IGNORED = 'IGNORED';

    public string $kind = self::KIND_IGNORED;
    /** @var string The provider's id of the delivery, for the dedup */
    public string $providerEventId = '';
    /** @var string|null The provider's id of the account the event belongs to (Slack team id, the mailbox) */
    public ?string $externalAccountId = null;
    public ?string $channelId = null;
    /** @var string|null The thread the event belongs to; a new top-level message opens its own (its message id) */
    public ?string $threadId = null;
    public ?string $messageId = null;
    public ?string $externalUserId = null;
    public ?string $senderAddress = null;
    public ?string $subject = null;
    public string $text = '';
    /** @var string|null KIND_BUTTON: the approval token */
    public ?string $approvalToken = null;
    /** @var string|null KIND_BUTTON: the option chosen */
    public ?string $optionKey = null;
    /** @var string|null KIND_HANDSHAKE: what the provider wants echoed */
    public ?string $handshakeChallenge = null;
}
