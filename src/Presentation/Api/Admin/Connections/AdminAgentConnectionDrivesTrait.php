<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Connections;

use DDD\Domain\AI\Entities\Connections\AgentConnection;
use DDD\Domain\AI\Entities\Connections\AgentConnectionDriveRoot;
use DDD\Domain\AI\Exceptions\Connections\AgentConnectionRefusedException;
use DDD\Domain\AI\Services\Connections\Adapters\DriveAgentConnectionAdapter;
use DDD\Domain\AI\Services\Connections\AgentConnectionAdaptersService;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveReleasesService;
use DDD\Domain\AI\Services\Connections\AgentConnectionDriveRootsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveItemsGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveRootItemPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveRootPostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveRootRevokePostRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveRootsGetRequestDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionDriveRootsGetResponseDto;
use DDD\Presentation\Api\Admin\Connections\Dtos\AdminAgentConnectionReleaseToAgentPostRequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The roots of drive connections and the ways items become roots: pick roots on an own connection (or a Picker-only
 * one), release a folder or shared drive to the agent's own drive identity with one's own token, take a release back,
 * assign items shared with the agent's identity (they arrive unassigned). Mounted together with
 * {@see AdminAgentConnectionsTrait} in the same controller: it uses that trait's gates (readable, manageable, the
 * system right).
 */
trait AdminAgentConnectionDrivesTrait
{
    /** The roots of a drive connection with the way each came (unassigned shares of the agent's identity included) */
    #[Get('/connections/{connectionId}/driveRoots', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Drive Roots')]
    public function listAgentConnectionDriveRoots(AdminAgentConnectionDriveRootsGetRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireReadableConnection($account, $requestDto->connectionId);
        return $this->buildDriveRootsResponse($agentConnection, null);
    }

    /** The items of a folder of a connection the account manages, with its own token: to pick roots (never the agent's view) */
    #[Get('/connections/{connectionId}/driveItems', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Drive Items To Pick')]
    public function listAgentConnectionDriveItems(AdminAgentConnectionDriveItemsGetRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $responseDto = $this->buildDriveRootsResponse($agentConnection, null);
        $responseDto->driveItems = $this->requireConnectionDriveAdapter($agentConnection)->listFolderChildren($requestDto->folderId ?? 'root', $requestDto->pageToken, 200);
        return $responseDto;
    }

    /** Adds a root to a connection the account manages (a folder, shared drive or file; never the whole drive) */
    #[Post('/connections/{connectionId}/driveRoots', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Add Drive Root')]
    public function addAgentConnectionDriveRoot(AdminAgentConnectionDriveRootPostRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        if (!in_array($requestDto->releaseWay, [AgentConnectionDriveRoot::WAY_PERSON_ROOT, AgentConnectionDriveRoot::WAY_PICKER, AgentConnectionDriveRoot::WAY_OWN_IDENTITY], true)) {
            throw new BadRequestException('releaseWay: PERSON_ROOT, PICKER or OWN_IDENTITY.');
        }
        if ($requestDto->releaseWay === AgentConnectionDriveRoot::WAY_OWN_IDENTITY) {
            $this->requireSystemRight($account);
        }
        $this->getDriveReleasesServiceOfEndpoints()->addRoot($agentConnection, $requestDto->itemId, (int)$account->id, $requestDto->releaseWay);
        return $this->buildDriveRootsResponse($agentConnection, 'Root added.');
    }

    /** Removes a root (the item itself is never touched) */
    #[Post('/connections/{connectionId}/driveRoots/remove', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Remove Drive Root')]
    public function removeAgentConnectionDriveRoot(AdminAgentConnectionDriveRootItemPostRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $this->getDriveReleasesServiceOfEndpoints()->removeRoot($agentConnection, $requestDto->itemId, (int)$account->id);
        return $this->buildDriveRootsResponse($agentConnection, 'Root removed.');
    }

    /** Assigns an unassigned share of the agent's identity as a root (the system right) */
    #[Post('/connections/{connectionId}/driveRoots/assign', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Assign Drive Root')]
    public function assignAgentConnectionDriveRoot(AdminAgentConnectionDriveRootItemPostRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $this->requireSystemRight($account);
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $this->getDriveReleasesServiceOfEndpoints()->assignRoot($agentConnection, $requestDto->itemId, (int)$account->id);
        return $this->buildDriveRootsResponse($agentConnection, 'Root assigned.');
    }

    /** Looks for new shares to the agent's identity (they arrive unassigned; the system right) */
    #[Post('/connections/{connectionId}/driveRoots/discover', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Discover Shared Drive Items')]
    public function discoverAgentConnectionDriveItems(AdminAgentConnectionDriveRootsGetRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $this->requireSystemRight($account);
        $agentConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $newCount = $this->getDriveReleasesServiceOfEndpoints()->discoverUnassignedItems($agentConnection);
        return $this->buildDriveRootsResponse($agentConnection, $newCount . ' new shared items (unassigned).');
    }

    /**
     * Releases a folder or shared drive of the account's own drive connection to the agent's own drive identity: the
     * backend shares it with the account's token at the role, then adds it as a root of the agent's connection. The
     * account's token is not needed afterwards.
     */
    #[Post('/connections/{connectionId}/releaseToAgent', requirements: ['connectionId' => '\d+'])]
    #[Summary('AgentConnection Release Drive Item To Agent')]
    public function releaseAgentConnectionDriveItemToAgent(AdminAgentConnectionReleaseToAgentPostRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        $sourceConnection = $this->requireManageableConnection($account, $requestDto->connectionId);
        $agentIdentityConnection = $this->getAgentConnectionsServiceOfEndpoints()->findIgnoringRights($requestDto->agentConnectionId);
        if (!$agentIdentityConnection instanceof AgentConnection) {
            throw new NotFoundException('connection_not_found');
        }
        $this->getDriveReleasesServiceOfEndpoints()->releaseToAgent($sourceConnection, $requestDto->itemId, $agentIdentityConnection, $requestDto->role, (int)$account->id);
        return $this->buildDriveRootsResponse($agentIdentityConnection, 'Released to the agent.');
    }

    /** Takes a release to the agent back (the person who released it, or the system right) */
    #[Post('/driveRoots/{driveRootId}/revoke', requirements: ['driveRootId' => '\d+'])]
    #[Summary('AgentConnection Revoke Drive Release')]
    public function revokeAgentConnectionDriveRelease(AdminAgentConnectionDriveRootRevokePostRequestDto $requestDto): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $account = $this->requireConnectionsAccount();
        /** @var AgentConnectionDriveRootsService $agentConnectionDriveRootsService */
        $agentConnectionDriveRootsService = DDDService::instance()->getService(AgentConnectionDriveRootsService::class);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentConnectionDriveRoot = $agentConnectionDriveRootsService->find($requestDto->driveRootId, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        if (!$agentConnectionDriveRoot instanceof AgentConnectionDriveRoot) {
            throw new NotFoundException('drive_root_not_found');
        }
        $outcome = $this->getDriveReleasesServiceOfEndpoints()->revokeReleaseToAgent($agentConnectionDriveRoot, (int)$account->id, $this->mayManageSystemConnections($account));
        $agentConnection = $this->getAgentConnectionsServiceOfEndpoints()->findIgnoringRights((int)$agentConnectionDriveRoot->agentConnectionId);
        if (!$agentConnection instanceof AgentConnection) {
            throw new NotFoundException('connection_not_found');
        }
        return $this->buildDriveRootsResponse($agentConnection, ucfirst($outcome) . '.');
    }

    protected function buildDriveRootsResponse(AgentConnection $agentConnection, ?string $note): AdminAgentConnectionDriveRootsGetResponseDto
    {
        $responseDto = new AdminAgentConnectionDriveRootsGetResponseDto();
        $responseDto->driveRoots = $this->getDriveReleasesServiceOfEndpoints()->findRootsOfConnection($agentConnection);
        $responseDto->note = $note;
        return $responseDto;
    }

    /** @throws AgentConnectionRefusedException */
    protected function requireConnectionDriveAdapter(AgentConnection $agentConnection): DriveAgentConnectionAdapter
    {
        /** @var AgentConnectionAdaptersService $agentConnectionAdaptersService */
        $agentConnectionAdaptersService = DDDService::instance()->getService(AgentConnectionAdaptersService::class);
        $adapter = $agentConnectionAdaptersService->getAdapterOfConnection($agentConnection);
        if (!$adapter instanceof DriveAgentConnectionAdapter) {
            throw new AgentConnectionRefusedException("The connection \"{$agentConnection->getLabel()}\" is no drive.");
        }
        return $adapter;
    }

    protected function getDriveReleasesServiceOfEndpoints(): AgentConnectionDriveReleasesService
    {
        /** @var AgentConnectionDriveReleasesService $agentConnectionDriveReleasesService */
        $agentConnectionDriveReleasesService = DDDService::instance()->getService(AgentConnectionDriveReleasesService::class);
        return $agentConnectionDriveReleasesService;
    }
}
