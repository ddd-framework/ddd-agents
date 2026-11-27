<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\AI\Services\Connections\AgentConnectionReach;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsAccountRights;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Symfony\Component\HttpFoundation\Cookie;
use DDD\Domain\AI\Entities\Connections\Adapters\AgentConnectionImapCredentials;
use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionOAuthStart;
use DDD\Domain\AI\Entities\Connections\AgentConnectionLevelRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionLevelRequests;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLink;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAccountLinks;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAction;
use DDD\Domain\AI\Entities\Connections\AgentConnectionAddresses;
use DDD\Domain\AI\Entities\Connections\AgentConnectionApprovalRequest;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermission;
use DDD\Domain\AI\Entities\Connections\AgentConnectionPermissions;
use DDD\Domain\AI\Entities\Connections\AgentConnectionReleases;
use DDD\Domain\AI\Entities\Connections\AgentConnectionsConfiguration;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\OAuthAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionActionsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionAdaptersService;
use DDD\Domain\AI\Services\Connections\AgentConnectionApprovalRequestsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionConnectsService;
use DDD\Domain\AI\Services\Connections\AgentConnectionInboundService;
use DDD\Domain\AI\Services\Connections\AgentConnectionsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionAccountLinkPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionAddressPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionApprovalAnswerPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionApprovalsGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionApprovalsGetResponseDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionGetResponseDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionImapPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionLevelPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionOAuthCallbackGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionOAuthStartPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionOAuthStartPostResponseDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionReleasePostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionsGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionsGetResponseDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionSettingsPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionSlackPostRequestDto;
use DDD\Presentation\Base\Dtos\RedirectResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;
use DDD\Presentation\Mcp\Client\Connections\AgentConnectionsMCPPackage;
use Throwable;

/**
 * The connection endpoints of an admin API: the account's own connections ("my connections"), the connections the
 * agent may use for it, the system connections, the connect flows (OAuth with its callback, IMAP/SMTP, the Slack bot),
 * levels, name, visibility and boundaries, releases, channels and account links of a system connection, the approval
 * list. An application mounts them with one controller that sets the prefix, the tag and the gates:
 *
 *     #[Route('/agent-connections')]
 *     class AdminAgentConnectionsController extends AdminController
 *     {
 *         use AdminAgentConnectionsTrait;
 *         protected function throwForbiddenUnlessConnectionsAccessAllowed(): void { … }
 *         protected function mayManageSystemConnections(Account $account): bool { … }
 *         protected function findSystemConnectionsAgentSpaceId(Account $account): ?int { … }
 *     }
 *
 * The OAuth callback (`GET /oauth/{provider}/callback`) is reached by the browser coming back from the provider without
 * the API's session: it must be public in the application's firewall; the signed state of the start proves the row
 * and the account. While AgentConnections is switched off every route answers 403 feature_not_available.
 */
trait AdminAgentConnectionsTrait
{
    /** @var string The HttpOnly cookie that binds an OAuth consent to the browser that started it */
    public const string OAUTH_BROWSER_NONCE_COOKIE = 'agentConnectionOAuthNonce';

    /** The connections the signed-in account sees: its own, the ones released to a space it belongs to, and (with the system right) the agent's system connections; plus the providers that can be connected here. */
    #[Get('/connections')]
    #[Summary('AgentConnections List')]
    public function listAgentConnections(AdminAgentConnectionsGetRequestDto $requestDto): AdminAgentConnectionsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnectionsService = $this->getAgentConnectionsServiceOfEndpoints();
        $responseDto = new AdminAgentConnectionsGetResponseDto();
        $seenIds = [];
        $visibleConnections = [...$agentConnectionsService->findOfStage((int)$account->id)->getElements(), ...$agentConnectionsService->findUsableConnectionsOfAccount((int)$account->id, AgentConnectionReach::acrossMemberSpaces())->getElements()];
        if ($this->mayManageSystemConnections($account)) {
            $visibleConnections = [...$visibleConnections, ...$agentConnectionsService->findOfStage(null, true)->getElements()];
        }
        foreach ($visibleConnections as $agentConnection) {
            if (isset($seenIds[$agentConnection->id])) {
                continue;
            }
            $seenIds[$agentConnection->id] = true;
            $responseDto->connections[] = AgentConnectionsMCPPackage::summarize($agentConnection, $agentConnectionsService, (int)$account->id, AgentConnectionReach::acrossMemberSpaces());
        }
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        foreach (AgentConnectionsConfiguration::current()->getEnabledProviders() as $provider) {
            $adapter = $agentConnectionAdaptersService->getAdapter($provider);
            if (!$adapter instanceof OAuthAgentConnectionAdapter || $adapter->isConfigured()) {
                $responseDto->connectableProviders[] = $provider;
            }
        }
        $responseDto->mayManageSystemConnections = $this->mayManageSystemConnections($account);
        $responseDto->shareableAgentSpaceIds = $this->findShareableAgentSpaceIdsForScreen($account);
        return $responseDto;
    }

    /** One connection with its levels, releases, channels, account links, granted scopes and latest audit rows. */
    #[Get('/connections/{connectionId}', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Details')]
    public function getAgentConnection(AdminAgentConnectionGetRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireReadableConnection($account, $requestDto->connectionId);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Sets the level of one capability of a connection (owner, or the system right for a system connection). */
    #[Post('/connections/{connectionId}/level', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Set Level')]
    public function setAgentConnectionLevel(AdminAgentConnectionLevelPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $this->getAgentConnectionsServiceOfEndpoints()->setLevel($agentConnection, $requestDto->capability, $requestDto->level, (int)$account->id);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Changes name, boundaries, default flag, pause state or visibility of a connection; every change is audited. */
    #[Post('/connections/{connectionId}/settings', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Update Settings')]
    public function updateAgentConnectionSettings(AdminAgentConnectionSettingsPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $agentConnectionsService = $this->getAgentConnectionsServiceOfEndpoints();
        $changes = [];
        $changedPropertyNames = [];
        if ($requestDto->displayName !== null) {
            $agentConnection->displayName = mb_substr(trim($requestDto->displayName), 0, 100) ?: null;
            $changes[] = 'name';
            $changedPropertyNames[] = 'displayName';
        }
        if ($requestDto->scopeBoundaries !== null) {
            $agentConnection->scopeBoundaries = $requestDto->scopeBoundaries->normalized();
            $changes[] = 'boundaries';
            $changedPropertyNames[] = 'scopeBoundaries';
        }
        if ($requestDto->isDefaultForItsCapabilities !== null) {
            $agentConnection->isDefaultForItsCapabilities = $requestDto->isDefaultForItsCapabilities;
            $changes[] = 'default';
            $changedPropertyNames[] = 'isDefaultForItsCapabilities';
        }
        if ($requestDto->paused !== null && in_array($agentConnection->status, [AgentConnection::STATUS_CONNECTED, AgentConnection::STATUS_PAUSED], true)) {
            $agentConnection->status = $requestDto->paused ? AgentConnection::STATUS_PAUSED : AgentConnection::STATUS_CONNECTED;
            $agentConnection->consecutiveErrorCount = 0;
            $changes[] = $requestDto->paused ? 'paused' : 'resumed';
            $changedPropertyNames[] = 'status';
            $changedPropertyNames[] = 'consecutiveErrorCount';
        }
        if ($changes !== []) {
            $agentConnectionsService->persistPropertiesIgnoringRights($agentConnection, ...$changedPropertyNames);
            $this->getActionsServiceOfEndpoints()->recordAdministration($agentConnection, AgentConnectionAction::OPERATION_SETTINGS, (int)$account->id, implode(', ', $changes));
        }
        if ($requestDto->visibility !== null) {
            $agentConnectionsService->setVisibility($agentConnection, $requestDto->visibility, (int)$account->id, $requestDto->targetAgentSpaceId);
        }
        return $this->buildDetail($agentConnection, $account);
    }

    /** Releases a connection to an agent space with optional per-capability ceilings and boundaries. */
    #[Post('/connections/{connectionId}/release', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Release To Space')]
    public function releaseAgentConnection(AdminAgentConnectionReleasePostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $agentConnectionRelease = $this->getAgentConnectionsServiceOfEndpoints()->releaseToAgentSpace($agentConnection, $requestDto->agentSpaceId, $requestDto->capabilityCeilings, (int)$account->id);
        if ($requestDto->scopeBoundaries !== null) {
            $agentConnectionRelease->scopeBoundaries = $requestDto->scopeBoundaries->normalized();
            DDDService::instance()->deactivateEntityRightsRestrictions();
            try {
                $agentConnectionRelease->update();
            } finally {
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
            }
        }
        return $this->buildDetail($agentConnection, $account);
    }

    /** Disconnects a connection: revokes it at the provider, deletes its secrets and its releases. */
    #[Post('/connections/{connectionId}/disconnect', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Disconnect')]
    public function disconnectAgentConnection(AdminAgentConnectionGetRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $this->getAgentConnectionsServiceOfEndpoints()->disconnect($agentConnection, (int)$account->id);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Starts the OAuth connect of a provider and returns the consent URL the browser opens. */
    #[Post('/oauth/{provider}/start')]
    #[Summary('AgentConnection OAuth Start')]
    public function startAgentConnectionOAuth(AdminAgentConnectionOAuthStartPostRequestDto $requestDto): AdminAgentConnectionOAuthStartPostResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $provider = strtoupper($requestDto->provider);
        if ($requestDto->connectionId !== null) {
            return $this->startAgentConnectionReconsent($requestDto, $account);
        }
        $ownership = AgentConnection::OWNERSHIP_ACCOUNT_GRANTED;
        $agentSpaceId = null;
        if (!$requestDto->systemConnection) {
            $this->requireOwnConnectionsRight();
        }
        if ($requestDto->systemConnection) {
            $this->requireSystemRight($account);
            $ownership = AgentConnection::OWNERSHIP_AGENT_OWNED;
            $agentSpaceId = $this->findSystemConnectionsAgentSpaceId($account);
        }
        $connectsService = $this->getConnectsServiceOfEndpoints();
        $agentConnectionOAuthStart = $connectsService->startOAuthConnect($provider, $ownership, (int)$account->id, $requestDto->capabilities, $requestDto->displayName, $requestDto->loginHint, $agentSpaceId, $requestDto->visibility, $requestDto->targetAgentSpaceId);
        return $this->buildOAuthStartResponse($agentConnectionOAuthStart, $connectsService->buildRedirectUri($provider));
    }

    /** Incremental consent of an existing connection: the levels take effect when the consent completes */
    protected function startAgentConnectionReconsent(AdminAgentConnectionOAuthStartPostRequestDto $requestDto, Account $account): AdminAgentConnectionOAuthStartPostResponseDto
    {
        $agentConnection = $this->requireManageableConnection($account, (int)$requestDto->connectionId);
        $levelRequests = AgentConnectionLevelRequests::newInstance();
        foreach ($requestDto->requestedLevels as $requestedLevel) {
            $levelRequest = $requestedLevel instanceof AgentConnectionLevelRequest
                ? $requestedLevel
                : AgentConnectionLevelRequest::of((string)(((array)$requestedLevel)['capability'] ?? ''), (string)(((array)$requestedLevel)['level'] ?? ''));
            $levelRequests->add($levelRequest);
        }
        $connectsService = $this->getConnectsServiceOfEndpoints();
        $agentConnectionOAuthStart = $connectsService->startOAuthReconsent($agentConnection, (int)$account->id, $levelRequests);
        return $this->buildOAuthStartResponse($agentConnectionOAuthStart, $connectsService->buildRedirectUri((string)$agentConnection->provider));
    }

    protected function buildOAuthStartResponse(AgentConnectionOAuthStart $agentConnectionOAuthStart, string $redirectUri): AdminAgentConnectionOAuthStartPostResponseDto
    {
        $responseDto = new AdminAgentConnectionOAuthStartPostResponseDto();
        $responseDto->connectionId = (int)$agentConnectionOAuthStart->agentConnection->id;
        $responseDto->authorizationUrl = $agentConnectionOAuthStart->authorizationUrl;
        $responseDto->redirectUri = $redirectUri;
        // binds the consent to this browser: the callback completes only with this cookie
        $responseDto->headers->setCookie(Cookie::create(static::OAUTH_BROWSER_NONCE_COOKIE, $agentConnectionOAuthStart->browserNonce)
            ->withExpires((new DateTime())->modify("+" . AgentConnectionConnectsService::STATE_VALIDITY_SECONDS . " seconds"))
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));
        return $responseDto;
    }

    /**
     * The browser returns here from the provider's consent screen; the answer is a redirect to the settings page with
     * `connected=<id>` or `connectError=<short reason>`. Public in the firewall: the signed state proves the row and
     * the browser nonce cookie proves the browser that started the connect.
     */
    #[Get('/oauth/{provider}/callback')]
    #[Summary('AgentConnection OAuth Callback')]
    public function completeAgentConnectionOAuth(AdminAgentConnectionOAuthCallbackGetRequestDto $requestDto): RedirectResponseDto
    {
        $currentRequest = DDDService::instance()->getRequestService()->getRequestStack()->getCurrentRequest();
        $browserNonce = $currentRequest?->cookies->get(static::OAUTH_BROWSER_NONCE_COOKIE);
        $redirectResponseDto = $this->buildOAuthCallbackRedirect($requestDto, is_string($browserNonce) ? $browserNonce : null);
        $redirectResponseDto->headers->clearCookie(static::OAUTH_BROWSER_NONCE_COOKIE, '/', null, true, true, Cookie::SAMESITE_LAX);
        return $redirectResponseDto;
    }

    protected function buildOAuthCallbackRedirect(AdminAgentConnectionOAuthCallbackGetRequestDto $requestDto, ?string $browserNonce): RedirectResponseDto
    {
        $agentConnectionsConfiguration = AgentConnectionsConfiguration::current();
        $settingsUrl = $agentConnectionsConfiguration->getPublicBaseUrl() . $agentConnectionsConfiguration->getSettingsPagePath();
        if (!$agentConnectionsConfiguration->isEnabled()) {
            return new RedirectResponseDto($settingsUrl . '?connectError=feature_not_available');
        }
        if ($requestDto->error !== null || $requestDto->code === null || $requestDto->state === null) {
            return new RedirectResponseDto($settingsUrl . '?connectError=' . rawurlencode(mb_substr((string)($requestDto->error ?? 'cancelled'), 0, 60)));
        }
        try {
            $agentConnection = $this->getConnectsServiceOfEndpoints()->completeOAuthConnect(strtoupper($requestDto->provider), $requestDto->state, $requestDto->code, $browserNonce);
        } catch (AgentConnectionRefusedException $refused) {
            return new RedirectResponseDto($settingsUrl . '?connectError=' . rawurlencode(mb_substr($refused->getMessage(), 0, 200)));
        }
        return new RedirectResponseDto($settingsUrl . '?connected=' . $agentConnection->id);
    }

    /** Connects an IMAP/SMTP mailbox after a verified login; the password is stored encrypted. */
    #[Post('/connections/imap')]
    #[Summary('AgentConnection Connect IMAP')]
    public function connectAgentConnectionImap(AdminAgentConnectionImapPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $ownership = AgentConnection::OWNERSHIP_ACCOUNT_GRANTED;
        $agentSpaceId = null;
        if (!$requestDto->systemConnection) {
            $this->requireOwnConnectionsRight();
        }
        if ($requestDto->systemConnection) {
            $this->requireSystemRight($account);
            $ownership = AgentConnection::OWNERSHIP_AGENT_OWNED;
            $agentSpaceId = $this->findSystemConnectionsAgentSpaceId($account);
        }
        $agentConnection = $this->getConnectsServiceOfEndpoints()->connectImapMailbox($ownership, (int)$account->id, AgentConnectionImapCredentials::fromValues([
            'imapHost' => $requestDto->imapHost, 'imapPort' => $requestDto->imapPort, 'smtpHost' => $requestDto->smtpHost,
            'smtpPort' => $requestDto->smtpPort, 'username' => $requestDto->username, 'password' => $requestDto->password,
            'fromAddress' => mb_strtolower(trim($requestDto->fromAddress)), 'draftsFolder' => $requestDto->draftsFolder ?? 'Drafts',
        ]), $requestDto->displayName, $agentSpaceId);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Connects the agent's Slack bot as a system connection. */
    #[Post('/system/slack')]
    #[Summary('AgentConnection Connect Slack Bot')]
    public function connectAgentConnectionSlackBot(AdminAgentConnectionSlackPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $this->requireSystemRight($account);
        $agentSpaceId = $this->findSystemConnectionsAgentSpaceId($account);
        if ($agentSpaceId === null) {
            throw new BadRequestException('No space owns the system connections here.');
        }
        $agentConnection = $this->getConnectsServiceOfEndpoints()->connectSlackBot((int)$account->id, $requestDto->displayName, $agentSpaceId);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Adds or changes a channel or address of a system connection (behaviour, posting use, audience, right). */
    #[Post('/connections/{connectionId}/addresses', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Upsert Address')]
    public function upsertAgentConnectionAddress(AdminAgentConnectionAddressPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $this->getConnectsServiceOfEndpoints()->upsertAddress($agentConnection, $requestDto->externalAddress, $requestDto->displayName, $requestDto->behaviour, $requestDto->postingUse, $requestDto->audience, $requestDto->requiredAccountRight, $requestDto->purposeName);
        unset($agentConnection->addresses);
        return $this->buildDetail($agentConnection, $account);
    }

    /** Links an external chat user to an account of this application. */
    #[Post('/connections/{connectionId}/accountLinks', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Link Account')]
    public function linkAgentConnectionAccount(AdminAgentConnectionAccountLinkPostRequestDto $requestDto): AdminAgentConnectionGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        if (!$agentConnection->isAgentOwned()) {
            throw new BadRequestException('Account links belong to a system connection.');
        }
        // the linked account drives conversations through the chat: only a member of the connection's space, and
        // never an account the host keeps out of chat links (e.g. a higher-privileged one)
        if ($agentConnection->agentSpaceId === null
            || !$this->getAgentConnectionsServiceOfEndpoints()->isAccountMemberOfAgentSpace($requestDto->accountId, (int)$agentConnection->agentSpaceId)
            || !$this->mayLinkChatUserToAccount($account, $requestDto->accountId)) {
            throw new ForbiddenException('This account cannot be linked to the connection.');
        }
        /** @var AgentConnectionInboundService $agentConnectionInboundService */
        $agentConnectionInboundService = DDDService::instance()->getService(AgentConnectionInboundService::class);
        $agentConnectionInboundService->linkAccount($agentConnection, $requestDto->externalUserId, $requestDto->accountId, AgentConnectionAccountLink::VERIFIED_BY_ADMIN, null);
        return $this->buildDetail($agentConnection, $account);
    }

    /** The approval requests the signed-in account may answer. */
    #[Get('/approvals')]
    #[Summary('AgentConnection Approvals List')]
    public function listAgentConnectionApprovals(AdminAgentConnectionApprovalsGetRequestDto $requestDto): AdminAgentConnectionApprovalsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $responseDto = new AdminAgentConnectionApprovalsGetResponseDto();
        $responseDto->approvalRequests = $this->getApprovalRequestsServiceOfEndpoints()->findAnswerableByAccount((int)$account->id, $requestDto->includeAnswered);
        return $responseDto;
    }

    /** Answers an approval request; an approval runs the stored step after a content check. */
    #[Post('/approvals/{approvalRequestId}/answer', requirements: ['approvalRequestId' => '\d+'])]
    #[Summary('AgentConnection Approval Answer')]
    public function answerAgentConnectionApproval(AdminAgentConnectionApprovalAnswerPostRequestDto $requestDto): AdminAgentConnectionApprovalsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $approvalRequestsService = $this->getApprovalRequestsServiceOfEndpoints();
        $agentConnectionApprovalRequest = $approvalRequestsService->findIgnoringRights($requestDto->approvalRequestId);
        if (!$agentConnectionApprovalRequest instanceof AgentConnectionApprovalRequest) {
            throw new NotFoundException('approval_not_found');
        }
        $approvalRequestsService->answer($agentConnectionApprovalRequest, $requestDto->option, (int)$account->id, AgentConnectionApprovalRequest::ANSWERED_VIA_ADMIN);
        $responseDto = new AdminAgentConnectionApprovalsGetResponseDto();
        $responseDto->approvalRequests = $approvalRequestsService->findAnswerableByAccount((int)$account->id, false);
        return $responseDto;
    }

    protected function buildDetail(AgentConnection $agentConnection, Account $account): AdminAgentConnectionGetResponseDto
    {
        $agentConnectionsService = $this->getAgentConnectionsServiceOfEndpoints();
        $responseDto = new AdminAgentConnectionGetResponseDto();
        $responseDto->connection = AgentConnectionsMCPPackage::summarize($agentConnection, $agentConnectionsService, (int)$account->id, AgentConnectionReach::acrossMemberSpaces());
        $responseDto->permissions = AgentConnectionPermissions::newInstance();
        foreach ($agentConnectionsService->findPermissionsOfConnection($agentConnection)->getElements() as $agentConnectionPermission) {
            $responseDto->permissions->add($agentConnectionPermission);
        }
        $responseDto->releases = AgentConnectionReleases::newInstance();
        foreach ($agentConnectionsService->findActiveReleasesOfConnection($agentConnection)->getElements() as $agentConnectionRelease) {
            $responseDto->releases->add($agentConnectionRelease);
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $responseDto->addresses = $this->findRowsOfConnection(AgentConnectionAddresses::getRepoClassInstance(), $agentConnection) ?? AgentConnectionAddresses::newInstance();
            $responseDto->accountLinks = $agentConnection->isAgentOwned() ? ($this->findRowsOfConnection(AgentConnectionAccountLinks::getRepoClassInstance(), $agentConnection) ?? AgentConnectionAccountLinks::newInstance()) : null;
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $responseDto->actions = $this->getActionsServiceOfEndpoints()->findLatestOfConnection((int)$agentConnection->id, 100);
        $responseDto->grantedScopes = $agentConnection->getGrantedScopeList();
        foreach ($responseDto->permissions->getElements() as $agentConnectionPermission) {
            $capability = (string)$agentConnectionPermission->capability;
            $responseDto->grantedLevels[] = AgentConnectionLevelRequest::of($capability, $agentConnectionsService->findHighestGrantedLevel($agentConnection, $capability));
        }
        return $responseDto;
    }

    protected function findRowsOfConnection(DBEntitySet $repository, AgentConnection $agentConnection): mixed
    {
        $alias = $repository::getBaseModelAlias();
        $queryBuilder = $repository::createQueryBuilder(true);
        $queryBuilder->andWhere("$alias.agentConnectionId = :agentConnectionId")->setParameter('agentConnectionId', (int)$agentConnection->id);
        return $repository->find($queryBuilder, false);
    }

    /** @throws ForbiddenException */
    protected function requireConnectionsAccount(): Account
    {
        if (!AgentConnectionsConfiguration::current()->isEnabled()) {
            throw new ForbiddenException('feature_not_available');
        }
        $this->throwForbiddenUnlessConnectionsAccessAllowed();
        $account = AuthService::instance()->getAccount();
        if (!$account instanceof Account || !isset($account->id)) {
            throw new ForbiddenException('Sign in first.');
        }
        return $account;
    }

    /** The owner, an account that reaches it through a release, or a manager of system connections may read it */
    protected function requireReadableConnection(Account $account, int $agentConnectionId): AgentConnection
    {
        $agentConnectionsService = $this->getAgentConnectionsServiceOfEndpoints();
        $agentConnection = $agentConnectionsService->findIgnoringRights($agentConnectionId);
        if (!$agentConnection instanceof AgentConnection
            || !$agentConnection->belongsToStage(AgentConnectionsConfiguration::current()->getCurrentStage())
            || ($agentConnectionsService->getAccessOfAccount($agentConnection, (int)$account->id, AgentConnectionReach::acrossMemberSpaces()) === null
                && !($agentConnection->isAgentOwned() && $this->mayManageSystemConnections($account)))) {
            throw new NotFoundException('connection_not_found');
        }
        return $agentConnection;
    }

    /** The owner of a mode-1 connection (with the right to manage own connections), or a manager of system connections */
    protected function requireManageableConnection(Account $account, int $agentConnectionId): AgentConnection
    {
        $agentConnection = $this->requireReadableConnection($account, $agentConnectionId);
        if (!$agentConnection->isAgentOwned()) {
            $this->requireOwnConnectionsRight();
        }
        $isOwner = !$agentConnection->isAgentOwned() && (int)$agentConnection->accountId === (int)$account->id;
        if (!$isOwner && !($agentConnection->isAgentOwned() && $this->mayManageSystemConnections($account))) {
            throw new ForbiddenException('Only the owner changes this connection.');
        }
        return $agentConnection;
    }

    /** The right to connect and manage the account's own connections ({@see AgentConnectionsAccountRights}) */
    protected function requireOwnConnectionsRight(): void
    {
        if (!$this->holdsConnectionsRight(AgentConnectionsAccountRights::MANAGE_OWN_AGENT_CONNECTIONS)) {
            throw new ForbiddenException('Managing own connections needs the right ' . AgentConnectionsAccountRights::MANAGE_OWN_AGENT_CONNECTIONS . '.');
        }
    }

    /** Whether the signed-in account holds a right of AgentConnections (the application's rights object or mapping) */
    protected function holdsConnectionsRight(string $rightName): bool
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        $accountRights = $aIConversationEntitlementsService->findComposedAccountRightsOfCurrentAccount();
        return $accountRights !== null && $accountRights->getMissingRightNames([$rightName]) === [];
    }

    protected function requireSystemRight(Account $account): void
    {
        if (!$this->mayManageSystemConnections($account)) {
            throw new ForbiddenException('Managing the agent\'s system connections needs the system right.');
        }
    }

    /** @return int[] */
    protected function findShareableAgentSpaceIdsForScreen(Account $account): array
    {
        return [];
    }

    /**
     * HOST HOOK: whether `$linkingAccount` may link a chat user to `$targetAccountId` (beyond membership of the
     * connection's space). Default: only to itself; a host allows more (e.g. accounts of equal or lower privilege).
     */
    protected function mayLinkChatUserToAccount(Account $linkingAccount, int $targetAccountId): bool
    {
        return (int)$linkingAccount->id === $targetAccountId;
    }

    /** HOST HOOK: who may use these endpoints at all */
    protected function throwForbiddenUnlessConnectionsAccessAllowed(): void
    {
    }

    /** HOST HOOK: whether the account manages the agent's system connections; default the right `manageSystemAgentConnections` */
    protected function mayManageSystemConnections(Account $account): bool
    {
        return $this->holdsConnectionsRight(AgentConnectionsAccountRights::MANAGE_SYSTEM_AGENT_CONNECTIONS);
    }

    /** HOST HOOK: the agent space that owns the system connections this account installs */
    protected function findSystemConnectionsAgentSpaceId(Account $account): ?int
    {
        return null;
    }

    protected function getAgentConnectionsServiceOfEndpoints(): AgentConnectionsService
    {
        /** @var AgentConnectionsService $agentConnectionsService */
        $agentConnectionsService = DDDService::instance()->getService(AgentConnectionsService::class);
        return $agentConnectionsService;
    }

    protected function getConnectsServiceOfEndpoints(): AgentConnectionConnectsService
    {
        /** @var AgentConnectionConnectsService $agentConnectionConnectsService */
        $agentConnectionConnectsService = DDDService::instance()->getService(AgentConnectionConnectsService::class);
        return $agentConnectionConnectsService;
    }

    protected function getActionsServiceOfEndpoints(): AgentConnectionActionsService
    {
        /** @var AgentConnectionActionsService $agentConnectionActionsService */
        $agentConnectionActionsService = DDDService::instance()->getService(AgentConnectionActionsService::class);
        return $agentConnectionActionsService;
    }

    protected function getApprovalRequestsServiceOfEndpoints(): AgentConnectionApprovalRequestsService
    {
        /** @var AgentConnectionApprovalRequestsService $agentConnectionApprovalRequestsService */
        $agentConnectionApprovalRequestsService = DDDService::instance()->getService(AgentConnectionApprovalRequestsService::class);
        return $agentConnectionApprovalRequestsService;
    }
}
