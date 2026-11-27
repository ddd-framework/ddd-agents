<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Connections\Adapters;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a completed OAuth consent returned: tokens (never shown) and the identity of the account.
 */
class AgentConnectionOAuthGrant extends ValueObject
{
    public string $accessToken = '';
    public ?string $refreshToken = null;
    public ?int $expiresAtUnixTime = null;
    /** @var string[] */
    public array $grantedScopes = [];
    public string $externalAccountId = '';
    public ?string $emailAddress = null;
    public ?string $displayName = null;
    /** @var string|null The Workspace domain (Google `hd`) */
    public ?string $hostedDomain = null;
}
