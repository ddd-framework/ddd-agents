<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Connections;

use DDD\Infrastructure\Exceptions\ConflictException;

/**
 * A level the provider has not granted yet (incremental consent): the owner consents to the additional scopes first
 * (the OAuth start with `connectionId` and the requested levels); the level takes effect once the consent succeeded.
 */
class AgentConnectionConsentRequiredException extends ConflictException
{
    /** @param string[] $missingScopes */
    public function __construct(public readonly string $capability, public readonly string $level, public readonly array $missingScopes)
    {
        parent::__construct("consent_required: $capability at $level needs the provider's consent to additional permissions first.");
    }
}
