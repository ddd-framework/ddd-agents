<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections\Adapters;

use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionOAuthGrant;
use DDD\Domain\AI\Services\Connections\AgentConnectionSecretPayload;

/** The OAuth connect flow of a provider (mode 1). */
interface OAuthAgentConnectionAdapter
{
    /**
     * The consent URL for exactly these scopes (the identity scopes are added by the adapter). Scopes granted earlier
     * stay granted (incremental consent).
     *
     * @param string[] $scopes {@see getScopesFor()} of the levels the connect asks for
     * @param string $codeChallenge the PKCE challenge (S256) of the verifier the connect start keeps
     */
    public function buildAuthorizationUrl(string $state, string $redirectUri, array $scopes, ?string $loginHint, string $codeChallenge): string;

    /** @param string $codeVerifier the PKCE verifier of the connect start */
    public function exchangeAuthorizationCode(string $authorizationCode, string $redirectUri, string $codeVerifier): AgentConnectionOAuthGrant;

    /**
     * A fresh access token from the refresh token.
     *
     * @throws \DDD\Domain\AI\Exceptions\Connections\AgentConnectionExpiredException the provider refused (revoked)
     * @throws \DDD\Domain\AI\Exceptions\Connections\AgentConnectionProviderException a transient failure
     */
    public function refreshAccessToken(AgentConnectionSecretPayload $secretPayload): AgentConnectionOAuthGrant;

    /** Revokes the grant at the provider (disconnect); a failure is reported, the local delete happens anyway */
    public function revokeGrant(AgentConnectionSecretPayload $secretPayload): void;

    /** @return array<string, string[]> capability => every scope it may need (at its highest level) */
    public function getScopesByCapability(): array;

    /**
     * The scopes a capability needs at a level (everything below included); OFF needs none.
     *
     * @return string[]
     */
    public function getScopesFor(string $capability, string $level): array;

    /**
     * The scopes of {@see getScopesFor()} the grant does not cover; a broader granted scope covers a narrower one.
     *
     * @param string[] $grantedScopes
     * @return string[] empty when the grant covers the level
     */
    public function findMissingScopes(array $grantedScopes, string $capability, string $level): array;

    /** Whether the adapter's client id and secret are set in this stage */
    public function isConfigured(): bool;
}
