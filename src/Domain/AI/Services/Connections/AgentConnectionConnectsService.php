<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnectionOAuthStart;
use DDD\Infrastructure\Libs\Encrypt;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionImapCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionLevelRequests;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddress;
use DDD\Domain\AI\Entities\Connections\AgentConnectionCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\Imap\ImapSmtpAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\OAuthAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\Adapters\Slack\SlackAgentConnectionAdapter;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Infrastructure\Services\DDDService;

/**
 * Connecting: a connection is never created by a tool, always by a person completing a consent or entering
 * credentials. Three ways:
 * - OAuth (mode 1, or mode 2 when an account with the system right connects the agent's own Google identity or a
 *   team mailbox): {@see startOAuthConnect()} creates the IN_PROGRESS row and the consent URL with a signed, short-lived
 *   state; {@see completeOAuthConnect()} checks the state, exchanges the code, stores the tokens encrypted and sets the
 *   levels the scopes cover (READ). A reconnect of the same external account updates its row.
 * - IMAP/SMTP: {@see connectImapMailbox()} checks the login on both servers before anything is stored.
 * - Slack bot (mode 2): {@see connectSlackBot()} reads the bot's identity with the token of the environment; the row
 *   holds only the variable's name.
 */
class AgentConnectionConnectsService
{
    public const string STATE_PURPOSE = 'agentConnectionOAuth';
    public const int STATE_VALIDITY_SECONDS = 900;
    public const string CALLBACK_PATH = '/api/admin/agent-connections/oauth/%s/callback';

    public function buildRedirectUri(string $provider): string
    {
        $publicBaseUrl = AgentConnectionsConfiguration::current()->getPublicBaseUrl();
        if ($publicBaseUrl === '') {
            throw new AgentConnectionRefusedException('The public base URL of this installation is not configured.');
        }
        return $publicBaseUrl . sprintf(static::CALLBACK_PATH, strtolower($provider));
    }

    /**
     * `$visibility` AGENT_SPACE (an account's own connection only) shares the connection with `$targetAgentSpaceId`
     * (without one, the only offered space) once the consent completes; the target is checked here, before the
     * consent starts. OWNER_ONLY or null keeps it with its owner.
     *
     * @param string[] $capabilities
     * @return AgentConnectionOAuthStart the pending row, the consent URL and the browser nonce for the cookie
     * @throws AgentConnectionRefusedException an unknown or unconfigured provider, a space the account may not share with
     */
    public function startOAuthConnect(string $provider, string $ownership, int $accountId, array $capabilities, ?string $displayName, ?string $loginHint, ?int $agentSpaceId = null, ?string $visibility = null, ?int $targetAgentSpaceId = null): AgentConnectionOAuthStart
    {
        $oAuthAdapter = $this->getAdaptersService()->getAdapter($provider);
        if (!$oAuthAdapter instanceof OAuthAgentConnectionAdapter) {
            throw new AgentConnectionRefusedException("The provider $provider has no consent screen.");
        }
        if (!$oAuthAdapter->isConfigured()) {
            throw new AgentConnectionRefusedException("The provider $provider is not configured in this stage.");
        }
        $capabilities = $capabilities !== [] ? array_values(array_intersect($capabilities, array_keys($oAuthAdapter->getScopesByCapability()))) : array_keys($oAuthAdapter->getScopesByCapability());
        $this->requireOwningSpace($ownership, $agentSpaceId);
        $shareTargetAgentSpaceId = null;
        if ($visibility !== null && $visibility !== AgentConnectionsService::VISIBILITY_OWNER_ONLY) {
            if ($visibility !== AgentConnectionsService::VISIBILITY_AGENT_SPACE || $ownership !== AgentConnection::OWNERSHIP_ACCOUNT_GRANTED) {
                throw new AgentConnectionRefusedException('Only an account\'s own connection takes a visibility at the connect.');
            }
            $plannedConnection = AgentConnection::newInstance();
            $plannedConnection->ownership = $ownership;
            $plannedConnection->provider = $provider;
            $plannedConnection->accountId = $accountId;
            $shareTargetAgentSpaceId = $this->getAgentConnectionsService()->resolveShareTarget($plannedConnection, $accountId, $targetAgentSpaceId);
        }
        $agentConnection = $this->getAgentConnectionsService()->createPendingConnection($ownership, $provider, $accountId, $accountId, $displayName, $agentSpaceId);
        // PKCE: the verifier stays on the server (encrypted in the signed state); the browser nonce binds the
        // state to the starting browser (HttpOnly cookie, compared at the callback)
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $browserNonce = bin2hex(random_bytes(32));
        $state = JWTPayload::createJWTFromParameters([
            'purpose' => static::STATE_PURPOSE,
            'agentConnectionId' => (int)$agentConnection->id,
            'accountId' => $accountId,
            'provider' => $provider,
            'stage' => AgentConnectionsConfiguration::current()->getCurrentStage(),
            'browserNonceHash' => hash('sha256', $browserNonce),
            'encryptedCodeVerifier' => (string)Encrypt::encrypt($codeVerifier, $this->getAgentConnectionCredentialSetsService()->requireEncryptionKey()),
            'shareTargetAgentSpaceId' => $shareTargetAgentSpaceId,
        ], static::STATE_VALIDITY_SECONDS);
        $agentConnectionOAuthStart = AgentConnectionOAuthStart::newInstance();
        $agentConnectionOAuthStart->agentConnection = $agentConnection;
        // incremental consent: the connect asks only for what reading needs; a higher level asks for its scopes then
        $readScopes = [];
        foreach ($capabilities as $capability) {
            $readScopes = [...$readScopes, ...$oAuthAdapter->getScopesFor($capability, AgentConnectionPermission::LEVEL_READ)];
        }
        $agentConnectionOAuthStart->authorizationUrl = $oAuthAdapter->buildAuthorizationUrl($state, $this->buildRedirectUri($provider), $readScopes, $loginHint, $codeChallenge);
        $agentConnectionOAuthStart->browserNonce = $browserNonce;
        return $agentConnectionOAuthStart;
    }

    /**
     * Asks the provider for the additional scopes of higher levels (or new capabilities) of an existing connection
     * (incremental consent). The levels take effect at the completion, and only those the grant covers; until then
     * the connection keeps working at its current levels.
     *
     * @throws AgentConnectionRefusedException a provider without a consent screen, an unknown capability or level
     */
    public function startOAuthReconsent(AgentConnection $agentConnection, int $accountId, AgentConnectionLevelRequests $levelRequests): AgentConnectionOAuthStart
    {
        $provider = (string)$agentConnection->provider;
        $oAuthAdapter = $this->getAdaptersService()->getAdapter($provider);
        if (!$oAuthAdapter instanceof OAuthAgentConnectionAdapter || !$oAuthAdapter->isConfigured()) {
            throw new AgentConnectionRefusedException("The provider $provider has no consent screen in this stage.");
        }
        $requestedScopes = [];
        foreach ($levelRequests->getElements() as $levelRequest) {
            if (!in_array($levelRequest->level, AgentConnectionPermission::LEVELS, true) || !array_key_exists($levelRequest->capability, $oAuthAdapter->getScopesByCapability())) {
                throw new AgentConnectionRefusedException("Unknown capability or level: {$levelRequest->capability} {$levelRequest->level}.");
            }
            $requestedScopes = [...$requestedScopes, ...$oAuthAdapter->getScopesFor($levelRequest->capability, $levelRequest->level)];
        }
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $browserNonce = bin2hex(random_bytes(32));
        $state = JWTPayload::createJWTFromParameters([
            'purpose' => static::STATE_PURPOSE,
            'agentConnectionId' => (int)$agentConnection->id,
            'accountId' => $accountId,
            'provider' => $provider,
            'stage' => AgentConnectionsConfiguration::current()->getCurrentStage(),
            'browserNonceHash' => hash('sha256', $browserNonce),
            'encryptedCodeVerifier' => (string)Encrypt::encrypt($codeVerifier, $this->getAgentConnectionCredentialSetsService()->requireEncryptionKey()),
            'reconsent' => true,
            'requestedLevels' => $levelRequests->toLevelsByCapability(),
        ], static::STATE_VALIDITY_SECONDS);
        $agentConnectionOAuthStart = AgentConnectionOAuthStart::newInstance();
        $agentConnectionOAuthStart->agentConnection = $agentConnection;
        // the scopes granted before stay granted (include_granted_scopes); the login hint keeps the same account
        $agentConnectionOAuthStart->authorizationUrl = $oAuthAdapter->buildAuthorizationUrl($state, $this->buildRedirectUri($provider), array_values(array_unique($requestedScopes)), $agentConnection->externalAddress, $codeChallenge);
        $agentConnectionOAuthStart->browserNonce = $browserNonce;
        return $agentConnectionOAuthStart;
    }

    /**
     * Completes a consent. The signed state proves which pending row and account it belongs to, and the browser nonce
     * (cookie of the starting browser) proves that the browser that returns is the one that started: a consent link
     * opened by anyone else is refused. The PKCE verifier of the start completes the code exchange.
     *
     * @throws AgentConnectionRefusedException an invalid or expired state, a refused code, a foreign domain
     */
    public function completeOAuthConnect(string $provider, string $state, string $authorizationCode, ?string $browserNonce): AgentConnection
    {
        $stateParameters = JWTPayload::getParametersFromJWT($state);
        if (!is_array($stateParameters) || ($stateParameters['purpose'] ?? null) !== static::STATE_PURPOSE || ($stateParameters['provider'] ?? null) !== $provider
            || ($stateParameters['stage'] ?? null) !== AgentConnectionsConfiguration::current()->getCurrentStage()) {
            throw new AgentConnectionRefusedException('The connect link is invalid or expired. Start the connect again.');
        }
        if ($browserNonce === null || $browserNonce === '' || !hash_equals((string)($stateParameters['browserNonceHash'] ?? ''), hash('sha256', $browserNonce))) {
            throw new AgentConnectionRefusedException('This connect was started in another browser. Start it again here.');
        }
        $codeVerifier = Encrypt::decrypt((string)($stateParameters['encryptedCodeVerifier'] ?? ''), $this->getAgentConnectionCredentialSetsService()->requireEncryptionKey());
        if (!is_string($codeVerifier) || $codeVerifier === '') {
            throw new AgentConnectionRefusedException('The connect link is invalid or expired. Start the connect again.');
        }
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionsService->findIgnoringRights((int)$stateParameters['agentConnectionId']);
        $isReconsent = ($stateParameters['reconsent'] ?? false) === true;
        $expectedStatusMet = $agentConnection instanceof AgentConnection
            && ($isReconsent ? $agentConnection->status !== AgentConnection::STATUS_REVOKED : $agentConnection->status === AgentConnection::STATUS_IN_PROGRESS);
        if (!$expectedStatusMet) {
            throw new AgentConnectionRefusedException('This connect was already completed or cancelled. Start it again.');
        }
        $accountId = (int)$stateParameters['accountId'];
        /** @var OAuthAgentConnectionAdapter $oAuthAdapter */
        $oAuthAdapter = $this->getAdaptersService()->getAdapter($provider);
        $oAuthGrant = $oAuthAdapter->exchangeAuthorizationCode($authorizationCode, $this->buildRedirectUri($provider), $codeVerifier);
        if ($isReconsent && $agentConnection->externalAccountId !== $oAuthGrant->externalAccountId) {
            throw new AgentConnectionRefusedException('The consent was given with another account than the one of "' . $agentConnection->getLabel() . '". Nothing was changed; consent again with that account.');
        }

        // a reconnect of the same external account by the same owner (a system connection: in the same space)
        // continues its existing row (levels, name, audit)
        foreach ($isReconsent ? [] : $agentConnectionsService->findOfStage($agentConnection->isAgentOwned() ? null : $accountId, $agentConnection->isAgentOwned(), $provider)->getElements() as $existingConnection) {
            if ((int)$existingConnection->id !== (int)$agentConnection->id && $existingConnection->externalAccountId === $oAuthGrant->externalAccountId
                && $existingConnection->ownership === $agentConnection->ownership
                && (!$agentConnection->isAgentOwned() || (int)$existingConnection->agentSpaceId === (int)$agentConnection->agentSpaceId)) {
                $agentConnection->status = AgentConnection::STATUS_REVOKED;
                $agentConnection->lastErrorText = 'merged into connection ' . $existingConnection->id;
                $agentConnectionsService->persistIgnoringRights($agentConnection);
                $agentConnection = $existingConnection;
                break;
            }
        }
        $secretPayload = new AgentConnectionSecretPayload(['accessToken' => $oAuthGrant->accessToken, 'refreshToken' => $oAuthGrant->refreshToken]);
        if ($oAuthGrant->refreshToken === null) {
            // a reconnect without a new refresh token keeps the stored one
            try {
                $previousSecrets = $this->getCredentialSetsService()->readSecrets($agentConnection);
                $secretPayload = $secretPayload->with('refreshToken', $previousSecrets->get('refreshToken'));
            } catch (AgentConnectionRefusedException) {
                throw new AgentConnectionRefusedException('The provider sent no refresh token. Remove the app\'s access in your account settings at the provider and connect again.');
            }
        }
        $this->getCredentialSetsService()->storeSecrets($agentConnection, AgentConnectionCredentials::TYPE_OAUTH, $secretPayload, $oAuthGrant->expiresAtUnixTime);
        $agentConnection->externalAccountId = $oAuthGrant->externalAccountId;
        $agentConnection->externalAddress = $oAuthGrant->emailAddress ?? $oAuthGrant->displayName;
        $agentConnection->displayName ??= $oAuthGrant->emailAddress;
        $agentConnection->grantedScopes = implode(' ', $oAuthGrant->grantedScopes);
        $agentConnection->status = AgentConnection::STATUS_CONNECTED;
        $agentConnection->consecutiveErrorCount = 0;
        $agentConnection->lastErrorText = null;
        $agentConnectionsService->persistIgnoringRights($agentConnection);

        // a capability is offered when the grant covers reading it (the owner may have unticked a scope)
        $coveredCapabilities = [];
        foreach (array_keys($oAuthAdapter->getScopesByCapability()) as $capability) {
            if ($oAuthAdapter->findMissingScopes($oAuthGrant->grantedScopes, $capability, AgentConnectionPermission::LEVEL_READ) === []) {
                $coveredCapabilities[] = $capability;
            }
        }
        $agentConnectionsService->ensurePermissions($agentConnection, $coveredCapabilities, $accountId);
        $this->releaseSystemConnectionToItsSpace($agentConnection, $accountId);
        $this->getActionsService()->recordAdministration($agentConnection, $isReconsent ? AgentConnectionAction::OPERATION_SETTINGS : AgentConnectionAction::OPERATION_CONNECT, $accountId, ($isReconsent ? 'consented ' : 'connected ') . implode(',', $coveredCapabilities));
        $notGrantedLevels = [];
        foreach ((array)($stateParameters['requestedLevels'] ?? []) as $capability => $level) {
            if ($agentConnectionsService->findMissingScopes($agentConnection, (string)$capability, (string)$level) !== []) {
                $notGrantedLevels[] = "$capability $level";
                continue;
            }
            $agentConnectionsService->setLevel($agentConnection, (string)$capability, (string)$level, $accountId);
        }
        if ($notGrantedLevels !== []) {
            throw new AgentConnectionRefusedException('The provider did not grant everything for ' . implode(', ', $notGrantedLevels) . ' (a permission was unticked). The other levels are set; consent again to raise these.');
        }
        $shareTargetAgentSpaceId = $stateParameters['shareTargetAgentSpaceId'] ?? null;
        if (is_int($shareTargetAgentSpaceId)) {
            try {
                $agentConnectionsService->setVisibility($agentConnection, AgentConnectionsService::VISIBILITY_AGENT_SPACE, $accountId, $shareTargetAgentSpaceId);
            } catch (AgentConnectionRefusedException $refused) {
                // the connection stays with its owner; the page names why it was not shared
                throw new AgentConnectionRefusedException('Connected as "' . $agentConnection->getLabel() . '", but not shared: ' . $refused->getMessage() . ' Share it in the connection settings.');
            }
        }
        return $agentConnection;
    }

    /**
     * An IMAP/SMTP mailbox; the login on both servers is checked first (public hosts and the standard ports only).
     */
    public function connectImapMailbox(string $ownership, int $accountId, AgentConnectionImapCredentials $imapCredentials, ?string $displayName, ?int $agentSpaceId = null): AgentConnection
    {
        $this->getAdaptersService()->getAdapter(AgentConnection::PROVIDER_IMAP);
        $this->requireOwningSpace($ownership, $agentSpaceId);
        ImapSmtpAgentConnectionAdapter::verifyCredentials($imapCredentials);
        $fromAddress = mb_strtolower(trim($imapCredentials->fromAddress));
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionsService->createPendingConnection($ownership, AgentConnection::PROVIDER_IMAP, $accountId, $accountId, $displayName ?? $fromAddress, $agentSpaceId);
        $this->getCredentialSetsService()->storeSecrets($agentConnection, AgentConnectionCredentials::TYPE_PASSWORD, new AgentConnectionSecretPayload($imapCredentials->toSecretValues()));
        $agentConnection->externalAccountId = $fromAddress;
        $agentConnection->externalAddress = $fromAddress;
        $agentConnection->status = AgentConnection::STATUS_CONNECTED;
        $agentConnectionsService->persistIgnoringRights($agentConnection);
        $agentConnectionsService->ensurePermissions($agentConnection, [AgentConnectionPermission::CAPABILITY_MAIL], $accountId);
        $this->releaseSystemConnectionToItsSpace($agentConnection, $accountId);
        $this->getActionsService()->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_CONNECT, $accountId, 'connected IMAP/SMTP');
        return $agentConnection;
    }

    /** The agent's Slack bot as a system connection; the token stays in the environment */
    public function connectSlackBot(int $accountId, ?string $displayName, int $agentSpaceId): AgentConnection
    {
        $slackSettings = AgentConnectionsConfiguration::current()->getAdapterSettings(AgentConnection::PROVIDER_SLACK);
        $botTokenVariable = (string)($slackSettings->botTokenEnvironmentVariable);
        $agentConnectionsService = $this->getAgentConnectionsService();
        $agentConnection = $agentConnectionsService->createPendingConnection(AgentConnection::OWNERSHIP_AGENT_OWNED, AgentConnection::PROVIDER_SLACK, null, $accountId, $displayName, $agentSpaceId);
        $this->getCredentialSetsService()->storeSecrets($agentConnection, AgentConnectionCredentials::TYPE_ENVIRONMENT_REFERENCE, new AgentConnectionSecretPayload([]), null, $botTokenVariable);
        /** @var SlackAgentConnectionAdapter $slackAdapter */
        $slackAdapter = $this->getAdaptersService()->getAdapterOfConnection($agentConnection);
        $botIdentity = $slackAdapter->findBotIdentity();
        $agentConnection->externalAccountId = $botIdentity->teamId;
        $agentConnection->externalAddress = $botIdentity->teamName . ' (bot ' . $botIdentity->botUserId . ')';
        $agentConnection->displayName ??= 'Slack ' . $botIdentity->teamName;
        $agentConnection->status = AgentConnection::STATUS_CONNECTED;
        $agentConnectionsService->persistIgnoringRights($agentConnection);
        $agentConnectionsService->ensurePermissions($agentConnection, [AgentConnectionPermission::CAPABILITY_CHAT], $accountId, AgentConnectionPermission::LEVEL_SEND_WITH_APPROVAL);
        $this->releaseSystemConnectionToItsSpace($agentConnection, $accountId);
        $this->getActionsService()->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_CONNECT, $accountId, 'connected Slack bot ' . $botIdentity->botUserId);
        return $agentConnection;
    }

    /** A system connection is released to its owning space at once, with the levels it has */
    protected function releaseSystemConnectionToItsSpace(AgentConnection $agentConnection, int $accountId): void
    {
        if (!$agentConnection->isAgentOwned() || $agentConnection->agentSpaceId === null) {
            return;
        }
        $this->getAgentConnectionsService()->ensureOwningSpaceRelease($agentConnection, $accountId);
    }

    /** A system connection needs its owning space; null is account level, never "the host" */
    protected function requireOwningSpace(string $ownership, ?int $agentSpaceId): void
    {
        if ($ownership === AgentConnection::OWNERSHIP_AGENT_OWNED && $agentSpaceId === null) {
            throw new AgentConnectionRefusedException('A system connection needs the agent space that owns it.');
        }
    }

    /** Adds or changes a channel of a system chat connection (its behaviour and what the agent posts there) */
    public function upsertAddress(AgentConnection $agentConnection, string $externalAddress, ?string $displayName, string $behaviour, string $postingUse, string $audience, ?string $requiredAccountRight, ?string $purposeName): AgentConnectionAddress
    {
        $agentConnectionAddress = null;
        foreach ($agentConnection->addresses?->getElements() ?? [] as $existingAddress) {
            if ($existingAddress->externalAddress === $externalAddress) {
                $agentConnectionAddress = $existingAddress;
            }
        }
        $agentConnectionAddress ??= AgentConnectionAddress::newInstance();
        $agentConnectionAddress->agentConnectionId = (int)$agentConnection->id;
        $agentConnectionAddress->externalAddress = $externalAddress;
        $agentConnectionAddress->displayName = $displayName;
        $agentConnectionAddress->role = $agentConnection->isAgentOwned() ? AgentConnectionAddress::ROLE_AGENT_OWN_IDENTITY : AgentConnectionAddress::ROLE_OPERATED_ON_BEHALF;
        $agentConnectionAddress->behaviour = $behaviour;
        $agentConnectionAddress->postingUse = $postingUse;
        $agentConnectionAddress->audience = $audience;
        $agentConnectionAddress->requiredAccountRight = $requiredAccountRight;
        $agentConnectionAddress->purposeName = $purposeName;
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionAddress->update();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $agentConnectionAddress;
    }

    protected function getAgentConnectionsService(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }

    protected function getAdaptersService(): AgentConnectionAdaptersService
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        return $agentConnectionAdaptersService;
    }

    protected function getCredentialSetsService(): AgentConnectionCredentialSetsService
    {
        /** @var AgentConnectionCredentialSetsService $agentConnectionCredentialSetsService */
        $agentConnectionCredentialSetsService = DDDService::instance()->getService(AgentConnectionCredentialSetsService::class);
        return $agentConnectionCredentialSetsService;
    }

    protected function getActionsService(): AgentConnectionActionsService
    {
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        return $agentConnectionActionsService;
    }

    protected function getAgentConnectionCredentialSetsService(): AgentConnectionCredentialSetsService
    {
        /** @var AgentConnectionCredentialSetsService $agentConnectionCredentialSetsService */
        $agentConnectionCredentialSetsService = DDDService::instance()->getService(AgentConnectionCredentialSetsService::class);
        return $agentConnectionCredentialSetsService;
    }
}
