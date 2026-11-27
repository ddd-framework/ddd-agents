<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The start of an OAuth connect: the pending connection, the consent URL the browser opens, and the browser nonce
 * that the starting browser keeps as an HttpOnly cookie. Only the browser that started the connect can complete it
 * (the callback compares the cookie with the hash in the signed state), so a consent link sent to someone else
 * links nothing.
 */
class AgentConnectionOAuthStart extends ValueObject
{
    public AgentConnection $agentConnection;
    public string $authorizationUrl = '';
    /** @var string never logged, never serialized into a response body; goes only into the cookie */
    public string $browserNonce = '';

    public function __debugInfo(): array
    {
        return ['agentConnectionId' => $this->agentConnection->id ?? null, 'authorizationUrl' => $this->authorizationUrl];
    }
}
